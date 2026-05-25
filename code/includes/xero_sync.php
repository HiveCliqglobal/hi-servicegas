<?php
/**
 * xero_sync.php — bidirectional Xero ↔ Hi-Service sync orchestrator.
 *
 * Pull:
 *   syncItems()      → Xero Items → products table (price, name, SKU, stock)
 *                       New items default is_active=0 (admin must approve)
 *                       Existing items: price/name/stock updated, approval preserved
 *
 * Push (Phase 2 — order paid):
 *   pushInvoice($orderId)  → Xero Invoice with line items + contact
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/xero.php';
require_once __DIR__ . '/customer_repo.php';
require_once __DIR__ . '/order_repo.php';

final class XeroSync
{
    /**
     * Pull all items from Xero and upsert into local `products` table.
     *
     * Behaviour:
     *   - Match by xero_item_id (preferred) then by code (SKU) as fallback
     *   - New items inserted with is_active=0 (admin approves before customers see them)
     *   - Existing items: price, name, in_stock_qty, is_tracked updated; is_active preserved
     *   - Items removed from Xero are FLAGGED (not deleted) — admin decides
     *
     * @return array  ['ok'=>bool, 'pulled'=>int, 'created'=>int, 'updated'=>int, 'orphans'=>int, 'errors'=>[]]
     */
    public static function syncItems(): array
    {
        $stats = ['ok' => true, 'pulled' => 0, 'created' => 0, 'updated' => 0, 'orphans' => 0, 'errors' => []];

        try {
            if (!Xero::isConnected()) {
                throw new RuntimeException('Xero is not connected. Visit /admin/connections.php');
            }
            $items = Xero::listItems();
        } catch (Throwable $e) {
            $stats['ok'] = false;
            $stats['errors'][] = $e->getMessage();
            log_to_file('xero-sync', 'listItems failed', ['err' => $e->getMessage()]);
            return $stats;
        }

        $stats['pulled'] = count($items);
        $seenItemIds    = [];

        foreach ($items as $it) {
            try {
                $xeroId   = (string) ($it['ItemID'] ?? '');
                $code     = (string) ($it['Code']   ?? '');
                $name     = (string) ($it['Name']   ?? '');
                $desc     = (string) ($it['Description'] ?? '');
                $tracked  = (bool)   ($it['IsTrackedAsInventory'] ?? false);
                $sold     = (bool)   ($it['IsSold'] ?? true);
                $price    = (float)  ($it['SalesDetails']['UnitPrice'] ?? 0);
                $stockQty = (int)    ($it['QuantityOnHand'] ?? 0);

                // Skip items not for sale (e.g. purchase-only items)
                if (!$sold) continue;
                if ($xeroId === '' || $code === '') continue;
                $seenItemIds[] = $xeroId;

                // Match priority: xero_item_id → code
                $existing = self::findExistingProduct($xeroId, $code);

                if ($existing) {
                    // UPDATE — preserve admin's is_active state
                    db()->prepare(
                        "UPDATE products
                            SET xero_item_id = :xid, code = :code, name = :n,
                                description = :d, price = :p,
                                in_stock_qty = :stock, is_tracked = :tr,
                                updated_at = NOW()
                          WHERE id = :id"
                    )->execute([
                        ':xid'   => $xeroId,
                        ':code'  => $code,
                        ':n'     => $name,
                        ':d'     => $desc,
                        ':p'     => $price,
                        ':stock' => $stockQty,
                        ':tr'    => $tracked ? 1 : 0,
                        ':id'    => $existing['id'],
                    ]);
                    $stats['updated']++;
                } else {
                    // INSERT — default is_active=0, admin must approve before customers see it
                    db()->prepare(
                        "INSERT INTO products
                          (xero_item_id, code, name, description, price, in_stock_qty, is_tracked, is_active, category, sort_order)
                          VALUES (:xid, :code, :n, :d, :p, :stock, :tr, 0, 'gas', 100)"
                    )->execute([
                        ':xid'   => $xeroId,
                        ':code'  => $code,
                        ':n'     => $name,
                        ':d'     => $desc,
                        ':p'     => $price,
                        ':stock' => $stockQty,
                        ':tr'    => $tracked ? 1 : 0,
                    ]);
                    $stats['created']++;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = "{$code} / {$name}: " . $e->getMessage();
                log_to_file('xero-sync', 'item upsert failed', ['item' => $it, 'err' => $e->getMessage()]);
            }
        }

        // Count orphans (products previously synced from Xero but no longer present)
        if (!empty($seenItemIds)) {
            $placeholders = implode(',', array_fill(0, count($seenItemIds), '?'));
            $stmt = db()->prepare(
                "SELECT COUNT(*) AS c FROM products
                  WHERE xero_item_id IS NOT NULL AND xero_item_id != ''
                    AND xero_item_id NOT IN ({$placeholders})"
            );
            $stmt->execute($seenItemIds);
            $stats['orphans'] = (int) ($stmt->fetch()['c'] ?? 0);
        }

        log_event('admin.xero.items_synced', null, null, $stats);
        return $stats;
    }

    /** Push a paid Hi-Service order to Xero as an AUTHORISED invoice. Returns Xero invoice metadata. */
    public static function pushInvoice(int $orderId): array
    {
        $order = OrderRepo::findById($orderId);
        if (!$order) throw new RuntimeException("Order #{$orderId} not found");

        $cust = CustomerRepo::findById((int) $order['customer_id']);
        if (!$cust) throw new RuntimeException("Customer for order #{$orderId} not found");

        $contactId = Xero::findOrCreateContact($cust);
        if ($contactId === '') throw new RuntimeException("Could not resolve Xero ContactID for customer #{$cust['id']}");

        $lines = OrderRepo::linesFor($orderId);
        $lineItems = [];
        foreach ($lines as $l) {
            // Try to use the product's Xero code if synced; otherwise use stored product_name as description only.
            $code = '';
            if (!empty($l['product_id'])) {
                $p = db()->prepare("SELECT code FROM products WHERE id = ?");
                $p->execute([(int) $l['product_id']]);
                $r = $p->fetch();
                if ($r) $code = (string) $r['code'];
            }
            $lineItems[] = [
                'code'        => $code,
                'description' => (string) $l['product_name'],
                'qty'         => (int)   $l['qty'],
                'unit_amount' => (float) $l['unit_price'],
            ];
        }

        $resp = Xero::createInvoice([
            'contact_id' => $contactId,
            'reference'  => (string) $order['order_reference'],
            'line_items' => $lineItems,
        ]);

        // Persist Xero invoice refs on the order + contact id on the customer
        db()->prepare("UPDATE orders SET xero_invoice_id = :i, xero_invoice_number = :n WHERE id = :id")
            ->execute([
                ':i'  => $resp['invoice_id'],
                ':n'  => $resp['invoice_number'],
                ':id' => $orderId,
            ]);
        if (empty($cust['xero_contact_id']) && $contactId !== '') {
            db()->prepare("UPDATE customers SET xero_contact_id = :c WHERE id = :id")
                ->execute([':c' => $contactId, ':id' => $cust['id']]);
        }

        log_event('admin.xero.invoice_created', 'order', (string) $orderId, $resp);
        return $resp;
    }

    private static function findExistingProduct(string $xeroItemId, string $code): ?array
    {
        if ($xeroItemId !== '') {
            $stmt = db()->prepare("SELECT * FROM products WHERE xero_item_id = ? LIMIT 1");
            $stmt->execute([$xeroItemId]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }
        if ($code !== '') {
            $stmt = db()->prepare("SELECT * FROM products WHERE code = ? LIMIT 1");
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }
        return null;
    }
}

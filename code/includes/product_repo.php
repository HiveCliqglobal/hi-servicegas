<?php
/**
 * product_repo.php — product catalogue (synced from Xero).
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ProductRepo
{
    public static function listActive(): array
    {
        return db()->query(
            'SELECT * FROM products WHERE is_active = 1 ORDER BY sort_order, name'
        )->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = db()->prepare('SELECT * FROM products WHERE code = :c LIMIT 1');
        $stmt->execute([':c' => $code]);
        return $stmt->fetch() ?: null;
    }

    /**
     * SHARED stock-validation helper — single source of truth.
     *
     * Called by BOTH ordering channels:
     *   - WhatsApp:  Conversation::actCollectOrderDetails (before cart commit)
     *   - Web shop:  shop/browse.php (after qty form POST)
     *
     * HARD RULE (locked 2026-05-26 — do not relax without explicit approval):
     *   - Only TRACKED products (is_tracked=1) are gated on stock
     *   - Untracked items (services, deposits, refills, levies, surcharges)
     *     are ALWAYS allowed — they have no physical inventory
     *   - The customer-facing CATALOGUE filter stays pure is_active=1
     *     (admin curation is canonical, stock never auto-hides)
     *   - This stock gate fires only at ORDER TIME — preventing the
     *     "your order is locked but unfulfillable" trap
     *
     * Returns shortfalls in the format both channels expect. Empty array =
     * everything in stock, OK to proceed.
     *
     * @param array $lines  Each: ['product_id'=>int, 'qty'=>int, 'product_name'?=>string]
     * @return array        [['product_id','product_name','requested','available'], ...]
     */
    public static function checkCartStock(array $lines): array
    {
        $issues = [];
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $qty = (int) ($line['qty']        ?? $line['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;

            $stmt = db()->prepare('SELECT name, in_stock_qty, is_tracked FROM products WHERE id = ? LIMIT 1');
            $stmt->execute([$pid]);
            $p = $stmt->fetch();
            if (!$p) continue;
            if ((int) $p['is_tracked'] !== 1) continue;       // untracked → unlimited

            $available = (int) $p['in_stock_qty'];
            if ($available < $qty) {
                $issues[] = [
                    'product_id'   => $pid,
                    'product_name' => (string) ($line['product_name'] ?? $p['name']),
                    'requested'    => $qty,
                    'available'    => $available,
                ];
            }
        }
        return $issues;
    }

    /**
     * Build a WhatsApp-style lettered catalogue, sorted by kg then name.
     * Returns [ ['letter'=>'A', 'product'=>[...]], ... ]
     */
    public static function letteredCatalogue(): array
    {
        $items = self::listActive();
        // Sort by kg (parsed from name)
        usort($items, function ($a, $b) {
            $ka = self::extractKg($a['name']);
            $kb = self::extractKg($b['name']);
            return $ka === $kb ? strcmp($a['name'], $b['name']) : $ka <=> $kb;
        });
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $out = [];
        foreach ($items as $i => $p) {
            $out[] = ['letter' => $alphabet[$i % 26], 'product' => $p];
        }
        return $out;
    }

    private static function extractKg(string $name): int
    {
        return preg_match('/(\d+)\s*kg/i', $name, $m) ? (int) $m[1] : 9999;
    }

    /**
     * Resolve order tokens (["B2","D1"]) into line items using the lettered catalogue.
     * Returns ['lines' => [...], 'total' => float, 'errors' => []]
     */
    public static function resolveTokens(array $tokens): array
    {
        $catalogue = self::letteredCatalogue();
        $byLetter = [];
        foreach ($catalogue as $row) $byLetter[$row['letter']] = $row['product'];

        $lines = [];
        $errors = [];
        $total = 0.0;

        // Accept BOTH shapes the upstream parser may pass us:
        //   - Associative ['C' => 2, 'B' => 1]  (current actCollectOrderDetails)
        //   - Flat        ['C2', 'B1']          (older callers / direct uses)
        // strict_types is on in this file, so the previous int-into-strtoupper path
        // raised TypeError and the webhook's catch-all returned the generic "Sorry,
        // something went wrong" to the customer. This loop handles both shapes safely.
        $normalised = [];
        foreach ($tokens as $key => $val) {
            if (is_string($key) && !is_string($val)) {
                // associative shape: key=letter, val=qty
                $normalised[] = [strtoupper((string) $key), (int) $val];
            } else {
                // flat shape: val is a token like "C2"
                if (!preg_match('/^([A-Z])(\d+)$/', strtoupper((string) $val), $m)) {
                    $errors[] = "Bad token: " . (string) $val;
                    continue;
                }
                $normalised[] = [$m[1], (int) $m[2]];
            }
        }

        foreach ($normalised as [$letter, $qty]) {
            if ($qty < 1) {
                $errors[] = "Bad quantity for {$letter}";
                continue;
            }
            if (!isset($byLetter[$letter])) {
                $errors[] = "Unknown product letter: {$letter}";
                continue;
            }
            $p = $byLetter[$letter];
            $lineTotal = $qty * (float) $p['price'];
            $lines[] = [
                'product_id'   => (int) $p['id'],
                'product_name' => $p['name'],
                'product_code' => $p['code'],
                'qty'          => $qty,
                'unit_price'   => (float) $p['price'],
                'line_total'   => $lineTotal,
            ];
            $total += $lineTotal;
        }
        return ['lines' => $lines, 'total' => $total, 'errors' => $errors];
    }
}

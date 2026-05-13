<?php
/**
 * order_repo.php — cart + order lifecycle.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

final class OrderRepo
{
    /**
     * Create a new order row in 'cart' state.
     * Honors the hs_demo cookie if present (web flow only).
     *
     * @return int new order_id
     */
    public static function createCart(int $customerId, string $channel, bool $isDemo = false): int
    {
        $ref = gen_order_ref();
        if (!$isDemo && !empty($_COOKIE['hs_demo'])) $isDemo = true;
        $stmt = db()->prepare(
            'INSERT INTO orders (order_reference, customer_id, channel, is_demo, status, total_amount)
             VALUES (:ref, :cid, :ch, :d, "cart", 0)'
        );
        $stmt->execute([':ref' => $ref, ':cid' => $customerId, ':ch' => $channel, ':d' => $isDemo ? 1 : 0]);
        return (int) db()->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByRef(string $ref): ?array
    {
        $stmt = db()->prepare('SELECT * FROM orders WHERE order_reference = :r LIMIT 1');
        $stmt->execute([':r' => $ref]);
        return $stmt->fetch() ?: null;
    }

    public static function linesFor(int $orderId): array
    {
        $stmt = db()->prepare('SELECT * FROM order_lines WHERE order_id = :id ORDER BY id');
        $stmt->execute([':id' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Replace all lines on an order, recompute total.
     *
     * @param array $lines  Each: ['product_id','product_name','qty','unit_price','line_total']
     */
    public static function replaceLines(int $orderId, array $lines): float
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM order_lines WHERE order_id = :id')->execute([':id' => $orderId]);
            $ins = $pdo->prepare(
                'INSERT INTO order_lines (order_id, product_id, product_name, qty, unit_price, line_total)
                 VALUES (:o, :pid, :n, :q, :p, :lt)'
            );
            $total = 0.0;
            foreach ($lines as $l) {
                $ins->execute([
                    ':o'   => $orderId,
                    ':pid' => (int) $l['product_id'],
                    ':n'   => (string) $l['product_name'],
                    ':q'   => (int) $l['qty'],
                    ':p'   => (float) $l['unit_price'],
                    ':lt'  => (float) $l['line_total'],
                ]);
                $total += (float) $l['line_total'];
            }
            $pdo->prepare('UPDATE orders SET total_amount = :t WHERE id = :id')
                ->execute([':t' => $total, ':id' => $orderId]);
            $pdo->commit();
            return $total;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setAddress(int $orderId, int $addressId): void
    {
        db()->prepare('UPDATE orders SET address_id = :a WHERE id = :id')
            ->execute([':a' => $addressId, ':id' => $orderId]);
    }

    public static function setSlot(int $orderId, int $slotId): void
    {
        db()->prepare('UPDATE orders SET slot_id = :s WHERE id = :id')
            ->execute([':s' => $slotId, ':id' => $orderId]);
    }

    public static function setStatus(int $orderId, string $status): void
    {
        db()->prepare('UPDATE orders SET status = :s WHERE id = :id')
            ->execute([':s' => $status, ':id' => $orderId]);
    }

    public static function markPaid(int $orderId, string $payfastPaymentId): void
    {
        db()->prepare(
            'UPDATE orders SET status = "paid", payfast_payment_id = :pf, paid_at = NOW() WHERE id = :id'
        )->execute([':pf' => $payfastPaymentId, ':id' => $orderId]);
    }
}

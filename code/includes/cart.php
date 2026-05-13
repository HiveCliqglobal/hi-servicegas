<?php
/**
 * cart.php — web-shop session helpers.
 *
 * Web flow keeps cart state in PHP $_SESSION (fast, no DB chatter)
 * and only persists to the orders table when we move past "browse".
 *
 * Cart structure in session:
 *   $_SESSION['hs_cart'] = [
 *      'customer_id'  => 42,
 *      'order_id'     => 123,           // populated once persisted
 *      'items'        => [ { product_id, qty, unit_price, name } ],
 *      'address_id'   => 17,            // chosen delivery address
 *      'slot_id'      => 8,             // chosen slot
 *      'total'        => 770.00,
 *      'updated_at'   => 1735482900,
 *   ]
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

final class Cart
{
    private const KEY = 'hs_cart';

    public static function init(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (!isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = [
                'customer_id' => null,
                'order_id'    => null,
                'items'       => [],
                'address_id'  => null,
                'slot_id'     => null,
                'total'       => 0.0,
                'updated_at'  => time(),
            ];
        }
    }

    public static function get(): array
    {
        self::init();
        return $_SESSION[self::KEY];
    }

    public static function set(array $patch): void
    {
        self::init();
        $_SESSION[self::KEY] = array_merge($_SESSION[self::KEY], $patch, ['updated_at' => time()]);
    }

    public static function clear(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        unset($_SESSION[self::KEY]);
    }

    /** Add/replace a product line. qty=0 removes it. */
    public static function setItem(int $productId, int $qty, float $unitPrice, string $name): void
    {
        self::init();
        $items = $_SESSION[self::KEY]['items'];
        $i = -1;
        foreach ($items as $idx => $line) {
            if ((int) $line['product_id'] === $productId) { $i = $idx; break; }
        }
        if ($qty <= 0) {
            if ($i >= 0) array_splice($items, $i, 1);
        } else {
            $line = [
                'product_id' => $productId,
                'name'       => $name,
                'qty'        => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $qty * $unitPrice,
            ];
            if ($i >= 0) $items[$i] = $line; else $items[] = $line;
        }
        $_SESSION[self::KEY]['items'] = array_values($items);
        $_SESSION[self::KEY]['total'] = array_sum(array_column($items, 'line_total'));
        $_SESSION[self::KEY]['updated_at'] = time();
    }

    public static function items(): array
    {
        self::init();
        return $_SESSION[self::KEY]['items'] ?? [];
    }

    public static function total(): float
    {
        self::init();
        return (float) ($_SESSION[self::KEY]['total'] ?? 0);
    }

    public static function isEmpty(): bool
    {
        return empty(self::items());
    }

    public static function requireCustomer(): int
    {
        self::init();
        $cid = (int) ($_SESSION[self::KEY]['customer_id'] ?? 0);
        if ($cid === 0) {
            header('Location: /shop/identify.php');
            exit;
        }
        return $cid;
    }
}

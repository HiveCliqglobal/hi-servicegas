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

        foreach ($tokens as $tok) {
            if (!preg_match('/^([A-Z])(\d+)$/', strtoupper($tok), $m)) {
                $errors[] = "Bad token: {$tok}";
                continue;
            }
            $letter = $m[1];
            $qty    = (int) $m[2];
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

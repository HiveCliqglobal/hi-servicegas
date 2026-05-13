<?php
/**
 * customer_repo.php — customer + address persistence.
 *
 * Source of truth = MySQL. Xero sync (Stage 3) layered on top — when a
 * customer is created here, we also create them in Xero and store the
 * xero_contact_id back. Reads always come from MySQL.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

final class CustomerRepo
{
    public static function findByPhone(string $phone): ?array
    {
        $phone = normalize_phone($phone);
        if ($phone === '') return null;
        $stmt = db()->prepare('SELECT * FROM customers WHERE phone = :p LIMIT 1');
        $stmt->execute([':p' => $phone]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['addresses'] = self::addressesFor((int) $row['id']);
        return $row;
    }

    public static function findByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') return null;
        $stmt = db()->prepare('SELECT * FROM customers WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['addresses'] = self::addressesFor((int) $row['id']);
        return $row;
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['addresses'] = self::addressesFor((int) $row['id']);
        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = db()->prepare(
            'INSERT INTO customers (phone, full_name, email, status)
             VALUES (:p, :n, :e, "active")'
        );
        $stmt->execute([
            ':p' => normalize_phone((string) ($data['phone'] ?? '')),
            ':n' => trim((string) ($data['full_name'] ?? '')),
            ':e' => strtolower(trim((string) ($data['email'] ?? ''))) ?: null,
        ]);
        return (int) db()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];
        foreach (['phone', 'full_name', 'email', 'status', 'xero_contact_id', 'notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "{$f} = :{$f}";
                $params[":{$f}"] = $data[$f] === null ? null : (string) $data[$f];
            }
        }
        if (!$fields) return;
        $sql = 'UPDATE customers SET ' . implode(', ', $fields) . ' WHERE id = :id';
        db()->prepare($sql)->execute($params);
    }

    // ============ Addresses ============

    public static function addressesFor(int $customerId): array
    {
        $stmt = db()->prepare('SELECT * FROM addresses WHERE customer_id = :id ORDER BY is_default DESC, id DESC');
        $stmt->execute([':id' => $customerId]);
        return $stmt->fetchAll();
    }

    public static function defaultAddress(int $customerId): ?array
    {
        $stmt = db()->prepare('SELECT * FROM addresses WHERE customer_id = :id ORDER BY is_default DESC, id DESC LIMIT 1');
        $stmt->execute([':id' => $customerId]);
        return $stmt->fetch() ?: null;
    }

    public static function addAddress(int $customerId, array $a): int
    {
        // Demote previous defaults if this one is default
        if (!empty($a['is_default'])) {
            db()->prepare('UPDATE addresses SET is_default = 0 WHERE customer_id = :id')
                ->execute([':id' => $customerId]);
        }
        $stmt = db()->prepare(
            'INSERT INTO addresses (customer_id, type, line1, line2, city, postal_code, is_default)
             VALUES (:cid, :t, :l1, :l2, :c, :pc, :d)'
        );
        $stmt->execute([
            ':cid' => $customerId,
            ':t'   => $a['type']        ?? 'street',
            ':l1'  => trim((string) ($a['line1'] ?? '')),
            ':l2'  => $a['line2']       ?? null,
            ':c'   => $a['city']        ?? null,
            ':pc'  => $a['postal_code'] ?? null,
            ':d'   => !empty($a['is_default']) ? 1 : 0,
        ]);
        return (int) db()->lastInsertId();
    }

    // ============ Delivery zone check ============

    /**
     * A code is "in zone" if it matches the street_code OR the po_box_code
     * for any active delivery zone. This mirrors the n8n + Excel logic where
     * a single suburb has both a street postal code and a PO Box code.
     */
    public static function postalCodeInZone(string $postalCode): bool
    {
        $pc = trim($postalCode);
        // Two named params because PDO native preparation can't reuse :pc twice
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM delivery_zones
             WHERE is_active = 1 AND (postal_code = :pc1 OR po_box_code = :pc2)'
        );
        $stmt->execute([':pc1' => $pc, ':pc2' => $pc]);
        return ((int) $stmt->fetchColumn()) > 0;
    }

    /** Resolve a postal code to suburb info (or null). */
    public static function zoneFor(string $postalCode): ?array
    {
        $pc = trim($postalCode);
        $stmt = db()->prepare(
            'SELECT * FROM delivery_zones
             WHERE is_active = 1 AND (postal_code = :pc1 OR po_box_code = :pc2)
             LIMIT 1'
        );
        $stmt->execute([':pc1' => $pc, ':pc2' => $pc]);
        return $stmt->fetch() ?: null;
    }
}

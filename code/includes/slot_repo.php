<?php
/**
 * slot_repo.php — slot listing + atomic allocation.
 *
 * Replaces the Google Sheet from n8n with proper MySQL row-level locking.
 * Two simultaneous bookings against the same slot cannot both succeed.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class SlotRepo
{
    /**
     * Return next available slots over the next 7 days, capped to 2 morning + 2 afternoon.
     *
     * Time-of-day rules ported from n8n:
     *  - Today before 12:00 → today PM + tomorrow AM
     *  - Today 12:00+      → tomorrow AM + tomorrow PM
     *
     * @return array list of ['letter'=>'A', 'slot'=>row, 'display'=>string]
     */
    public static function availableSlots(): array
    {
        $today = date('Y-m-d');
        $hour  = (int) date('G');

        $rows = db()->prepare(
            'SELECT * FROM slots
             WHERE delivery_date >= CURDATE()
               AND delivery_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND is_active = 1
               AND booked_count < capacity
             ORDER BY delivery_date, time_block'
        );
        $rows->execute();
        $all = $rows->fetchAll();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $hasTodaySlot = false;
        foreach ($all as $s) {
            if ($s['delivery_date'] === $today) { $hasTodaySlot = true; break; }
        }

        if ($hasTodaySlot && $hour < 12) {
            $all = array_filter($all, fn($s) =>
                ($s['delivery_date'] === $today    && $s['time_block'] === '13:00-16:30') ||
                ($s['delivery_date'] === $tomorrow && $s['time_block'] === '08:00-12:00')
            );
        } elseif ($hasTodaySlot && $hour >= 12) {
            $all = array_filter($all, fn($s) => $s['delivery_date'] === $tomorrow);
        }

        $morning   = array_slice(array_values(array_filter($all, fn($s) => $s['time_block'] === '08:00-12:00')), 0, 2);
        $afternoon = array_slice(array_values(array_filter($all, fn($s) => $s['time_block'] === '13:00-16:30')), 0, 2);

        $letters = ['A', 'B', 'C', 'D'];
        $out = [];
        $i = 0;
        foreach (array_merge($morning, $afternoon) as $slot) {
            $out[] = [
                'letter'  => $letters[$i++] ?? '?',
                'slot'    => $slot,
                'display' => self::displayLabel($slot),
            ];
        }
        return $out;
    }

    /** Find the closest available slot to a given target date. */
    public static function closestTo(string $isoDate): ?array
    {
        $stmt = db()->prepare(
            'SELECT *, ABS(DATEDIFF(delivery_date, :d)) AS dist
             FROM slots
             WHERE is_active = 1
               AND booked_count < capacity
               AND delivery_date >= CURDATE()
             ORDER BY dist, delivery_date
             LIMIT 1'
        );
        $stmt->execute([':d' => $isoDate]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Allocate (book) a slot atomically.
     *
     * @return bool  true if the row was decremented (success), false if full / not found.
     */
    public static function allocate(int $slotId): bool
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'UPDATE slots SET booked_count = booked_count + 1
             WHERE id = :id AND booked_count < capacity AND is_active = 1'
        );
        $stmt->execute([':id' => $slotId]);
        return $stmt->rowCount() === 1;
    }

    /** Release a slot (e.g. on order cancellation / payment failed). */
    public static function release(int $slotId): void
    {
        db()->prepare('UPDATE slots SET booked_count = GREATEST(booked_count - 1, 0) WHERE id = :id')
            ->execute([':id' => $slotId]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM slots WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function displayLabel(array $slot): string
    {
        $d = new DateTime($slot['delivery_date']);
        $when = $d->format('l, j F Y');
        $tod  = $slot['time_block'] === '08:00-12:00' ? 'Morning' : 'Afternoon';
        return "{$when} · {$tod} ({$slot['time_block']})";
    }

    /** Helper: pre-create future slots if cron hasn't yet. */
    public static function ensureNextNDays(int $days = 14, int $capacity = 6): void
    {
        $pdo = db();
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO slots (delivery_date, time_block, capacity, is_active)
             VALUES (:d, :tb, :cap, 1)'
        );
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime("+{$i} day"));
            foreach (['08:00-12:00', '13:00-16:30'] as $tb) {
                $stmt->execute([':d' => $d, ':tb' => $tb, ':cap' => $capacity]);
            }
        }
    }
}

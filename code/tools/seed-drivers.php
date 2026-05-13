<?php
/**
 * tools/seed-drivers.php
 *
 * Sets the PIN for the 3 demo drivers seeded by migration 005,
 * and assigns ~half of the existing paid/delivered demo orders to them
 * so the driver app has data to show out of the box.
 *
 * All three drivers get PIN = 1234 for demo purposes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { echo "CLI only.\n"; exit(1); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = db();

// 1. Hash 1234 → bcrypt
$hash = password_hash('1234', PASSWORD_BCRYPT, ['cost' => 11]);

// 2. Update existing drivers OR insert them (idempotent)
$drivers = [
    ['Daniel Mokoena', '27821234001', 'daniel@hiservice.co.za',  'CA 145-678', '#d62828'],
    ['Pieter Joubert', '27821234002', 'pieter@hiservice.co.za',  'CA 245-789', '#0f7a52'],
    ['Sipho Khumalo',  '27821234003', 'sipho@hiservice.co.za',   'CA 345-890', '#7c3aed'],
];

foreach ($drivers as [$name, $phone, $email, $reg, $color]) {
    $exists = $pdo->prepare("SELECT id FROM drivers WHERE phone = :p");
    $exists->execute([':p' => $phone]);
    $row = $exists->fetch();
    if ($row) {
        $pdo->prepare("UPDATE drivers SET name=:n, email=:e, vehicle_reg=:v, pin_hash=:h, avatar_color=:c, is_active=1 WHERE id=:id")
            ->execute([':n' => $name, ':e' => $email, ':v' => $reg, ':h' => $hash, ':c' => $color, ':id' => $row['id']]);
        echo "✓ Updated driver: $name (PIN 1234)\n";
    } else {
        $pdo->prepare("INSERT INTO drivers (name, phone, email, vehicle_reg, pin_hash, avatar_color) VALUES (:n, :p, :e, :v, :h, :c)")
            ->execute([':n' => $name, ':p' => $phone, ':e' => $email, ':v' => $reg, ':h' => $hash, ':c' => $color]);
        echo "✓ Created driver: $name (PIN 1234)\n";
    }
}

// 3. Sample assignment — randomly assign ~60% of historical 'delivered' demo orders
//    so the driver app's "Delivered" view has data to show.
$driverIds = array_column($pdo->query("SELECT id FROM drivers WHERE is_active = 1")->fetchAll(), 'id');
if (empty($driverIds)) { echo "No drivers to assign.\n"; exit(0); }

$stmt = $pdo->query("SELECT id FROM orders WHERE is_demo = 1 AND status = 'delivered' AND assigned_driver_id IS NULL");
$ids = array_column($stmt->fetchAll(), 'id');
$assignedCount = 0;
foreach ($ids as $oid) {
    if (rand(0, 100) < 60) {
        $did = $driverIds[array_rand($driverIds)];
        $pdo->prepare("UPDATE orders SET assigned_driver_id = :d WHERE id = :id")
            ->execute([':d' => $did, ':id' => $oid]);
        $assignedCount++;
    }
}
echo "✓ Back-assigned {$assignedCount} historical delivered orders to drivers.\n";

// 4. Promote ~half of recent paid demo orders to "available for delivery"
//    (they show up as incoming on the driver app)
$paidIds = array_column($pdo->query("SELECT id FROM orders WHERE is_demo = 1 AND status = 'paid' AND assigned_driver_id IS NULL LIMIT 20")->fetchAll(), 'id');
echo "✓ {$assignedCount} delivered (assigned) · " . count($paidIds) . " paid (incoming, unassigned)\n";

echo "\nDone. Drivers can sign in at /driver/ with PIN 1234.\n";

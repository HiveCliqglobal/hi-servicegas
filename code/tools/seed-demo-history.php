<?php
/**
 * tools/seed-demo-history.php
 *
 * Seeds ~314 demo orders across the last 12 months with a realistic
 * South African gas-delivery seasonality:
 *   · Winter peak  : June–August (heaviest, cold = heaters on)
 *   · Shoulder     : May + September
 *   · Summer trough: December–February (cold storage, low usage)
 *   · Spring climb : March–April
 *
 * All orders are tagged is_demo = 1 and marked with payfast_payment_id
 * starting "SEED-" so the script is idempotent — re-running cleans
 * its previous seed.
 *
 * Run on the server:
 *   /opt/alt/php81/usr/bin/php ~/public_html/tools/seed-demo-history.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = db();

// =====================================================
// 1. Clear previous seed orders (keep manual demo runs)
// =====================================================
echo "Clearing previous seed runs…\n";
$pdo->exec("DELETE FROM order_lines WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE payfast_payment_id LIKE 'SEED-%') AS t)");
$deleted = $pdo->exec("DELETE FROM orders WHERE payfast_payment_id LIKE 'SEED-%'");
echo "  removed {$deleted} previous seed order(s)\n";

// =====================================================
// 2. Demo customers — realistic SA name mix
// =====================================================
$customers = [
    ['Daniel Smith',         '27821001001', 'daniel@demo.test'],
    ['Sarah Johnson',        '27821001002', 'sarah@demo.test'],
    ['Pieter van der Merwe', '27821001003', 'pieter@demo.test'],
    ['Nomsa Mbeki',          '27821001004', 'nomsa@demo.test'],
    ['Karl Marais',          '27821001005', 'karl@demo.test'],
    ['Lerato Khumalo',       '27821001006', 'lerato@demo.test'],
    ['Riaan Joubert',        '27821001007', 'riaan@demo.test'],
    ['Mandla Dlamini',       '27821001008', 'mandla@demo.test'],
    ['Christelle du Toit',   '27821001009', 'christelle@demo.test'],
    ['Sipho Nkomo',          '27821001010', 'sipho@demo.test'],
    ['Antoinette Botha',     '27821001011', 'antoinette@demo.test'],
    ['Thabo Mokoena',        '27821001012', 'thabo@demo.test'],
    ['Helene Pretorius',     '27821001013', 'helene@demo.test'],
    ['Bongani Zulu',         '27821001014', 'bongani@demo.test'],
    ['Marlene Steyn',        '27821001015', 'marlene@demo.test'],
];

$zones = ['7140' => 'Strand', '7130' => 'Somerset West', '7195' => 'Kleinmond', '7600' => 'Stellenbosch', '7141' => 'Lwandle'];
$streetNames = ['Main','Park','Beach','Church','Long','High','Oak','Vineyard','Coastal','Mountain','Sunset'];

$customerIds = [];
foreach ($customers as [$name, $phone, $email]) {
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = :p");
    $stmt->execute([':p' => $phone]);
    $row = $stmt->fetch();
    if ($row) {
        $id = (int) $row['id'];
    } else {
        $pdo->prepare("INSERT INTO customers (phone, full_name, email, status) VALUES (:p, :n, :e, 'active')")
            ->execute([':p' => $phone, ':n' => $name, ':e' => $email]);
        $id = (int) $pdo->lastInsertId();
    }
    // Ensure default address
    $addrStmt = $pdo->prepare("SELECT id FROM addresses WHERE customer_id = :c LIMIT 1");
    $addrStmt->execute([':c' => $id]);
    $aRow = $addrStmt->fetch();
    if (!$aRow) {
        $pc = array_rand($zones);
        $city = $zones[$pc];
        $line1 = rand(1, 99) . ' ' . $streetNames[array_rand($streetNames)] . ' Street';
        $pdo->prepare("INSERT INTO addresses (customer_id, line1, city, postal_code, is_default) VALUES (:c, :l, :ci, :pc, 1)")
            ->execute([':c' => $id, ':l' => $line1, ':ci' => $city, ':pc' => $pc]);
        $aId = (int) $pdo->lastInsertId();
    } else {
        $aId = (int) $aRow['id'];
    }
    $customerIds[] = ['cid' => $id, 'aid' => $aId];
}
echo "Customers ready: " . count($customerIds) . "\n";

// =====================================================
// 3. Products with realistic weighting (9kg most popular)
// =====================================================
$products = $pdo->query("SELECT id, code, name, price FROM products WHERE is_active = 1")->fetchAll();
$weightedProducts = [];
$weights = ['LPG-5KG' => 25, 'LPG-9KG' => 40, 'LPG-14KG' => 10, 'LPG-19KG' => 18, 'LPG-48KG' => 7];
foreach ($products as $p) {
    $w = $weights[$p['code']] ?? 10;
    for ($i = 0; $i < $w; $i++) $weightedProducts[] = $p;
}

// =====================================================
// 4. Monthly volumes — Cape winter pattern
// =====================================================
$volumes = [
    '2025-05' => 18,   // shoulder (partial month — started mid-May 2025)
    '2025-06' => 45,   // 🥶 winter begins
    '2025-07' => 62,   // ❄️ peak
    '2025-08' => 55,   // ❄️ peak tail
    '2025-09' => 32,   // cooling off
    '2025-10' => 18,
    '2025-11' => 12,
    '2025-12' => 8,    // 🌞 summer trough
    '2026-01' => 9,
    '2026-02' => 11,
    '2026-03' => 14,
    '2026-04' => 22,   // autumn climb
    '2026-05' => 12,   // current month so far
];

// =====================================================
// 5. Helper — find/create historical slot
// =====================================================
$slotCache = [];
$getSlot = function (string $date, string $block) use ($pdo, &$slotCache): int {
    $key = "{$date}|{$block}";
    if (isset($slotCache[$key])) return $slotCache[$key];
    $stmt = $pdo->prepare("SELECT id FROM slots WHERE delivery_date = :d AND time_block = :b");
    $stmt->execute([':d' => $date, ':b' => $block]);
    $row = $stmt->fetch();
    if ($row) {
        $id = (int) $row['id'];
    } else {
        $pdo->prepare("INSERT INTO slots (delivery_date, time_block, capacity, booked_count, is_active) VALUES (:d, :b, 50, 0, 1)")
            ->execute([':d' => $date, ':b' => $block]);
        $id = (int) $pdo->lastInsertId();
    }
    $slotCache[$key] = $id;
    return $id;
};

// =====================================================
// 6. Generate orders
// =====================================================
$totalCreated = 0;
$totalRevenue = 0.0;
echo "\nMonth         Orders   Pattern\n";
echo "------------- -------  -----------------------------\n";

foreach ($volumes as $ym => $n) {
    $year = (int) substr($ym, 0, 4);
    $month = (int) substr($ym, 5, 2);
    $daysInMonth = (int) date('t', strtotime("$year-$month-01"));

    // Cap day for current month
    $maxDay = $daysInMonth;
    if ($year === (int) date('Y') && $month === (int) date('n')) {
        $maxDay = (int) date('j');
    }
    // For our partial May 2025 (started seeding mid-may)
    $minDay = ($ym === '2025-05') ? 13 : 1;

    $monthRev = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $day = rand($minDay, $maxDay);
        // 70% business hours, 30% spread across the day
        $hour = (rand(0, 100) < 70) ? rand(8, 18) : rand(0, 23);
        $createdAt = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, rand(0, 59), rand(0, 59));

        $cust = $customerIds[array_rand($customerIds)];
        $ref = 'ORD-' . date('YmdHis', strtotime($createdAt)) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $channel = rand(0, 100) < 65 ? 'whatsapp' : 'web';

        // Status distribution: 85% paid, 8% delivered (older orders), 5% cancelled, 2% failed
        $r = rand(0, 100);
        $monthsAgo = ((int) date('Y') - $year) * 12 + ((int) date('n') - $month);
        if ($monthsAgo > 1) {
            // older orders likely delivered or cancelled
            $status = $r < 80 ? 'delivered' : ($r < 92 ? 'paid' : ($r < 97 ? 'cancelled' : 'failed'));
        } else {
            $status = $r < 78 ? 'paid' : ($r < 88 ? 'delivered' : ($r < 96 ? 'cancelled' : 'failed'));
        }

        // 1–3 line items, distinct products
        $lineCount = rand(0, 100) < 70 ? 1 : (rand(0, 100) < 70 ? 2 : 3);
        $lines = [];
        $total = 0.0;
        $usedIds = [];
        $tries = 0;
        while (count($lines) < $lineCount && $tries++ < 10) {
            $p = $weightedProducts[array_rand($weightedProducts)];
            if (in_array((int) $p['id'], $usedIds, true)) continue;
            $usedIds[] = (int) $p['id'];
            $qty = rand(1, 2);
            $lineTotal = $qty * (float) $p['price'];
            $lines[] = [$p['id'], $p['name'], $qty, (float) $p['price'], $lineTotal];
            $total += $lineTotal;
        }
        if (empty($lines)) continue;

        // Delivery 1-2 days after order
        $deliveryDate = date('Y-m-d', strtotime("$year-$month-$day +" . rand(1, 2) . " days"));
        $timeBlock = rand(0, 1) ? '08:00-12:00' : '13:00-16:30';
        $slotId = $getSlot($deliveryDate, $timeBlock);

        $paidAt = null;
        $deliveredAt = null;
        if (in_array($status, ['paid', 'delivered'], true)) {
            $paidAt = date('Y-m-d H:i:s', strtotime($createdAt) + rand(60, 900));
        }
        if ($status === 'delivered') {
            $deliveredAt = date('Y-m-d H:i:s', strtotime("$deliveryDate $timeBlock") - rand(0, 3600));
        }

        $pdo->prepare("
          INSERT INTO orders
          (order_reference, customer_id, address_id, slot_id, channel, is_demo, status,
           total_amount, payfast_payment_id, paid_at, delivered_at, created_at, updated_at)
          VALUES (:r, :c, :a, :s, :ch, 1, :st, :t, :pf, :p, :d, :cr1, :cr2)
        ")->execute([
            ':r'   => $ref,
            ':c'   => $cust['cid'],
            ':a'   => $cust['aid'],
            ':s'   => $slotId,
            ':ch'  => $channel,
            ':st'  => $status,
            ':t'   => $total,
            ':pf'  => 'SEED-' . substr($ref, -10),
            ':p'   => $paidAt,
            ':d'   => $deliveredAt,
            ':cr1' => $createdAt,
            ':cr2' => $createdAt,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO order_lines (order_id, product_id, product_name, qty, unit_price, line_total) VALUES (:o, :p, :n, :q, :u, :l)");
        foreach ($lines as $ln) {
            $stmt->execute([':o' => $orderId, ':p' => $ln[0], ':n' => $ln[1], ':q' => $ln[2], ':u' => $ln[3], ':l' => $ln[4]]);
        }

        if (in_array($status, ['paid', 'delivered'], true)) $monthRev += $total;
        $totalCreated++;
    }
    $bar = str_repeat('▇', min(40, (int) ceil($n / 1.5)));
    echo sprintf("%-13s %6d   %s  R %s\n", $ym, $n, $bar, number_format($monthRev, 0));
    $totalRevenue += $monthRev;
}

echo "\n────────────────────────────────────────────────────\n";
echo "✓ Created {$totalCreated} demo orders\n";
echo "✓ Total demo revenue: R " . number_format($totalRevenue, 2) . "\n";
echo "✓ Spread across " . count($volumes) . " months\n";
echo "\nRun '\$ DELETE FROM orders WHERE payfast_payment_id LIKE \"SEED-%\";' to remove cleanly.\n";
echo "Or just re-run this script — it's idempotent.\n";

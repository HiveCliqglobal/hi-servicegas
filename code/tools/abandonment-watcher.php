<?php
/**
 * tools/abandonment-watcher.php
 *
 * Detects customers who stalled mid-flow OR left orders unpaid and pings
 * admins via GHL so nobody falls through the cracks. Designed to be run
 * by cron every 10 minutes:
 *
 *   *\/10 * * * *  /usr/local/bin/php /home/hiserviceshopz/public_html/tools/abandonment-watcher.php > /dev/null 2>&1
 *
 * Four patterns detected:
 *   1. Session at non-menu state for > 30 min idle    (mid-flow drop-off)
 *   2. Order in 'cart' status > 30 min                 (started, never paid)
 *   3. Order in 'pending_payment' status > 60 min      (PayFast page abandoned)
 *   4. Order 'paid' > 4 hrs but delivered_at NULL      (driver no-show)
 *
 * Dedup: every alert checks event_log for the SAME pattern + entity in
 * the last 4 hours and skips if found. Same abandonment never pings twice.
 *
 * Telegram will plug in here once Stage 4 lands — same notification points.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

// CLI-only safety check (still works under cron)
if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_INTERNAL_CRON'])) {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/ghl.php';

$stats = ['session_stuck' => 0, 'cart_abandoned' => 0, 'payment_abandoned' => 0, 'driver_no_show' => 0];

// ════════════════════════════════════════════════════════════════════
//  Pattern 1 — Session at non-menu state, idle for > 30 min
// ════════════════════════════════════════════════════════════════════
$rows = db()->query("
    SELECT s.phone, s.current_step, s.customer_id, s.updated_at,
           (SELECT MAX(created_at) FROM conversations WHERE phone = s.phone) AS last_msg_at
      FROM sessions s
     WHERE s.current_step NOT IN ('menu', 'cancelled', 'general_help')
       AND s.expires_at > NOW()
       AND s.updated_at < NOW() - INTERVAL 30 MINUTE
")->fetchAll();

foreach ($rows as $r) {
    if (alertAlreadySentRecently('abandonment.session_stuck', $r['phone'], 4)) continue;

    $cust = $r['customer_id'] ? CustomerRepo::findById((int) $r['customer_id']) : CustomerRepo::findByPhone($r['phone']);
    $name = $cust ? (trim((string) ($cust['full_name'] ?? '')) ?: $r['phone']) : $r['phone'];

    log_event('abandonment.session_stuck', 'customer', $r['customer_id'] ? (string) $r['customer_id'] : null, [
        'phone'        => $r['phone'],
        'state'        => $r['current_step'],
        'idle_since'   => $r['updated_at'],
        'last_msg_at'  => $r['last_msg_at'],
    ]);

    try {
        $gid = $cust ? GHL::syncCustomer($cust) : '';
        if ($gid) {
            GHL::addTag($gid, ['whatsapp-abandoned', 'mid-flow-drop-off']);
            GHL::notifyUser(
                GHL::USER_GAS,
                "Customer stalled mid-order",
                "Customer: *{$name}* ({$r['phone']})\n" .
                "Stalled at step: *{$r['current_step']}*\n" .
                "Idle since: {$r['updated_at']}\n" .
                "Last message at: {$r['last_msg_at']}\n\n" .
                "Reach out — their session is still active, anything you reply on WhatsApp picks up where they left off."
            );
        }
    } catch (Throwable $e) {
        log_to_file('abandonment', 'GHL notify failed for session_stuck', ['err' => $e->getMessage(), 'phone' => $r['phone']]);
    }
    $stats['session_stuck']++;
}

// ════════════════════════════════════════════════════════════════════
//  Pattern 2 — Order in 'cart' status > 30 min
// ════════════════════════════════════════════════════════════════════
$rows = db()->query("
    SELECT o.id, o.order_reference, o.customer_id, o.total_amount, o.created_at
      FROM orders o
     WHERE o.status = 'cart'
       AND o.created_at < NOW() - INTERVAL 30 MINUTE
       AND o.created_at > NOW() - INTERVAL 7 DAY
")->fetchAll();

foreach ($rows as $r) {
    $entityKey = (string) $r['order_reference'];
    if (alertAlreadySentRecently('abandonment.cart_abandoned', $entityKey, 24)) continue;

    $cust = CustomerRepo::findById((int) $r['customer_id']);
    $name = $cust ? (trim((string) ($cust['full_name'] ?? '')) ?: $cust['phone']) : '(unknown)';
    $phone = $cust['phone'] ?? '';

    log_event('abandonment.cart_abandoned', 'order', $entityKey, [
        'amount' => (float) $r['total_amount'],
        'created_at' => $r['created_at'],
    ]);

    try {
        $gid = $cust ? GHL::syncCustomer($cust) : '';
        if ($gid) {
            GHL::addTag($gid, ['cart-abandoned']);
            GHL::notifyUser(
                GHL::USER_GAS,
                "Cart abandoned — never went to payment",
                "Customer: *{$name}* ({$phone})\n" .
                "Order ref: *{$r['order_reference']}*\n" .
                "Cart value: R " . number_format((float) $r['total_amount'], 2) . "\n" .
                "Started: {$r['created_at']}\n\n" .
                "They built a cart but never clicked Pay. Worth a follow-up — their session is still active."
            );
        }
    } catch (Throwable $e) {
        log_to_file('abandonment', 'GHL notify failed for cart_abandoned', ['err' => $e->getMessage(), 'order' => $entityKey]);
    }
    $stats['cart_abandoned']++;
}

// ════════════════════════════════════════════════════════════════════
//  Pattern 3 — Order in 'pending_payment' > 60 min (PayFast page abandoned)
// ════════════════════════════════════════════════════════════════════
$rows = db()->query("
    SELECT o.id, o.order_reference, o.customer_id, o.total_amount, o.created_at
      FROM orders o
     WHERE o.status = 'pending_payment'
       AND o.created_at < NOW() - INTERVAL 60 MINUTE
       AND o.created_at > NOW() - INTERVAL 7 DAY
")->fetchAll();

foreach ($rows as $r) {
    $entityKey = (string) $r['order_reference'];
    if (alertAlreadySentRecently('abandonment.payment_abandoned', $entityKey, 24)) continue;

    $cust = CustomerRepo::findById((int) $r['customer_id']);
    $name = $cust ? (trim((string) ($cust['full_name'] ?? '')) ?: $cust['phone']) : '(unknown)';
    $phone = $cust['phone'] ?? '';

    log_event('abandonment.payment_abandoned', 'order', $entityKey, [
        'amount' => (float) $r['total_amount'],
        'created_at' => $r['created_at'],
    ]);

    try {
        $gid = $cust ? GHL::syncCustomer($cust) : '';
        if ($gid) {
            GHL::addTag($gid, ['payment-abandoned']);
            GHL::notifyUser(
                GHL::USER_GAS,
                "Payment link not completed",
                "Customer: *{$name}* ({$phone})\n" .
                "Order ref: *{$r['order_reference']}*\n" .
                "Amount: R " . number_format((float) $r['total_amount'], 2) . "\n" .
                "Created: {$r['created_at']}\n\n" .
                "We sent them a PayFast link but they never paid. Reach out — possibly cold feet, card issue, or simple distraction."
            );
        }
    } catch (Throwable $e) {
        log_to_file('abandonment', 'GHL notify failed for payment_abandoned', ['err' => $e->getMessage(), 'order' => $entityKey]);
    }
    $stats['payment_abandoned']++;
}

// ════════════════════════════════════════════════════════════════════
//  Pattern 4 — Order 'paid' > 4 hrs but no delivery
// ════════════════════════════════════════════════════════════════════
$rows = db()->query("
    SELECT o.id, o.order_reference, o.customer_id, o.assigned_driver_id, o.paid_at,
           d.name AS driver_name
      FROM orders o
      LEFT JOIN drivers d ON d.id = o.assigned_driver_id
     WHERE o.status = 'paid'
       AND o.paid_at IS NOT NULL
       AND o.paid_at < NOW() - INTERVAL 4 HOUR
       AND o.delivered_at IS NULL
       AND o.paid_at > NOW() - INTERVAL 7 DAY
")->fetchAll();

foreach ($rows as $r) {
    $entityKey = (string) $r['order_reference'];
    if (alertAlreadySentRecently('abandonment.driver_no_show', $entityKey, 4)) continue;

    $cust = CustomerRepo::findById((int) $r['customer_id']);
    $name = $cust ? (trim((string) ($cust['full_name'] ?? '')) ?: $cust['phone']) : '(unknown)';
    $phone = $cust['phone'] ?? '';

    log_event('abandonment.driver_no_show', 'order', $entityKey, [
        'paid_at' => $r['paid_at'],
        'driver'  => $r['driver_name'],
    ]);

    try {
        $gid = $cust ? GHL::syncCustomer($cust) : '';
        if ($gid) {
            GHL::addTag($gid, ['delivery-late', 'driver-no-show']);
            GHL::notifyUser(
                GHL::USER_GAS,
                "🚨 Delivery overdue (paid 4+ hrs ago)",
                "Customer: *{$name}* ({$phone})\n" .
                "Order ref: *{$r['order_reference']}*\n" .
                "Paid at: {$r['paid_at']}\n" .
                "Assigned driver: " . ($r['driver_name'] ?: '(unassigned)') . "\n\n" .
                "Customer hasn't received their order yet. Chase the driver + reach out to customer."
            );
        }
    } catch (Throwable $e) {
        log_to_file('abandonment', 'GHL notify failed for driver_no_show', ['err' => $e->getMessage(), 'order' => $entityKey]);
    }
    $stats['driver_no_show']++;
}

// ─── Summary log + console output ───
$total = array_sum($stats);
log_event('abandonment.watcher_run', null, null, $stats + ['total_alerts_sent' => $total]);

echo "[" . date('Y-m-d H:i:s') . "] abandonment-watcher: " . json_encode($stats) . "\n";


// ════════════════════════════════════════════════════════════════════
//  Helper — has this alert pattern fired for this entity in the last N hours?
// ════════════════════════════════════════════════════════════════════
function alertAlreadySentRecently(string $action, string $entityId, int $hours): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM event_log
          WHERE action = :a
            AND entity_id = :e
            AND created_at > NOW() - INTERVAL :h HOUR
          LIMIT 1"
    );
    $stmt->bindValue(':a', $action, PDO::PARAM_STR);
    $stmt->bindValue(':e', $entityId, PDO::PARAM_STR);
    $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
    $stmt->execute();
    return (bool) $stmt->fetch();
}

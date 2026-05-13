<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_bootstrap.php';
driver_require_login();

$drv = current_driver();
$nav = 'today';
$pageTitle = 'Order · Hi-Service Driver';

$orderId = (int) ($_GET['id'] ?? 0);

$flash = null;
$err   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && driver_csrf_verify($_POST['csrf'] ?? '')) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_delivered') {
        $notes = trim((string) ($_POST['notes'] ?? ''));
        try {
            db()->prepare("
              UPDATE orders
              SET status = 'delivered',
                  delivered_at = NOW(),
                  assigned_driver_id = :did,
                  driver_notes = :n
              WHERE id = :id AND status IN ('paid','pending_payment')
            ")->execute([':did' => $drv['id'], ':n' => $notes ?: null, ':id' => $orderId]);
            log_event('driver.order.delivered', 'order', (string) $orderId, ['driver' => $drv['id'], 'note' => $notes], null);
            $flash = 'Delivered. Thanks!';
            // Bounce to delivered list after a brief moment
            header('Location: /driver/delivered.php?just=' . $orderId);
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    } elseif ($action === 'mark_failed') {
        $notes = trim((string) ($_POST['notes'] ?? ''));
        try {
            db()->prepare("
              UPDATE orders SET status='failed', driver_notes=:n, assigned_driver_id=:did
              WHERE id = :id
            ")->execute([':n' => $notes ?: 'Driver flagged delivery as failed', ':did' => $drv['id'], ':id' => $orderId]);
            log_event('driver.order.failed', 'order', (string) $orderId, ['driver' => $drv['id'], 'note' => $notes]);
            header('Location: /driver/today.php?flash=Order+flagged+as+failed');
            exit;
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$stmt = db()->prepare(
    "SELECT o.*,
            c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
            a.line1, a.line2, a.city, a.postal_code,
            s.delivery_date, s.time_block
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN addresses a ON a.id = o.address_id
     LEFT JOIN slots     s ON s.id = o.slot_id
     WHERE o.id = :id LIMIT 1"
);
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    include __DIR__ . '/_header.php';
    echo '<div class="drv-empty"><h3>Order not found</h3><a href="/driver/today.php" class="btn btn-primary">← Back</a></div>';
    include __DIR__ . '/_footer.php';
    exit;
}

$lines = db()->prepare("SELECT * FROM order_lines WHERE order_id = :id");
$lines->execute([':id' => $orderId]);
$lines = $lines->fetchAll();

$addr = trim(implode(', ', array_filter([$order['line1'], $order['line2'], $order['city'], $order['postal_code']])), ', ');
$mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($addr);
$telUrl  = 'tel:' . preg_replace('/\D+/', '', (string) $order['customer_phone']);
$waUrl   = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $order['customer_phone']) . '?text=' . urlencode("Hi " . explode(' ', $order['customer_name'])[0] . ", I'm Hi-Service Gas — on the way with your order!");
$csrf = driver_csrf_token();
$alreadyDelivered = $order['status'] === 'delivered';
$alreadyFailed    = $order['status'] === 'failed';

include __DIR__ . '/_header.php';
?>

<section class="drv-section drv-order-detail">
  <a href="/driver/today.php" class="drv-back">← Back to today</a>

  <?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="drv-card">
    <div class="drv-card-head">
      <div>
        <div class="muted small"><?= htmlspecialchars($order['order_reference']) ?></div>
        <h2 style="margin:2px 0 4px"><?= htmlspecialchars($order['customer_name']) ?></h2>
        <p class="muted small" style="margin:0">
          <?= htmlspecialchars($order['delivery_date'] ? date('D, d M Y', strtotime($order['delivery_date'])) : '') ?>
          <?= $order['time_block'] ? ' · ' . htmlspecialchars($order['time_block']) : '' ?>
        </p>
      </div>
      <div class="drv-card-amount">R <?= number_format((float) $order['total_amount'], 2, '.', ' ') ?></div>
    </div>

    <div class="drv-quick-actions">
      <a href="<?= htmlspecialchars($mapsUrl) ?>" target="_blank" class="drv-qa">
        <span>🗺️</span><span>Map</span>
      </a>
      <a href="<?= htmlspecialchars($telUrl) ?>" class="drv-qa">
        <span>📞</span><span>Call</span>
      </a>
      <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" class="drv-qa">
        <span>💬</span><span>WhatsApp</span>
      </a>
    </div>
  </div>

  <div class="drv-card">
    <h3 style="margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Address</h3>
    <p style="margin:0;font-size:15px;line-height:1.45"><?= htmlspecialchars($addr ?: 'No address on file') ?></p>
  </div>

  <div class="drv-card">
    <h3 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Items</h3>
    <ul class="drv-items">
      <?php foreach ($lines as $l): ?>
        <li>
          <span class="drv-item-qty"><?= (int) $l['qty'] ?>×</span>
          <span class="drv-item-name"><?= htmlspecialchars($l['product_name']) ?></span>
          <span class="drv-item-price">R <?= number_format((float) $l['line_total'], 2, '.', ' ') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if (!empty($order['driver_notes'])): ?>
    <div class="drv-card">
      <h3 style="margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Driver note</h3>
      <p style="margin:0;font-size:14px"><?= nl2br(htmlspecialchars($order['driver_notes'])) ?></p>
    </div>
  <?php endif; ?>

  <?php if ($alreadyDelivered): ?>
    <div class="drv-card" style="background:#dcfce7;border-color:#a7f3d0">
      <h3 style="margin:0;color:#15803d">✅ Delivered <?= $order['delivered_at'] ? '· ' . date('H:i', strtotime($order['delivered_at'])) : '' ?></h3>
    </div>
  <?php elseif ($alreadyFailed): ?>
    <div class="drv-card" style="background:#fef2f2;border-color:#fecaca">
      <h3 style="margin:0;color:#991b1b">❌ Failed — flagged for admin</h3>
    </div>
  <?php else: ?>
    <form method="post" class="drv-mark-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <label>
        <span>Note <small>(optional — e.g. "Left at gate", "Customer paid cash")</small></span>
        <textarea name="notes" rows="3" placeholder="Anything admin should know..."></textarea>
      </label>
      <div class="drv-actions-row">
        <button type="submit" name="action" value="mark_failed" class="btn btn-ghost danger" onclick="return confirm('Flag this delivery as failed?')">❌ Flag failed</button>
        <button type="submit" name="action" value="mark_delivered" class="btn btn-primary btn-lg">✅ Mark delivered</button>
      </div>
    </form>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

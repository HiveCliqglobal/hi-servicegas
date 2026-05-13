<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_bootstrap.php';
driver_require_login();

$drv = current_driver();
$nav = 'delivered';
$pageTitle = 'Delivered · Hi-Service Driver';

$just = (int) ($_GET['just'] ?? 0);

$rows = db()->prepare(
    "SELECT o.id, o.order_reference, o.total_amount, o.delivered_at, o.driver_notes,
            c.full_name, a.line1, a.city, a.postal_code,
            (SELECT GROUP_CONCAT(CONCAT(ol.qty, '× ', ol.product_name) SEPARATOR ' · ')
             FROM order_lines ol WHERE ol.order_id = o.id) AS items_summary
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN addresses a ON a.id = o.address_id
     WHERE o.assigned_driver_id = :id AND o.status = 'delivered'
     ORDER BY o.delivered_at DESC
     LIMIT 50"
);
$rows->execute([':id' => $drv['id']]);
$rows = $rows->fetchAll();

$todayCount = 0; $todayRev = 0.0;
foreach ($rows as $r) {
    if ($r['delivered_at'] && date('Y-m-d', strtotime($r['delivered_at'])) === date('Y-m-d')) {
        $todayCount++;
        $todayRev += (float) $r['total_amount'];
    }
}

$pendingCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();

include __DIR__ . '/_header.php';
?>

<?php if ($just): ?>
  <div class="alert alert-success">✅ Delivered! Order moved here.</div>
<?php endif; ?>

<section class="drv-stats">
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#dcfce7;color:#15803d">✅</div>
    <div>
      <div class="drv-stat-num"><?= $todayCount ?></div>
      <div class="drv-stat-lbl">Delivered today</div>
    </div>
  </div>
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#dbeafe;color:#1e40af">💰</div>
    <div>
      <div class="drv-stat-num" style="font-size:14px">R <?= number_format($todayRev, 0, '.', ' ') ?></div>
      <div class="drv-stat-lbl">Today's revenue</div>
    </div>
  </div>
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#f1f5f9;color:#475569">📦</div>
    <div>
      <div class="drv-stat-num"><?= count($rows) ?></div>
      <div class="drv-stat-lbl">Lifetime</div>
    </div>
  </div>
</section>

<section class="drv-section">
  <div class="drv-section-head">
    <h2>My delivered orders</h2>
    <span class="muted small"><?= count($rows) ?> total</span>
  </div>

  <?php if (empty($rows)): ?>
    <div class="drv-empty">
      <div class="drv-empty-icon">📭</div>
      <h3>No deliveries yet</h3>
      <p>Once you mark an order delivered, it'll appear here.</p>
    </div>
  <?php else: ?>
    <div class="drv-orders">
      <?php foreach ($rows as $r):
        $addr = trim(implode(', ', array_filter([$r['line1'], $r['city'], $r['postal_code']])), ', ');
      ?>
        <a href="/driver/order.php?id=<?= (int) $r['id'] ?>" class="drv-order-card drv-order-done">
          <div class="drv-order-head">
            <div class="drv-order-when">
              <?= $r['delivered_at'] ? htmlspecialchars(date('D, d M · H:i', strtotime($r['delivered_at']))) : '—' ?>
            </div>
            <div class="drv-order-status status-delivered">✓ DELIVERED</div>
          </div>
          <h3 class="drv-order-customer"><?= htmlspecialchars($r['full_name'] ?? '—') ?></h3>
          <p class="drv-order-address">📍 <?= htmlspecialchars($addr) ?></p>
          <p class="drv-order-items">📦 <?= htmlspecialchars($r['items_summary'] ?? '—') ?></p>
          <?php if (!empty($r['driver_notes'])): ?>
            <p class="drv-order-note">📝 <?= htmlspecialchars($r['driver_notes']) ?></p>
          <?php endif; ?>
          <div class="drv-order-foot">
            <span class="drv-order-total">R <?= number_format((float) $r['total_amount'], 2, '.', ' ') ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

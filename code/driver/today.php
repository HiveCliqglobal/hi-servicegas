<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_bootstrap.php';
driver_require_login();

$drv = current_driver();
$nav = 'today';
$pageTitle = 'Today · Hi-Service Driver';

// Pending = paid orders not yet delivered (incoming queue)
$pending = db()->query(
    "SELECT o.*, c.full_name, c.phone AS customer_phone,
            a.line1, a.line2, a.city, a.postal_code,
            s.delivery_date, s.time_block,
            (SELECT GROUP_CONCAT(CONCAT(ol.qty, '× ', ol.product_name) SEPARATOR ' · ')
             FROM order_lines ol WHERE ol.order_id = o.id) AS items_summary
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     LEFT JOIN addresses a ON a.id = o.address_id
     LEFT JOIN slots     s ON s.id = o.slot_id
     WHERE o.status = 'paid'
       AND (o.assigned_driver_id IS NULL OR o.assigned_driver_id = " . (int) $drv['id'] . ")
     ORDER BY s.delivery_date ASC, s.time_block ASC, o.paid_at ASC
     LIMIT 50"
)->fetchAll();

// Today's delivered count for the driver
$stmt = db()->prepare(
    "SELECT COUNT(*) FROM orders
     WHERE assigned_driver_id = :id AND status = 'delivered'
       AND DATE(delivered_at) = CURDATE()"
);
$stmt->execute([':id' => $drv['id']]);
$dTodayCount = (int) $stmt->fetchColumn();

$dTodayRevenue = (float) db()->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM orders
     WHERE assigned_driver_id = " . (int) $drv['id'] . "
       AND status = 'delivered' AND DATE(delivered_at) = CURDATE()"
)->fetchColumn();

$pendingCount = count($pending);

include __DIR__ . '/_header.php';
?>

<section class="drv-stats">
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#fef3c7;color:#b45309">🚚</div>
    <div>
      <div class="drv-stat-num"><?= $pendingCount ?></div>
      <div class="drv-stat-lbl">Incoming</div>
    </div>
  </div>
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#dcfce7;color:#15803d">✅</div>
    <div>
      <div class="drv-stat-num"><?= $dTodayCount ?></div>
      <div class="drv-stat-lbl">Delivered today</div>
    </div>
  </div>
  <div class="drv-stat">
    <div class="drv-stat-icon" style="background:#dbeafe;color:#1e40af">💰</div>
    <div>
      <div class="drv-stat-num" style="font-size:14px">R <?= number_format($dTodayRevenue, 0, '.', ' ') ?></div>
      <div class="drv-stat-lbl">Collected today</div>
    </div>
  </div>
</section>

<section class="drv-section">
  <div class="drv-section-head">
    <h2>Incoming orders</h2>
    <span class="muted small"><?= $pendingCount ?> pending</span>
  </div>

  <?php if (empty($pending)): ?>
    <div class="drv-empty">
      <div class="drv-empty-icon">🎉</div>
      <h3>You're all caught up</h3>
      <p>No incoming orders right now. We'll notify you when a new order is ready.</p>
    </div>
  <?php else: ?>
    <div class="drv-orders">
      <?php foreach ($pending as $o):
        $isToday = !empty($o['delivery_date']) && $o['delivery_date'] === date('Y-m-d');
        $isPast  = !empty($o['delivery_date']) && $o['delivery_date'] < date('Y-m-d');
        $when = $o['delivery_date'] ? date('D, d M', strtotime($o['delivery_date'])) : 'No slot';
        if ($o['time_block']) $when .= ' · ' . $o['time_block'];
        $addr = trim(implode(', ', array_filter([$o['line1'], $o['line2'], $o['city'], $o['postal_code']])), ', ');
      ?>
        <a href="/driver/order.php?id=<?= (int) $o['id'] ?>" class="drv-order-card">
          <div class="drv-order-head">
            <div class="drv-order-when <?= $isToday ? 'is-today' : ($isPast ? 'is-overdue' : '') ?>">
              <?= htmlspecialchars($when) ?>
            </div>
            <div class="drv-order-status <?= $isToday ? 'status-today' : ($isPast ? 'status-overdue' : 'status-upcoming') ?>">
              <?= $isToday ? 'TODAY' : ($isPast ? 'OVERDUE' : 'UPCOMING') ?>
            </div>
          </div>
          <h3 class="drv-order-customer"><?= htmlspecialchars($o['full_name'] ?? 'Unknown') ?></h3>
          <p class="drv-order-address">📍 <?= htmlspecialchars($addr ?: 'No address') ?></p>
          <p class="drv-order-items">📦 <?= htmlspecialchars($o['items_summary'] ?? '—') ?></p>
          <div class="drv-order-foot">
            <span class="drv-order-total">R <?= number_format((float) $o['total_amount'], 2, '.', ' ') ?></span>
            <span class="drv-order-go">View →</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

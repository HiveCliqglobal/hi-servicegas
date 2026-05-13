<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_bootstrap.php';
driver_require_login();

$drv = current_driver();
$nav = 'profile';
$pageTitle = 'Profile · Hi-Service Driver';

// Lifetime + today stats
$stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE assigned_driver_id = :id AND status='delivered'");
$stmt->execute([':id' => $drv['id']]);
$lifetime = (int) $stmt->fetchColumn();

$pendingCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn();

$row = db()->prepare("SELECT vehicle_reg, last_login_at, email FROM drivers WHERE id = :id");
$row->execute([':id' => $drv['id']]);
$row = $row->fetch();

include __DIR__ . '/_header.php';
?>

<section class="drv-section">
  <div class="drv-profile-card">
    <div class="drv-profile-avatar" style="background:<?= htmlspecialchars($drv['avatar']) ?>">
      <?= htmlspecialchars(driver_initials($drv['name'])) ?>
    </div>
    <h2 style="margin:14px 0 4px"><?= htmlspecialchars($drv['name']) ?></h2>
    <p class="muted small" style="margin:0"><?= htmlspecialchars($drv['phone']) ?></p>
    <?php if (!empty($row['vehicle_reg'])): ?>
      <p class="muted small" style="margin:4px 0 0">🚚 <?= htmlspecialchars($row['vehicle_reg']) ?></p>
    <?php endif; ?>
  </div>

  <div class="drv-stats" style="margin-top:14px">
    <div class="drv-stat">
      <div class="drv-stat-icon" style="background:#dcfce7;color:#15803d">✅</div>
      <div>
        <div class="drv-stat-num"><?= $lifetime ?></div>
        <div class="drv-stat-lbl">Lifetime deliveries</div>
      </div>
    </div>
    <div class="drv-stat">
      <div class="drv-stat-icon" style="background:#fef3c7;color:#b45309">🚚</div>
      <div>
        <div class="drv-stat-num"><?= $pendingCount ?></div>
        <div class="drv-stat-lbl">Pending pool</div>
      </div>
    </div>
  </div>

  <div class="drv-card" style="margin-top:14px">
    <h3 style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Need help?</h3>
    <p style="margin:0 0 10px">If something's not working, call admin.</p>
    <a href="tel:+27636935532" class="btn btn-ghost btn-block">📞 Call 063 693 5532</a>
  </div>

  <a href="/driver/logout.php" class="btn btn-ghost btn-block" style="margin-top:14px">Sign out</a>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

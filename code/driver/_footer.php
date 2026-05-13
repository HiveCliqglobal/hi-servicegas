<?php
declare(strict_types=1);
$nav = $nav ?? 'today';
$pending = isset($pendingCount) ? (int) $pendingCount : null;
?>
</main>

<nav class="drv-nav">
  <div class="drv-nav-inner">
    <a href="/driver/today.php" class="drv-nav-item <?= $nav === 'today' ? 'active' : '' ?>" aria-label="Today">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      <span>Today</span>
      <?php if ($pending !== null && $pending > 0): ?>
        <span class="drv-nav-badge"><?= $pending ?></span>
      <?php endif; ?>
    </a>
    <a href="/driver/delivered.php" class="drv-nav-item <?= $nav === 'delivered' ? 'active' : '' ?>" aria-label="Delivered">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>Delivered</span>
    </a>
    <a href="/driver/profile.php" class="drv-nav-item <?= $nav === 'profile' ? 'active' : '' ?>" aria-label="Profile">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      <span>Profile</span>
    </a>
  </div>
</nav>

<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/driver/sw.js').catch(() => {}));
  }
</script>
</body>
</html>

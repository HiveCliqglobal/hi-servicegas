<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

// Demo mode toggle — ?demo=1 sets a 30-day cookie that flags orders as is_demo
if (isset($_GET['demo'])) {
    if ((string) $_GET['demo'] === '0') {
        setcookie('hs_demo', '', time() - 3600, '/', '', true, true);
    } else {
        setcookie('hs_demo', '1', time() + 30 * 86400, '/', '', true, true);
    }
    redirect('/shop/');
}
$demoMode = !empty($_COOKIE['hs_demo']);

$products = db()->query("SELECT * FROM products WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();

include __DIR__ . '/_header.php';
?>

<?php if ($demoMode): ?>
  <div style="background:#fef3c7;color:#92400e;padding:8px 16px;text-align:center;font-size:12.5px;border-bottom:1px solid #fde68a;font-weight:500">
    🧪 Demo mode active · orders placed here are flagged as demo · <a href="/shop/?demo=0" style="color:#92400e;font-weight:600">exit demo mode</a>
  </div>
<?php endif; ?>

<section class="shop-hero">
  <div class="container">
    <p class="kicker">Door-to-door LPG delivery · Helderberg & surrounds</p>
    <h1>Order Gas Online</h1>
    <p class="lead">Helderberg, Stellenbosch, Strand, Gordon's Bay, Pringle Bay, Betty's Bay, Kleinmond and surrounds. Order, pay and schedule your delivery in one go.</p>
    <div class="hero-actions">
      <a href="#products" class="btn btn-primary">Shop Gas</a>
      <a href="/shop/areas.php" class="btn btn-ghost">📍 View delivery areas</a>
      <a href="https://wa.me/27636935532" class="btn btn-ghost">💬 Order on WhatsApp</a>
    </div>
  </div>
</section>

<section class="shop-grid container" id="products">
  <h2 class="section-title">Available LPG Products</h2>
  <div class="product-grid">
    <?php foreach ($products as $p): ?>
      <article class="product-card">
        <div class="product-image">
          <?php if (!empty($p['image_url'])): ?>
            <img src="<?= h($p['image_url']) ?>" alt="<?= h($p['name']) ?>">
          <?php else: ?>
            <div class="product-image-placeholder">🛢️</div>
          <?php endif; ?>
        </div>
        <h3 class="product-name"><?= h($p['name']) ?></h3>
        <div class="product-price"><?= h(money($p['price'])) ?></div>
        <a href="/shop/identify.php" class="btn btn-primary btn-block">Order this</a>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if (empty($products)): ?>
    <div class="empty-state">
      <p>No products are loaded yet. Sync from Xero in the admin panel.</p>
    </div>
  <?php endif; ?>
</section>

<section class="container areas-cta">
  <div class="areas-cta-card">
    <div>
      <p class="kicker">Coverage</p>
      <h3>Not sure if we deliver to you?</h3>
      <p class="muted">17 suburbs across Helderberg, Overberg and Stellenbosch. Drop your postal code on the map page to check instantly.</p>
    </div>
    <a href="/shop/areas.php" class="btn btn-primary">📍 View delivery areas →</a>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

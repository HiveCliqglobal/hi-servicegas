<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/product_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$customerId = Cart::requireCustomer();
$customer   = CustomerRepo::findById($customerId);
if (!$customer) {
    Cart::clear();
    redirect('/shop/identify.php');
}

$products = ProductRepo::listActive();
$stockIssues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($products as $p) {
        $q = (int) ($_POST['qty'][$p['id']] ?? 0);
        if ($q > 0) {
            Cart::setItem((int) $p['id'], $q, (float) $p['price'], (string) $p['name']);
        } else {
            Cart::setItem((int) $p['id'], 0, 0, '');
        }
    }

    // ─── SHARED stock gate (matches WhatsApp behaviour exactly) ───
    // Validate the cart against products.in_stock_qty for any TRACKED items.
    // If any line exceeds available stock, hold the customer on this page with
    // a banner + an option to either reduce quantities OR request a callback
    // when stock arrives. Untracked items (services/deposits/refills/levies)
    // are always allowed. Hard-locked rule — see ProductRepo::checkCartStock().
    $cartLines = [];
    foreach (Cart::items() as $i) {
        $cartLines[] = [
            'product_id'   => (int) $i['product_id'],
            'qty'          => (int) $i['qty'],
            'product_name' => (string) $i['name'],
        ];
    }
    $stockIssues = ProductRepo::checkCartStock($cartLines);

    if (empty($stockIssues) && !Cart::isEmpty()) {
        redirect('/shop/address.php');
    }
    // If $stockIssues is non-empty, fall through and render the banner below.
}

$cartItems = [];
foreach (Cart::items() as $i) {
    $cartItems[(int) $i['product_id']] = (int) $i['qty'];
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container">
    <p class="kicker">Step 4 of 6 · Hi <?= h(explode(' ', $customer['full_name'])[0] ?? 'there') ?> 👋</p>
    <h1>Choose your gas</h1>
    <p class="lead">Tap + and − to set the quantity. Total updates live.</p>

    <?php if (!empty($stockIssues)): ?>
      <div class="alert alert-warning" style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #f97316;border-radius:8px;padding:14px 16px;margin:18px 0;color:#7c2d12">
        <h3 style="margin:0 0 8px;color:#9a3412;font-size:16px">😕 Limited stock on some items</h3>
        <ul style="margin:0 0 12px 18px;padding:0;font-size:14px">
          <?php foreach ($stockIssues as $i): ?>
            <li>
              <b><?= h($i['product_name']) ?></b> —
              you asked for <b><?= (int) $i['requested'] ?></b>,
              we currently have <b><?= (int) $i['available'] ?></b>
            </li>
          <?php endforeach; ?>
        </ul>
        <p style="margin:0 0 10px;font-size:14px">Two ways forward:</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <span class="muted small" style="align-self:center">↓ Reduce the quantity below and continue, OR ↓</span>
          <a href="/shop/callback.php" class="btn btn-ghost btn-sm" style="background:#fff;border:1px solid #f97316;color:#9a3412;text-decoration:none;padding:8px 14px;border-radius:6px;font-weight:600">📞 Leave my details — call me when it's back</a>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" id="browse-form">
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
            <div class="qty-stepper" data-price="<?= h((string) $p['price']) ?>">
              <button type="button" class="qty-btn qty-minus" aria-label="Decrease">−</button>
              <input type="number" name="qty[<?= (int) $p['id'] ?>]" min="0" max="20" value="<?= h((string) ($cartItems[$p['id']] ?? 0)) ?>" class="qty-input" inputmode="numeric">
              <button type="button" class="qty-btn qty-plus" aria-label="Increase">+</button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="sticky-total">
        <div class="container sticky-total-inner">
          <div>
            <div class="muted small">Order total</div>
            <div class="big-total" id="cart-total"><?= h(money(Cart::total())) ?></div>
          </div>
          <button type="submit" class="btn btn-primary" id="continue-btn" <?= Cart::isEmpty() ? 'disabled' : '' ?>>Continue →</button>
        </div>
      </div>
    </form>
  </div>
</section>

<script>
(function () {
  function rand() { /* no-op */ }
  function format(n) { return 'R ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
  function recalc() {
    let total = 0;
    document.querySelectorAll('.qty-stepper').forEach(function (st) {
      const price = parseFloat(st.dataset.price);
      const qty   = parseInt(st.querySelector('.qty-input').value, 10) || 0;
      total += price * qty;
    });
    document.getElementById('cart-total').textContent = format(total);
    document.getElementById('continue-btn').disabled = total <= 0;
  }
  document.querySelectorAll('.qty-stepper').forEach(function (st) {
    const input = st.querySelector('.qty-input');
    st.querySelector('.qty-minus').addEventListener('click', function () {
      input.value = Math.max(0, (parseInt(input.value, 10) || 0) - 1);
      recalc();
    });
    st.querySelector('.qty-plus').addEventListener('click', function () {
      input.value = Math.min(20, (parseInt(input.value, 10) || 0) + 1);
      recalc();
    });
    input.addEventListener('input', recalc);
  });
  recalc();
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>

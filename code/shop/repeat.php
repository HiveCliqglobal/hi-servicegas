<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$customerId = Cart::requireCustomer();
$customer   = CustomerRepo::findById($customerId);
if (!$customer) { Cart::clear(); redirect('/shop/identify.php'); }

// Find the most recent paid order for this customer
$stmt = db()->prepare(
    "SELECT * FROM orders WHERE customer_id = :id AND status IN ('paid','delivered')
     ORDER BY paid_at DESC, created_at DESC LIMIT 1"
);
$stmt->execute([':id' => $customerId]);
$lastOrder = $stmt->fetch();

// No prior order? go straight to browse
if (!$lastOrder) {
    redirect('/shop/browse.php');
}

$lastLines = OrderRepo::linesFor((int) $lastOrder['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = (string) ($_POST['choice'] ?? '');
    if ($choice === 'repeat') {
        // Pre-fill the cart with last order's lines
        foreach ($lastLines as $l) {
            Cart::setItem((int) $l['product_id'], (int) $l['qty'], (float) $l['unit_price'], $l['product_name']);
        }
        log_event('shop.repeat.accepted', 'customer', (string) $customerId, ['prior_order' => $lastOrder['order_reference']]);
        redirect('/shop/address.php');
    }
    if ($choice === 'new') {
        Cart::set(['items' => [], 'total' => 0]);
        redirect('/shop/browse.php');
    }
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Welcome back · <?= h(explode(' ', $customer['full_name'])[0] ?? '') ?> 👋</p>
    <h1>Repeat your last order?</h1>
    <p class="lead">Your last order was:</p>

    <div class="card">
      <ul class="review-items">
        <?php foreach ($lastLines as $l): ?>
          <li>
            <span><?= h((string) $l['qty']) ?> × <?= h($l['product_name']) ?></span>
            <strong><?= h(money($l['line_total'])) ?></strong>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="review-total"><span>Total</span><strong><?= h(money($lastOrder['total_amount'])) ?></strong></div>
      <p class="muted small" style="margin-top:10px">Placed <?= h(date('d M Y', strtotime($lastOrder['created_at']))) ?> · ref <?= h($lastOrder['order_reference']) ?></p>
    </div>

    <form method="post" class="step-form">
      <button type="submit" name="choice" value="repeat" class="btn btn-primary btn-block">1 · Repeat this order</button>
      <button type="submit" name="choice" value="new" class="btn btn-ghost btn-block">2 · Place a different order</button>
    </form>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

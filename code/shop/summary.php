<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$customerId = Cart::requireCustomer();
$cart       = Cart::get();

if (empty($cart['items']) || empty($cart['address_id']) || empty($cart['slot_id'])) {
    redirect('/shop/browse.php');
}

$customer = CustomerRepo::findById($customerId);
$slot     = SlotRepo::findById((int) $cart['slot_id']);
$addr     = null;
foreach (CustomerRepo::addressesFor($customerId) as $a) {
    if ((int) $a['id'] === (int) $cart['address_id']) { $addr = $a; break; }
}

if (!$customer || !$slot || !$addr) {
    redirect('/shop/browse.php');
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner-wide">
    <p class="kicker">Review</p>
    <h1>Confirm your order</h1>

    <div class="review-grid">
      <div class="card">
        <h3>Items</h3>
        <ul class="review-items">
          <?php foreach ($cart['items'] as $i): ?>
            <li>
              <span><?= h((string) $i['qty']) ?> × <?= h($i['name']) ?></span>
              <strong><?= h(money($i['line_total'])) ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="review-total">
          <span>Total</span>
          <strong><?= h(money($cart['total'])) ?></strong>
        </div>
      </div>

      <div class="card">
        <h3>Customer</h3>
        <p><?= h($customer['full_name']) ?></p>
        <p class="muted small">WhatsApp: <?= h($customer['phone']) ?></p>
        <p class="muted small">Email: <?= h($customer['email'] ?? '—') ?></p>

        <h3 style="margin-top:18px">Delivery address</h3>
        <p>
          <?= h(trim(($addr['line1'] ?? '') . ', ' . ($addr['line2'] ?? '') . ', ' . ($addr['city'] ?? '') . ', ' . ($addr['postal_code'] ?? ''), ', ')) ?>
        </p>

        <h3 style="margin-top:18px">Delivery time</h3>
        <p><?= h(SlotRepo::displayLabel($slot)) ?></p>
      </div>
    </div>

    <form method="post" action="/shop/pay.php" class="step-form">
      <button type="submit" class="btn btn-primary btn-block" style="font-size:17px">Pay <?= h(money($cart['total'])) ?> with PayFast →</button>
      <p class="muted small step-note">Payment expires in 24 hours. You'll get a PDF invoice on WhatsApp after payment.</p>
      <p class="muted small" style="text-align:center;margin-top:10px"><a href="/shop/browse.php">← Edit items</a> &nbsp;·&nbsp; <a href="/shop/slot.php">← Change slot</a></p>
    </form>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_once __DIR__ . '/../includes/customer_repo.php';

$ref      = (string) ($_GET['ref'] ?? '');
$order    = $ref ? OrderRepo::findByRef($ref) : null;
$customer = $order ? CustomerRepo::findById((int) $order['customer_id']) : null;
$slot     = $order && !empty($order['slot_id']) ? SlotRepo::findById((int) $order['slot_id']) : null;

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner-wide">
    <div style="text-align:center">
      <div class="success-badge">✓</div>
      <p class="kicker">Order confirmed</p>
      <h1>Thanks <?= h(explode(' ', $customer['full_name'] ?? '')[0] ?? 'for your order') ?>!</h1>
      <p class="lead">Your gas order is locked in. Dispatch will be in touch on the day. Your invoice and tracking link will follow on WhatsApp.</p>
    </div>

    <div class="success-grid">
      <div class="card">
        <h3>Order summary</h3>
        <?php if ($order): ?>
          <div class="kv"><span>Reference</span><b><?= h($order['order_reference']) ?></b></div>
          <?php if ($slot): ?>
            <div class="kv"><span>Delivery</span><b><?= h(SlotRepo::displayLabel($slot)) ?></b></div>
          <?php endif; ?>
          <div class="kv"><span>Total paid</span><b><?= h(money($order['total_amount'])) ?></b></div>
          <p style="margin-top:14px"><a href="/shop/receipt.php?ref=<?= h(urlencode($order['order_reference'])) ?>" class="btn btn-ghost" target="_blank">📄 View / print receipt</a></p>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>What happens next</h3>
        <ol class="next-steps">
          <li><b>Right now</b> — your slot is locked and our dispatch team is notified.</li>
          <li><b>Within 1 hour</b> — your VAT invoice will be WhatsApp'd to <?= h($customer['phone'] ?? 'you') ?> as a PDF.</li>
          <li><b>On delivery day</b> — our driver will WhatsApp you when they're 30 minutes out.</li>
          <li><b>After delivery</b> — you can re-order anytime by replying "Hi" on WhatsApp.</li>
        </ol>
      </div>
    </div>

    <p style="text-align:center;margin-top:30px">
      <a href="/shop/" class="btn btn-ghost">Back to shop</a>
      <a href="https://wa.me/27636935532" class="btn btn-primary">💬 Need help? WhatsApp us</a>
    </p>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

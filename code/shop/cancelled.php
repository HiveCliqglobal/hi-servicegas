<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';

$ref   = (string) ($_GET['ref'] ?? '');
$order = $ref ? OrderRepo::findByRef($ref) : null;

if ($order && $order['status'] === 'pending_payment') {
    OrderRepo::setStatus((int) $order['id'], 'cancelled');
    if (!empty($order['slot_id'])) SlotRepo::release((int) $order['slot_id']);
    log_event('shop.payment_cancelled', 'order', $order['order_reference']);
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Cancelled</p>
    <h1>Payment cancelled</h1>
    <p class="lead">No payment was taken. Your slot has been released. Feel free to try again or chat to us if you got stuck.</p>

    <p style="text-align:center;margin-top:24px">
      <a href="/shop/" class="btn btn-primary">Back to shop</a>
      <a href="https://wa.me/27636935532" class="btn btn-ghost">WhatsApp us</a>
    </p>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

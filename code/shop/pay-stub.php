<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/cart.php';

// Stand-in payment page used until PayFast LIVE keys are configured.
// Lets us complete the flow end-to-end in dev without real money.

$ref = (string) ($_GET['ref'] ?? '');
$order = $ref ? OrderRepo::findByRef($ref) : null;
if (!$order) {
    redirect('/shop/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'simulate_paid') {
    OrderRepo::markPaid((int) $order['id'], 'STUB-' . time());
    log_event('shop.pay.stub_completed', 'order', $order['order_reference']);

    // Mirror to GHL (best-effort)
    if (env('GHL_PRIVATE_TOKEN')) {
        try {
            require_once __DIR__ . '/../includes/ghl.php';
            require_once __DIR__ . '/../includes/customer_repo.php';
            $cust = CustomerRepo::findById((int) $order['customer_id']);
            GHL::syncPaidOrder($order, $cust);
            log_event('shop.pay.ghl_synced', 'order', $order['order_reference']);
        } catch (Throwable $e) {
            log_to_file('shop', 'ghl paid sync failed', ['err' => $e->getMessage()]);
        }
    }

    Cart::clear();
    redirect('/shop/success.php?ref=' . urlencode($order['order_reference']));
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Payment (dev stub — PayFast not yet wired)</p>
    <h1>Pay <?= h(money($order['total_amount'])) ?></h1>
    <p class="lead">PayFast LIVE keys will replace this page in Stage 3 day 7. For now we can simulate a successful payment to test the end-to-end flow.</p>

    <div class="card">
      <p><strong>Order:</strong> <?= h($order['order_reference']) ?></p>
      <p><strong>Amount:</strong> <?= h(money($order['total_amount'])) ?></p>
    </div>

    <form method="post" class="step-form">
      <input type="hidden" name="action" value="simulate_paid">
      <button type="submit" class="btn btn-primary btn-block">Simulate successful payment</button>
      <p class="muted small step-note">In production this button is replaced by PayFast's checkout. The success page + invoice + WhatsApp PDF are wired by the same code path on ITN.</p>
    </form>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

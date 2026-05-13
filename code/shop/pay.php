<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$customerId = Cart::requireCustomer();
$cart       = Cart::get();

if (empty($cart['items']) || empty($cart['address_id']) || empty($cart['slot_id'])) {
    redirect('/shop/browse.php');
}

// 1. Allocate the slot atomically — if full, bounce back
if (!SlotRepo::allocate((int) $cart['slot_id'])) {
    log_event('shop.slot.allocation_failed', 'slot', (string) $cart['slot_id']);
    Cart::set(['slot_id' => null]);
    $_SESSION['flash_error'] = 'Sorry, that slot just filled up. Please pick another.';
    redirect('/shop/slot.php');
}

// 2. Persist the order
$orderId = OrderRepo::createCart($customerId, 'web');
OrderRepo::setAddress($orderId, (int) $cart['address_id']);
OrderRepo::setSlot($orderId, (int) $cart['slot_id']);

$lines = [];
foreach ($cart['items'] as $i) {
    $lines[] = [
        'product_id'   => (int) $i['product_id'],
        'product_name' => $i['name'],
        'qty'          => (int) $i['qty'],
        'unit_price'   => (float) $i['unit_price'],
        'line_total'   => (float) $i['line_total'],
    ];
}
$total = OrderRepo::replaceLines($orderId, $lines);
OrderRepo::setStatus($orderId, 'pending_payment');

Cart::set(['order_id' => $orderId, 'total' => $total]);
$order = OrderRepo::findById($orderId);

log_event('shop.pay.redirect', 'order', $order['order_reference'], ['total' => $total]);

// 3. Generate PayFast URL — STUB until Stage 3 day 7
// For now, we redirect to a stand-in /shop/pay-stub.php that simulates success.
// When PayFast is wired, this whole block is replaced by payfast_build_pay_link().
$payfastReady = !empty(env('PAYFAST_MERCHANT_ID')) && !empty(env('PAYFAST_MERCHANT_KEY'));

if ($payfastReady) {
    require_once __DIR__ . '/../includes/payfast.php';
    $url = payfast_build_pay_link([
        'order_reference' => $order['order_reference'],
        'order_total'     => $total,
        'customer_name'   => trim($order['full_name'] ?? ''),
        'customer_email'  => '',  // load from customer
        'customer_phone'  => '',
    ]);
    redirect($url);
}

// FALLBACK while PayFast keys are not yet provided
redirect('/shop/pay-stub.php?ref=' . urlencode($order['order_reference']));

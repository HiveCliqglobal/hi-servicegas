<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_login();

$id    = (int) ($_GET['id'] ?? 0);
$order = OrderRepo::findById($id);
if (!$order) {
    http_response_code(404);
    include __DIR__ . '/_header.php';
    echo '<div class="card"><h2>Order not found</h2><p><a href="/admin/orders.php">← Back to orders</a></p></div>';
    include __DIR__ . '/_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_delivered') {
        db()->prepare('UPDATE orders SET status="delivered", delivered_at=NOW() WHERE id=:id')->execute([':id' => $id]);
        log_event('admin.order.delivered', 'order', $order['order_reference']);
        redirect('/admin/order-detail.php?id=' . $id);
    }
    if ($action === 'cancel') {
        OrderRepo::setStatus($id, 'cancelled');
        if (!empty($order['slot_id'])) SlotRepo::release((int) $order['slot_id']);
        log_event('admin.order.cancelled', 'order', $order['order_reference']);
        redirect('/admin/order-detail.php?id=' . $id);
    }
}

$customer = CustomerRepo::findById((int) $order['customer_id']);
$slot     = $order['slot_id'] ? SlotRepo::findById((int) $order['slot_id']) : null;
$lines    = OrderRepo::linesFor($id);

// Find the address used
$addr = null;
if ($order['address_id']) {
    $stmt = db()->prepare('SELECT * FROM addresses WHERE id = :id');
    $stmt->execute([':id' => $order['address_id']]);
    $addr = $stmt->fetch();
}

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<div class="page-head">
  <p class="muted small"><a href="/admin/orders.php">← Back to orders</a></p>
  <h1>Order <?= h($order['order_reference']) ?></h1>
  <p>
    <span class="status-badge status-<?= h($order['status']) ?>"><?= h(str_replace('_', ' ', $order['status'])) ?></span>
    <span class="chan chan-<?= h($order['channel']) ?>"><?= h($order['channel']) ?></span>
    <span class="muted small">· <?= h(date('d M Y · H:i', strtotime($order['created_at']))) ?></span>
  </p>
</div>

<div class="detail-grid">
  <div class="card">
    <h2>Items</h2>
    <table class="data-table thin">
      <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
      <tbody>
        <?php foreach ($lines as $l): ?>
          <tr>
            <td><?= h($l['product_name']) ?></td>
            <td><?= (int) $l['qty'] ?></td>
            <td><?= h(money($l['unit_price'])) ?></td>
            <td><b><?= h(money($l['line_total'])) ?></b></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right"><b>Order total</b></td><td><b><?= h(money($order['total_amount'])) ?></b></td></tr>
      </tfoot>
    </table>

    <div class="action-row">
      <a href="/shop/receipt.php?ref=<?= h(urlencode($order['order_reference'])) ?>" target="_blank" class="btn btn-ghost">📄 View receipt</a>
      <?php if ($order['status'] === 'paid'): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="mark_delivered">
          <button type="submit" class="btn btn-primary">✅ Mark delivered</button>
        </form>
      <?php endif; ?>
      <?php if (!in_array($order['status'], ['cancelled', 'delivered'], true)): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Cancel order? Slot will be released.')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn btn-ghost danger">✕ Cancel order</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>Customer</h2>
    <?php if ($customer): ?>
      <p><b><?= h($customer['full_name']) ?></b></p>
      <p class="muted small">WhatsApp: <?= h($customer['phone']) ?></p>
      <p class="muted small">Email: <?= h($customer['email'] ?? '—') ?></p>
    <?php endif; ?>

    <h3 style="margin-top:18px">Delivery address</h3>
    <?php if ($addr): ?>
      <p>
        <?= h(implode(', ', array_filter([
          $addr['line1'] ?? null, $addr['line2'] ?? null,
          $addr['city']  ?? null, $addr['postal_code'] ?? null,
        ]))) ?>
      </p>
    <?php else: ?>
      <p class="muted">— no address —</p>
    <?php endif; ?>

    <h3 style="margin-top:18px">Delivery slot</h3>
    <p><?= $slot ? h(SlotRepo::displayLabel($slot)) : '<span class="muted">—</span>' ?></p>

    <h3 style="margin-top:18px">Payment</h3>
    <p>
      <?php if ($order['payfast_payment_id']): ?>
        PayFast ref: <code><?= h($order['payfast_payment_id']) ?></code>
        <span class="muted small">— paid <?= h($order['paid_at'] ?? '—') ?></span>
      <?php else: ?>
        <span class="muted">No payment recorded</span>
      <?php endif; ?>
    </p>

    <h3 style="margin-top:18px">Xero invoice</h3>
    <p>
      <?php if ($order['xero_invoice_number']): ?>
        <?= h($order['xero_invoice_number']) ?>
      <?php else: ?>
        <span class="muted">— pending (Xero not yet connected) —</span>
      <?php endif; ?>
    </p>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>

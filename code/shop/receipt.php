<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/order_repo.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/slot_repo.php';

$ref   = (string) ($_GET['ref'] ?? '');
$order = $ref ? OrderRepo::findByRef($ref) : null;

if (!$order) {
    http_response_code(404);
    echo '<p>Receipt not found. <a href="/shop/">Back to shop</a>.</p>';
    exit;
}

$customer = CustomerRepo::findById((int) $order['customer_id']);
$slot     = $order['slot_id'] ? SlotRepo::findById((int) $order['slot_id']) : null;
$lines    = OrderRepo::linesFor((int) $order['id']);
$addr     = null;
if ($order['address_id']) {
    $stmt = db()->prepare('SELECT * FROM addresses WHERE id = :id');
    $stmt->execute([':id' => $order['address_id']]);
    $addr = $stmt->fetch();
}

$subtotal = (float) $order['total_amount'] / 1.15;
$vat      = (float) $order['total_amount'] - $subtotal;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt · <?= h($order['order_reference']) ?> · Hi-Service Gas</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body{background:#eee;padding:24px 0}
  .receipt{max-width:780px;margin:0 auto;background:#fff;padding:48px 56px;box-shadow:0 6px 26px rgba(0,0,0,.1)}
  .r-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid var(--red);padding-bottom:24px;margin-bottom:24px}
  .r-head img{height:48px}
  .r-head .r-title{text-align:right}
  .r-title h1{margin:0;font-size:32px;color:var(--black);letter-spacing:-.02em}
  .r-meta{display:grid;grid-template-columns:1fr 1fr;gap:36px;margin:18px 0 28px;font-size:14px}
  .r-meta h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--grey);margin:0 0 6px}
  .r-meta p{margin:0;line-height:1.5}
  .r-table{width:100%;border-collapse:collapse;margin-bottom:22px;font-size:14px}
  .r-table th{text-align:left;background:#fafafa;padding:10px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--grey);border-bottom:1px solid var(--line)}
  .r-table td{padding:12px;border-bottom:1px solid var(--line);vertical-align:top}
  .r-table .num{text-align:right}
  .r-totals{margin-left:auto;width:280px;font-size:14px}
  .r-totals .line{display:flex;justify-content:space-between;padding:6px 0}
  .r-totals .total{border-top:2px solid var(--ink);margin-top:6px;padding-top:10px;font-weight:800;font-size:18px;color:var(--black)}
  .r-foot{margin-top:32px;padding-top:18px;border-top:1px solid var(--line);font-size:12px;color:var(--grey);line-height:1.6}
  .r-status{display:inline-block;padding:4px 10px;border-radius:11px;font-weight:700;font-size:11px;letter-spacing:.08em;text-transform:uppercase}
  .r-status.paid{background:#e8f5e9;color:#1b5e20}
  .r-status.pending{background:#fff3e0;color:#bf6700}
  .r-status.cancelled{background:#fdecea;color:#7a1c1c}
  .r-actions{max-width:780px;margin:0 auto 24px;display:flex;justify-content:space-between;gap:10px;padding:0 16px}
  .r-actions .btn{font-size:13px;padding:9px 18px}
  @media print{
    body{background:#fff;padding:0}
    .receipt{box-shadow:none;padding:18mm 16mm}
    .r-actions{display:none}
  }
</style>
</head>
<body>

<div class="r-actions">
  <a href="/shop/" class="btn btn-ghost">← Back to shop</a>
  <button onclick="window.print()" class="btn btn-primary">🖨️ Print / Save as PDF</button>
</div>

<div class="receipt">
  <header class="r-head">
    <div>
      <img src="/assets/img/hi-service-logo.png" alt="Hi-Service Gas">
      <div class="muted small" style="margin-top:8px">16 Rankine Street, Strand, 7140<br>063 693 5532 · gas@hiservice.co.za</div>
    </div>
    <div class="r-title">
      <h1>RECEIPT</h1>
      <p class="muted small"><?= h($order['order_reference']) ?></p>
      <p style="margin-top:6px">
        <?php
          $st = $order['status'];
          if ($st === 'paid' || $st === 'delivered') echo '<span class="r-status paid">Paid</span>';
          elseif (in_array($st, ['cart','pending_payment'], true)) echo '<span class="r-status pending">' . h(str_replace('_', ' ', $st)) . '</span>';
          else echo '<span class="r-status cancelled">' . h($st) . '</span>';
        ?>
      </p>
    </div>
  </header>

  <div class="r-meta">
    <div>
      <h4>Bill to</h4>
      <p><b><?= h($customer['full_name'] ?? '—') ?></b></p>
      <?php if ($customer): ?>
        <p class="muted"><?= h($customer['phone']) ?></p>
        <p class="muted"><?= h($customer['email'] ?? '') ?></p>
      <?php endif; ?>
    </div>
    <div>
      <h4>Deliver to</h4>
      <?php if ($addr): ?>
        <p><?= h(implode(', ', array_filter([$addr['line1'] ?? null, $addr['line2'] ?? null, $addr['city'] ?? null, $addr['postal_code'] ?? null]))) ?></p>
      <?php else: ?>
        <p class="muted">—</p>
      <?php endif; ?>
      <?php if ($slot): ?>
        <p class="muted" style="margin-top:8px"><b>Delivery:</b> <?= h(SlotRepo::displayLabel($slot)) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <table class="r-table">
    <thead>
      <tr>
        <th>Description</th>
        <th style="width:80px" class="num">Qty</th>
        <th style="width:120px" class="num">Unit price</th>
        <th style="width:120px" class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($lines as $l): ?>
        <tr>
          <td><?= h($l['product_name']) ?></td>
          <td class="num"><?= (int) $l['qty'] ?></td>
          <td class="num"><?= h(money($l['unit_price'])) ?></td>
          <td class="num"><?= h(money($l['line_total'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="r-totals">
    <div class="line"><span>Subtotal (excl. VAT)</span><span><?= h(money($subtotal)) ?></span></div>
    <div class="line"><span>VAT 15%</span><span><?= h(money($vat)) ?></span></div>
    <div class="line total"><span>Total</span><span><?= h(money($order['total_amount'])) ?></span></div>
  </div>

  <footer class="r-foot">
    <p><b>Hi-Service Gas (Pty) Ltd</b> · 16 Rankine Street, Strand, Western Cape, 7140 · 063 693 5532 · gas@hiservice.co.za</p>
    <p style="margin-top:6px">Thank you for your order. Our dispatch team has been notified of your delivery booking. For any questions reply on WhatsApp to <a href="https://wa.me/27636935532">063 693 5532</a>.</p>
    <?php if (!$order['xero_invoice_number']): ?>
      <p style="margin-top:14px;color:#bf6700"><b>Note:</b> This is an order receipt. Your VAT invoice from Xero will follow once accounting is connected.</p>
    <?php endif; ?>
  </footer>
</div>

</body>
</html>

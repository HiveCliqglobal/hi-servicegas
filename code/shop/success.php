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
      <p class="lead">Your gas order is locked in. Your <b>VAT invoice from Xero</b> is on its way — download it below or check your WhatsApp.</p>
    </div>

    <?php
      // VAT invoice PDF — populated by api/webhook/payfast-itn.php after PayFast ITN
      // fires (usually within 2-5 sec of payment). If not yet ready, show a placeholder
      // and auto-refresh up to 5x so the customer doesn't have to manually reload.
      $pdfUrl = $order ? (string) ($order['xero_invoice_pdf_url'] ?? '') : '';
    ?>

    <div class="success-grid">
      <div class="card">
        <h3>Order summary</h3>
        <?php if ($order): ?>
          <div class="kv"><span>Reference</span><b><?= h($order['order_reference']) ?></b></div>
          <?php if ($slot): ?>
            <div class="kv"><span>Delivery</span><b><?= h(SlotRepo::displayLabel($slot)) ?></b></div>
          <?php endif; ?>
          <div class="kv"><span>Total paid</span><b><?= h(money($order['total_amount'])) ?></b></div>

          <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
            <?php if ($pdfUrl !== ''): ?>
              <a href="<?= h($pdfUrl) ?>" class="btn btn-primary" download="Hi-Service-Invoice-<?= h($order['order_reference']) ?>.pdf">📄 Download VAT invoice</a>
            <?php else: ?>
              <span id="pdf-pending" class="muted small" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 12px;color:#9a3412">⏳ Generating your VAT invoice in Xero…</span>
            <?php endif; ?>
            <a href="/shop/receipt.php?ref=<?= h(urlencode($order['order_reference'])) ?>" class="btn btn-ghost" target="_blank">📋 View receipt</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3>What happens next</h3>
        <ol class="next-steps">
          <li><b>Right now</b> — your slot is locked and our dispatch team is notified.</li>
          <li><b>Within seconds</b> — your VAT invoice PDF is generated in Xero<?= ($order && $order['channel'] === 'whatsapp') ? ' and sent to your WhatsApp' : ' and available above' ?>.</li>
          <li><b>On delivery day</b> — our driver will WhatsApp you when they're 30 minutes out.</li>
          <li><b>After delivery</b> — you can re-order anytime by replying "Hi" on WhatsApp.</li>
        </ol>
      </div>
    </div>

    <?php if ($order && $pdfUrl === ''): ?>
      <script>
        // Auto-refresh up to 5× every 4 seconds until the PDF link appears.
        (function () {
          var tries    = parseInt(sessionStorage.getItem('pdfTries') || '0', 10);
          var maxTries = 5;
          if (tries >= maxTries) {
            var p = document.getElementById('pdf-pending');
            if (p) p.innerHTML = 'Invoice still being generated. Refresh the page in a moment, or check your WhatsApp.';
            sessionStorage.removeItem('pdfTries');
            return;
          }
          sessionStorage.setItem('pdfTries', String(tries + 1));
          setTimeout(function () { window.location.reload(); }, 4000);
        })();
      </script>
    <?php else: ?>
      <script>sessionStorage.removeItem('pdfTries');</script>
    <?php endif; ?>

    <p style="text-align:center;margin-top:30px">
      <a href="/shop/" class="btn btn-ghost">Back to shop</a>
      <a href="https://wa.me/27636935532" class="btn btn-primary">💬 Need help? WhatsApp us</a>
    </p>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

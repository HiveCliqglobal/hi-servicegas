<?php
/**
 * shop/callback.php — web-shop equivalent of the WhatsApp out-of-stock
 * callback request flow.
 *
 * Customer clicked "Leave my details" on browse.php because one of their
 * desired items was out of stock. We:
 *   1. Collect their name (phone we already have from cart customer)
 *   2. Log the lead
 *   3. Tag in GHL + notify USER_GAS
 *   4. Thank them + offer to keep browsing
 *
 * Mirrors Conversation::actLogCallbackLead() — same admin notification
 * shape so staff get the same context regardless of which channel the
 * customer used.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/product_repo.php';
require_once __DIR__ . '/../includes/cart.php';
require_once __DIR__ . '/../includes/ghl.php';

Cart::init();
$customerId = Cart::requireCustomer();
$customer   = CustomerRepo::findById($customerId);
if (!$customer) {
    Cart::clear();
    redirect('/shop/identify.php');
}

// Reconstruct the stock-issue context from the current cart so the admin
// notification carries the exact items the customer was hoping to buy.
$cartLines = [];
foreach (Cart::items() as $i) {
    $cartLines[] = [
        'product_id'   => (int) $i['product_id'],
        'qty'          => (int) $i['qty'],
        'product_name' => (string) $i['name'],
    ];
}
$stockIssues = ProductRepo::checkCartStock($cartLines);

$success = false;
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
    $name = trim((string) ($_POST['name'] ?? '')) ?: trim((string) ($customer['full_name'] ?? ''));

    if ($name === '' || mb_strlen($name) < 2) {
        $errors[] = 'Please tell us your name so we know who to call.';
    } else {
        try {
            // Update customer record with provided name if blank
            if (empty($customer['full_name'])) {
                CustomerRepo::update((int) $customer['id'], ['full_name' => $name]);
            }

            $summaryLines = [];
            foreach ($stockIssues as $l) {
                $summaryLines[] = "{$l['requested']} × {$l['product_name']} (only {$l['available']} in stock)";
            }
            $oosSummary = implode("\n  - ", $summaryLines) ?: '(no items captured)';

            log_event('shop.lead.callback_requested', 'customer', (string) $customer['id'], [
                'phone'         => $customer['phone'],
                'name'          => $name,
                'out_of_stock'  => $stockIssues,
                'channel'       => 'web',
            ]);

            // Push to GHL: tag + internal notification to the gas team
            $cust = CustomerRepo::findById((int) $customer['id']);
            $gid  = GHL::syncCustomer($cust);
            if ($gid) {
                GHL::addTag($gid, ['callback-requested', 'out-of-stock', 'web-shop']);
                GHL::notifyUser(
                    GHL::USER_GAS,
                    "Callback requested — stock issue (web shop)",
                    "Customer: *{$name}* ({$customer['phone']})\n\nWanted but out of stock:\n  - {$oosSummary}\n\nReach out as soon as stock lands. They came via the web shop, not WhatsApp."
                );
            }

            // Clear the cart — the order can't be fulfilled, customer is now a lead
            Cart::clear();
            $success = true;
        } catch (Throwable $e) {
            log_to_file('shop', 'callback lead failed', ['err' => $e->getMessage()]);
            $errors[] = 'Something went wrong saving your details. Please WhatsApp 063 693 5532 or call 021 492 8515.';
        }
    }
}

$csrf = csrf_token();
include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container" style="max-width:640px">

    <?php if ($success): ?>
      <div style="text-align:center;padding:40px 20px">
        <div style="font-size:72px;line-height:1;margin-bottom:18px">✅</div>
        <h1>Thanks, we'll be in touch</h1>
        <p class="lead">Our team has been notified and will WhatsApp or call you on
          <b><?= h($customer['phone']) ?></b> as soon as stock arrives.</p>
        <div style="margin-top:30px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <a href="/shop/browse.php" class="btn btn-ghost">← Browse other products</a>
          <a href="/" class="btn btn-primary">Back to home</a>
        </div>
      </div>

    <?php else: ?>
      <p class="kicker">📞 Callback request</p>
      <h1>We're out of stock right now</h1>
      <p class="lead">No problem — leave your name and our team will reach out to <b><?= h($customer['phone']) ?></b> as soon as we restock.</p>

      <?php if (!empty($stockIssues)): ?>
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:14px 16px;margin:18px 0">
          <p style="margin:0 0 6px;font-weight:600;color:#9a3412">Items you wanted:</p>
          <ul style="margin:0 0 0 18px;padding:0;color:#7c2d12;font-size:14px">
            <?php foreach ($stockIssues as $i): ?>
              <li><b><?= h($i['product_name']) ?></b> — wanted <?= (int) $i['requested'] ?>, only <?= (int) $i['available'] ?> in stock</li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php foreach ($errors as $e): ?>
        <div class="alert alert-error" style="background:#fef2f2;color:#7f1d1d;border:1px solid #fecaca;padding:12px 14px;border-radius:6px;margin:14px 0"><?= h($e) ?></div>
      <?php endforeach; ?>

      <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>
          <span>Your name <small>*</small></span>
          <input type="text" name="name" value="<?= h((string) ($customer['full_name'] ?? '')) ?>" required autofocus>
        </label>
        <div style="margin-top:14px;display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap">
          <a href="/shop/browse.php" class="btn btn-ghost">← Back to products</a>
          <button type="submit" class="btn btn-primary">Notify me when in stock →</button>
        </div>
      </form>

      <p class="muted small" style="margin-top:18px">Phone we'll use: <b><?= h($customer['phone']) ?></b><br>
      Office hours: Mon-Fri 08:00-17:00 · Sat 08:00-13:00 · Direct: 021 492 8515</p>
    <?php endif; ?>

  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

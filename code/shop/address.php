<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$customerId = Cart::requireCustomer();

if (Cart::isEmpty()) redirect('/shop/browse.php');

$customer  = CustomerRepo::findById($customerId);
$addresses = CustomerRepo::addressesFor($customerId);
$default   = $addresses[0] ?? null;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choice = (string) ($_POST['choice'] ?? 'same');

    if ($choice === 'same' && $default) {
        Cart::set(['address_id' => (int) $default['id']]);
        redirect('/shop/slot.php');
    }

    if ($choice === 'different') {
        $line1 = trim((string) ($_POST['line1'] ?? ''));
        $line2 = trim((string) ($_POST['line2'] ?? ''));
        $city  = trim((string) ($_POST['city'] ?? ''));
        $code  = trim((string) ($_POST['postal_code'] ?? ''));
        if (strlen($line1) < 4 || $city === '' || !preg_match('/^\d{4}$/', $code)) {
            $error = 'Please complete the new address with a valid 4-digit postal code.';
        } elseif (!CustomerRepo::postalCodeInZone($code)) {
            $error = 'Sadly that postal code is outside our delivery area.';
        } else {
            $aid = CustomerRepo::addAddress($customerId, [
                'line1' => $line1, 'line2' => $line2 ?: null, 'city' => $city,
                'postal_code' => $code, 'is_default' => true,
            ]);
            Cart::set(['address_id' => $aid]);
            redirect('/shop/slot.php');
        }
    }
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner-wide">
    <p class="kicker">Step 5 of 6</p>
    <h1>Where should we deliver?</h1>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="step-form" id="addr-form">
      <?php if ($default): ?>
        <label class="addr-option">
          <input type="radio" name="choice" value="same" checked>
          <div>
            <strong>Use my saved address</strong>
            <div class="muted small">
              <?= h(trim(($default['line1'] ?? '') . ', ' . ($default['line2'] ?? '') . ', ' . ($default['city'] ?? '') . ', ' . ($default['postal_code'] ?? ''), ', ')) ?>
            </div>
          </div>
        </label>
      <?php endif; ?>

      <label class="addr-option">
        <input type="radio" name="choice" value="different" <?= $default ? '' : 'checked' ?>>
        <div>
          <strong>Use a different address</strong>
          <div class="muted small">Once-off delivery to a new location</div>
        </div>
      </label>

      <div id="diff-fields" class="addr-fields">
        <label><span>Street address</span>
          <input type="text" name="line1" placeholder="31 Example Road" value="<?= h($_POST['line1'] ?? '') ?>"></label>
        <label><span>Suburb / line 2</span>
          <input type="text" name="line2" value="<?= h($_POST['line2'] ?? '') ?>"></label>
        <label><span>City</span>
          <input type="text" name="city" value="<?= h($_POST['city'] ?? 'Strand') ?>"></label>
        <label><span>Postal code</span>
          <input type="text" name="postal_code" inputmode="numeric" maxlength="4" value="<?= h($_POST['postal_code'] ?? '') ?>"></label>
      </div>

      <button type="submit" class="btn btn-primary btn-block">Continue to delivery time</button>
    </form>
  </div>
</section>

<script>
(function () {
  const radios = document.querySelectorAll('input[name="choice"]');
  const diff   = document.getElementById('diff-fields');
  function toggle() {
    const v = document.querySelector('input[name="choice"]:checked').value;
    diff.style.display = v === 'different' ? 'grid' : 'none';
  }
  radios.forEach(r => r.addEventListener('change', toggle));
  toggle();
})();
</script>

<?php include __DIR__ . '/_footer.php'; ?>

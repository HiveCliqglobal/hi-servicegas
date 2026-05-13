<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();

if (empty($_SESSION['hs_signup']['phone']) || empty($_SESSION['hs_signup']['postal_code'])) {
    redirect('/shop/identify.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim((string) ($_POST['name'] ?? ''));
    $line1 = trim((string) ($_POST['line1'] ?? ''));
    $line2 = trim((string) ($_POST['line2'] ?? ''));
    $city  = trim((string) ($_POST['city'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? $_SESSION['hs_signup']['email'] ?? '')));

    $nameParts = preg_split('/\s+/', $name) ?: [];
    if (count($nameParts) < 2 || strlen($name) < 3) {
        $error = 'Please enter your full name (first and surname).';
    } elseif (strlen($line1) < 4) {
        $error = 'Street address looks too short — please include the number.';
    } elseif ($city === '') {
        $error = 'City is required.';
    } else {
        $customerId = CustomerRepo::create([
            'phone'     => $_SESSION['hs_signup']['phone'],
            'full_name' => $name,
            'email'     => $email ?: null,
        ]);
        $addressId = CustomerRepo::addAddress($customerId, [
            'line1'       => $line1,
            'line2'       => $line2 ?: null,
            'city'        => $city,
            'postal_code' => $_SESSION['hs_signup']['postal_code'],
            'is_default'  => true,
        ]);
        log_event('shop.customer.created', 'customer', (string) $customerId, ['phone' => $_SESSION['hs_signup']['phone']]);

        // Mirror to GHL (best-effort — never block the customer)
        if (env('GHL_PRIVATE_TOKEN')) {
            try {
                require_once __DIR__ . '/../includes/ghl.php';
                $customer = CustomerRepo::findById($customerId);
                $address  = CustomerRepo::defaultAddress($customerId);
                $gid = GHL::syncCustomer($customer, $address);
                if ($gid) {
                    CustomerRepo::update($customerId, ['xero_contact_id' => null]);
                    db()->prepare('UPDATE customers SET notes = CONCAT(IFNULL(notes,""), :n) WHERE id = :id')
                        ->execute([':n' => "\n[GHL] {$gid}", ':id' => $customerId]);
                    log_event('shop.customer.ghl_synced', 'customer', (string) $customerId, ['ghl_id' => $gid]);
                }
            } catch (Throwable $e) {
                log_to_file('shop', 'ghl sync failed', ['err' => $e->getMessage(), 'customer_id' => $customerId]);
            }
        }

        Cart::set(['customer_id' => $customerId]);
        unset($_SESSION['hs_signup']);
        redirect('/shop/browse.php');
    }
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Step 3 of 6</p>
    <h1>A few quick details</h1>
    <p class="lead">We need these to create your account and invoice. Stored securely — never shared.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="step-form">
      <label>
        <span>Full name and surname</span>
        <input type="text" name="name" required autofocus value="<?= h($_POST['name'] ?? '') ?>">
      </label>
      <label>
        <span>Street address (line 1)</span>
        <input type="text" name="line1" required placeholder="31 Example Road" value="<?= h($_POST['line1'] ?? '') ?>">
      </label>
      <label>
        <span>Suburb / line 2 <span class="muted">(optional)</span></span>
        <input type="text" name="line2" value="<?= h($_POST['line2'] ?? '') ?>">
      </label>
      <label>
        <span>City</span>
        <input type="text" name="city" required value="<?= h($_POST['city'] ?? 'Strand') ?>">
      </label>
      <label>
        <span>Email</span>
        <input type="email" name="email" required placeholder="you@example.com" value="<?= h($_SESSION['hs_signup']['email'] ?? '') ?>">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Save and continue</button>
    </form>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

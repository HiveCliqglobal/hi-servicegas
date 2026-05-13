<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = normalize_phone((string) ($_POST['phone'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (strlen($phone) < 10) {
        $error = 'Please enter a valid mobile number (e.g. 0848580000).';
    } else {
        $customer = CustomerRepo::findByPhone($phone);
        if (!$customer && $email !== '') {
            $customer = CustomerRepo::findByEmail($email);
        }

        if ($customer && ($customer['status'] ?? '') === 'archived') {
            redirect('/shop/archived.php');
        }

        if ($customer) {
            // Returning customer — check for a prior paid order
            Cart::set(['customer_id' => (int) $customer['id']]);
            log_event('shop.identify.returning', 'customer', (string) $customer['id'], ['phone' => $phone]);

            $stmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = :id AND status IN ('paid','delivered')");
            $stmt->execute([':id' => $customer['id']]);
            $hasPriorOrder = ((int) $stmt->fetchColumn()) > 0;

            redirect($hasPriorOrder ? '/shop/repeat.php' : '/shop/browse.php');
        } else {
            // New customer — postal code check first
            $_SESSION['hs_signup'] = ['phone' => $phone, 'email' => $email];
            log_event('shop.identify.new', null, null, ['phone' => $phone]);
            redirect('/shop/postal-code.php');
        }
    }
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Step 1 of 6</p>
    <h1>Let's get started</h1>
    <p class="lead">Enter your WhatsApp number and we'll find your account or get you set up in 30 seconds.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" class="step-form">
      <label>
        <span>WhatsApp number</span>
        <input type="tel" name="phone" placeholder="084 858 0000" required autofocus value="<?= h($_POST['phone'] ?? '') ?>">
      </label>
      <label>
        <span>Email <span class="muted">(optional)</span></span>
        <input type="email" name="email" placeholder="you@example.com" value="<?= h($_POST['email'] ?? '') ?>">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Continue</button>
      <p class="muted small step-note">By continuing you agree we may WhatsApp you about your order.</p>
    </form>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

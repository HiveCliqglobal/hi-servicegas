<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/customer_repo.php';
require_once __DIR__ . '/../includes/cart.php';

Cart::init();

// Must have started identify first
if (empty($_SESSION['hs_signup']['phone'])) {
    redirect('/shop/identify.php');
}

$error = null;
$outOfZone = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = preg_replace('/\D+/', '', (string) ($_POST['postal_code'] ?? '')) ?? '';
    if (strlen($code) !== 4) {
        $error = 'Please enter your 4-digit postal code (e.g. 7140).';
    } else {
        $_SESSION['hs_signup']['postal_code'] = $code;
        if (CustomerRepo::postalCodeInZone($code)) {
            redirect('/shop/details.php');
        } else {
            $outOfZone = true;
            log_event('shop.postal.out_of_zone', null, null, ['code' => $code, 'phone' => $_SESSION['hs_signup']['phone']]);
        }
    }
}

include __DIR__ . '/_header.php';
?>

<section class="shop-step">
  <div class="container shop-step-inner">
    <p class="kicker">Step 2 of 6</p>
    <h1>Are you in our delivery area?</h1>
    <p class="lead">We deliver across Helderberg, Stellenbosch, Strand, Gordon's Bay, Pringle Bay, Betty's Bay, Kleinmond and surrounds.</p>

    <?php if ($outOfZone): ?>
      <div class="alert alert-error">
        <strong>Outside delivery area.</strong> Sadly your postal code is outside our current delivery route. We've noted your details and will be in touch if we expand.
        <p style="margin-top:10px"><a href="/shop/" class="btn btn-ghost">Back to shop</a></p>
      </div>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="post" class="step-form">
        <label>
          <span>4-digit postal code</span>
          <input type="text" name="postal_code" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="7140" required autofocus>
        </label>
        <button type="submit" class="btn btn-primary btn-block">Check delivery area</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/_footer.php'; ?>

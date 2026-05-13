<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/driver_auth.php';

if (current_driver()) {
    header('Location: /driver/today.php');
    exit;
}

$error = null;
$drivers = db()->query("SELECT id, name, avatar_color FROM drivers WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driverId = (int) ($_POST['driver_id'] ?? 0);
    $pin = (string) ($_POST['pin'] ?? '');
    if ($driverId <= 0 || $pin === '') {
        $error = 'Please pick your name and enter your PIN.';
    } else {
        $user = driver_attempt_login($driverId, $pin);
        if ($user) {
            header('Location: /driver/today.php');
            exit;
        }
        $error = 'Wrong PIN. Try again or call admin on 063 693 5532.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<title>Sign in · Hi-Service Driver</title>
<link rel="manifest" href="/driver/manifest.json">
<link rel="apple-touch-icon" href="/driver/icon-192.png">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="driver-login-body">
<main class="drv-login">
  <div class="drv-login-logo">
    <img src="/assets/img/hi-service-logo.png" alt="Hi-Service Gas">
  </div>
  <h1>Driver sign in</h1>
  <p class="muted">Pick your name and enter your 4-digit PIN.</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" class="drv-login-form" autocomplete="off">
    <label>
      <span>Who are you?</span>
      <select name="driver_id" required>
        <option value="">Select your name…</option>
        <?php foreach ($drivers as $d): ?>
          <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>
      <span>4-digit PIN</span>
      <input type="password" name="pin" inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required placeholder="• • • •">
    </label>
    <button type="submit" class="btn btn-primary btn-lg btn-block">Sign in →</button>
    <p class="muted small drv-login-help">Forgot your PIN? Call admin on 063 693 5532</p>
  </form>
</main>
</body>
</html>

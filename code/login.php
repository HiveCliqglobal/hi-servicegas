<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('/admin/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $token    = (string) ($_POST['csrf'] ?? '');

    if (!csrf_verify($token)) {
        $error = 'Session expired. Please try again.';
    } else {
        $user = attempt_login($username, $password);
        if ($user) {
            log_event('auth.login', 'user', (string) $user['id'], ['username' => $user['username']], (int) $user['id']);
            redirect('/admin/');
        }
        $error = 'Invalid username or password.';
        log_to_file('auth', 'login.failed', ['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
$csrf = csrf_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hi-Service · Sign in</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-body">
<main class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">
      <img src="/assets/img/hi-service-logo.png" alt="Hi-Service Gas">
    </div>
    <h1>Operator Portal</h1>
    <p class="muted">Sign in to manage orders, conversations and slots.</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label>
        <span>Username</span>
        <input type="text" name="username" required autofocus value="<?= h($_POST['username'] ?? '') ?>">
      </label>
      <label>
        <span>Password</span>
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>

    <p class="footer-note muted">Hi-Service Gas · 16 Rankine Street, Strand · 063 693 5532</p>
  </div>
</main>
</body>
</html>

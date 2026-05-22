<?php
/**
 * api/xero/callback.php
 *
 * Receives the Xero redirect after the admin authorises. Verifies the
 * CSRF state cookie, exchanges the code for tokens, persists them via
 * Xero::handleCallback(), and bounces back to /admin/connections.php.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/xero.php';
require_login();

$code        = (string) ($_GET['code']  ?? '');
$state       = (string) ($_GET['state'] ?? '');
$xeroErr     = (string) ($_GET['error'] ?? '');
$cookieState = (string) ($_COOKIE['xero_oauth_state'] ?? '');

setcookie('xero_oauth_state', '', time() - 3600, '/', '', true, true);

if ($xeroErr !== '') {
    $_SESSION['flash_error'] = 'Xero returned an error: ' . $xeroErr;
    redirect('/admin/connections.php');
}
if ($code === '' || $state === '') {
    $_SESSION['flash_error'] = 'Xero callback was missing code or state. Try connecting again.';
    redirect('/admin/connections.php');
}
if (!hash_equals($cookieState, $state)) {
    $_SESSION['flash_error'] = 'OAuth state mismatch — please retry from /admin/connections.php';
    redirect('/admin/connections.php');
}

try {
    $tenantName = Xero::handleCallback($code);
    $_SESSION['flash'] = "✓ Xero connected · {$tenantName}";
    log_event('admin.xero.connected', 'xero', $tenantName);
} catch (Throwable $e) {
    log_to_file('xero', 'callback failed', ['err' => $e->getMessage()]);
    $_SESSION['flash_error'] = 'Xero connection failed: ' . $e->getMessage();
}
redirect('/admin/connections.php');

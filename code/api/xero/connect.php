<?php
/**
 * api/xero/connect.php
 *
 * Starts the Xero OAuth 2.0 flow. Generates a CSRF state cookie,
 * builds the authorize URL, redirects the admin to Xero.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/xero.php';
require_login();

if (!Xero::isConfigured()) {
    $_SESSION['flash_error'] = 'Xero is missing XERO_CLIENT_ID / XERO_CLIENT_SECRET. Add them to /home/hiserviceshopz/.env';
    redirect('/admin/connections.php');
}

$state = bin2hex(random_bytes(24));
setcookie('xero_oauth_state', $state, [
    'expires'  => time() + 600,    // 10 minutes
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);

log_event('admin.xero.connect_start', null, null, ['state' => substr($state, 0, 8) . '…']);
redirect(Xero::authorizeUrl($state));

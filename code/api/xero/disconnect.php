<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/xero.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf'] ?? '')) {
    redirect('/admin/connections.php');
}

try {
    Xero::disconnect();
    log_event('admin.xero.disconnected');
    $_SESSION['flash'] = 'Xero disconnected.';
} catch (Throwable $e) {
    $_SESSION['flash_error'] = 'Could not disconnect Xero: ' . $e->getMessage();
}
redirect('/admin/connections.php');

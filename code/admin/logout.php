<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
$u = current_user();
if ($u) {
    log_event('auth.logout', 'user', (string) $u['id'], null, $u['id']);
}
logout();
redirect('/login.php');

<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

// Logged-in users → admin. Everyone else → public shop landing.
if (current_user()) {
    redirect('/admin/');
}
redirect('/shop/');

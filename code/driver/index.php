<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_auth.php';
header('Location: ' . (current_driver() ? '/driver/today.php' : '/driver/login.php'));
exit;

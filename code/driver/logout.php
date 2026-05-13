<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/driver_auth.php';
driver_logout();
header('Location: /driver/login.php');
exit;

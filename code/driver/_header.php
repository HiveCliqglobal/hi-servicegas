<?php
declare(strict_types=1);
$drv = current_driver();
$pageTitle = $pageTitle ?? 'Hi-Service Drivers';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0f172a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Hi-Service Driver">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="manifest" href="/driver/manifest.json">
<link rel="apple-touch-icon" href="/driver/icon-192.png">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="driver-body">

<header class="drv-header">
  <div class="drv-header-top">
    <div class="drv-loc">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
      <span><?= htmlspecialchars($drv['phone'] ? 'Strand · Cape Town' : '') ?></span>
    </div>
    <a href="/driver/profile.php" class="drv-avatar" style="background:<?= htmlspecialchars($drv['avatar']) ?>" title="<?= htmlspecialchars($drv['name']) ?>">
      <?= htmlspecialchars(driver_initials($drv['name'])) ?>
    </a>
  </div>
  <h1 class="drv-greet">Hi <?= htmlspecialchars(explode(' ', $drv['name'])[0]) ?>, <?= htmlspecialchars($greeting) ?> 👋</h1>
</header>

<main class="drv-main">

<?php
declare(strict_types=1);
$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hi-Service · Operator</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="admin-body">

<div class="demo-banner" style="margin-left:240px">🚧 Demo build · Integrations (PayFast, Xero, Meta WhatsApp, GHL) wire in next stage</div>

<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="/assets/img/hi-service-logo.png" alt="Hi-Service">
  </div>
  <nav class="sidebar-nav">
    <a href="/admin/">📊 Dashboard</a>
    <a href="/admin/orders.php">🛒 CRM · Orders</a>
    <a href="/admin/reports.php">📈 Reports</a>
    <a href="/admin/conversations.php">💬 Conversations</a>
    <a href="/admin/slots.php">📅 Slots</a>
    <a href="/admin/products.php">✅ Approved Products</a>
    <a href="/admin/delivery-zones.php">📍 Delivery Zones</a>
    <a href="/admin/drivers.php">🚚 Drivers</a>
    <a href="/admin/audit.php">🔒 Audit Trail</a>
    <a href="/admin/agent.php">🤖 Agent</a>
    <a href="/admin/ai-tester.php">🧪 AI Test Bench</a>
  </nav>
  <div class="sidebar-footer">
    <div class="muted small"><?= h($user['display_name']) ?></div>
    <a href="/admin/logout.php" class="muted small">Sign out</a>
  </div>
</aside>

<main class="admin-main">
  <div class="admin-content">

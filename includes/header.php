<?php
declare(strict_types=1);
$navUser = current_user() ?? ['full_name' => '', 'username' => '', 'role' => 'cashier'];
$brandName = is_admin() ? website_name() : get_setting('store_name', 'My Store');
$navRole = role_label((string)$navUser['role']);
$navLowStock = 0;
try {
    $navLowStock = low_stock_count(db());
} catch (Throwable $ex) {
    error_log('POS low stock badge: ' . $ex->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#15803d">
  <meta name="referrer" content="same-origin">
  <title><?= e($brandName) ?> - POS</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=4">
</head>
<body>
<header class="app-header">
  <div class="brand"><span class="brand-mark"><i class="bi bi-shop"></i></span><span><?= e($brandName) ?></span></div>
  <div class="header-spacer"></div>
  <?php if (!is_admin() && $navLowStock > 0): ?>
    <button class="btn btn-sm" id="low-stock-pill" title="Low stock products" type="button"><i class="bi bi-exclamation-triangle"></i> <span id="low-stock-pill-count"><?= (int)$navLowStock ?></span> low stock</button>
  <?php else: ?>
    <button class="btn btn-sm hidden" id="low-stock-pill" title="Low stock products" type="button"><i class="bi bi-exclamation-triangle"></i> <span id="low-stock-pill-count">0</span> low stock</button>
  <?php endif; ?>
  <div class="user-menu" id="user-menu">
    <button class="user-menu-btn" id="user-menu-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Account menu">
      <span class="header-user">
        <strong><?= e($navUser['full_name'] !== '' ? $navUser['full_name'] : $navUser['username']) ?></strong>
        <span><?= e($navRole) ?></span>
      </span>
      <i class="bi bi-chevron-down"></i>
    </button>
    <div class="user-menu-dropdown hidden" id="user-menu-dropdown" role="menu">
      <button class="user-menu-item" data-tab="settings" type="button" role="menuitem"><i class="bi bi-sliders2"></i> Settings</button>
      <a class="user-menu-item" href="logout" role="menuitem"><i class="bi bi-box-arrow-right"></i> Log out</a>
    </div>
  </div>
</header>
<nav class="app-nav" id="main-nav" aria-label="Main navigation">
  <?php if (is_admin()): ?>
    <button class="nav-tab active" data-tab="dashboard" type="button"><i class="bi bi-speedometer2"></i> Admin Dashboard</button>
    <button class="nav-tab" data-tab="users" type="button"><i class="bi bi-people"></i> Users</button>
    <button class="nav-tab" data-tab="backup" type="button"><i class="bi bi-database-shield"></i> Backup &amp; Restore</button>
  <?php else: ?>
    <button class="nav-tab active" data-tab="cashier" type="button"><i class="bi bi-cash-coin"></i> Cashier</button>
    <button class="nav-tab" data-tab="inventory" type="button"><i class="bi bi-box-seam"></i> Inventory</button>
    <button class="nav-tab" data-tab="sales" type="button"><i class="bi bi-clock-history"></i> Sales History</button>
  <?php endif; ?>
</nav>
<main class="app-main">

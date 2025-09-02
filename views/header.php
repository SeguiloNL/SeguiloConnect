<?php
$u = auth_user();
$config = require __DIR__ . '/../config.php';

// Role-flags
$role = $u['role'] ?? null;
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);
$isCustomer = !$isMgr;
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($config['app']['app_name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= e(base_url()) ?>/assets/css/app.css" rel="stylesheet">
<style>
  .navbar-custom {
    background-color: #1e73be !important;
  }
  .navbar-custom .nav-link,
  .navbar-custom .navbar-brand,
  .navbar-custom .navbar-text {
    color: #fff !important;
  }
  .navbar-custom .nav-link:hover,
  .navbar-custom .nav-link:focus {
    color: #f0f0f0 !important;
  }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-custom mb-0">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= e(base_url()) ?>/index.php?route=dashboard"><?= e($config['app']['app_name']) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <?php if ($u): ?>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link" href="<?= e(base_url()) ?>/index.php?route=dashboard">Dashboard</a>
        </li>

        <!-- Beheer dropdown -->
<?php if ($isMgr): ?>
  <li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="beheerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
      Simkaart &amp; Abonnementen beheer
    </a>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=sims_list">Simkaarten</a></li>
      <?php if ($isSuper): ?>
        <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=plans_list">Abonnementen</a></li>
        <li><hr class="dropdown-divider"></li>
      <?php endif; ?>
      <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=orders_list">Bestellingen</a></li>
    </ul>
  </li>
<?php else: ?>
          <!-- Eindklant: Mijn dropdown -->
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="mijnDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              Mijn
            </a>
            <ul class="dropdown-menu">
              <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=orders_list">Mijn bestellingen</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>

      <!-- Rechts uitgelijnd gedeelte -->
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
        <?php if ($isMgr): ?>
          <li class="nav-item">
            <a class="nav-link" href="<?= e(base_url()) ?>/index.php?route=users_list" title="Gebruikers">
              <i class="bi bi-people"></i>
            </a>
          </li>
        <?php endif; ?>

        <?php if ($isSuper): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle" href="#" id="systeemDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Systeembeheer">
              <i class="bi bi-gear"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=system_admin">Systeembeheer</a></li>
            </ul>
          </li>
        <?php endif; ?>

        <li class="nav-item">
          <span class="navbar-text me-3"><?= e($u['name']) ?> (<?= e(role_label($role)) ?>)</span>
        </li>
        <li class="nav-item">
          <a class="btn btn-outline-light" href="<?= e(base_url()) ?>/index.php?route=logout">Uitloggen</a>
        </li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</nav>

<!-- Impersonatie-banner -->
<?php if (!empty($_SESSION['impersonator_id'])): ?>
  <div class="bg-warning-subtle border-bottom border-warning">
    <div class="container py-2">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          Je bent momenteel ingelogd als <strong><?= e($u['name']) ?> (<?= e($u['role']) ?>)</strong>.
        </div>
        <form method="post" action="index.php?route=impersonate_stop" class="m-0">
          <?php csrf_field(); ?>
          <button class="btn btn-sm btn-outline-dark">Terug naar mijn account</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="container mt-4">
  <?php if (function_exists('flash_output')) echo flash_output(); ?> 
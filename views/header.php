<?php
echo "<div style='background:#ffc;padding:4px'>DEBUG start header.php</div>";
$u = auth_user();
$config = require __DIR__ . '/../config.php';



// Role-flags
$role     = $u['role'] ?? null;
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);
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
  .navbar-custom { background-color: #1e73be !important; }
  .navbar-custom .nav-link,
  .navbar-custom .navbar-brand,
  .navbar-custom .navbar-text { color: #fff !important; }
  .navbar-custom .nav-link:hover,
  .navbar-custom .nav-link:focus { color: #f0f0f0 !important; }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-custom mb-0">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php?route=dashboard">
      <?php if (!empty($config['app']['logo'])): ?>
        <img src="<?= e($config['app']['logo']) ?>" alt="Logo" height="30">
      <?php else: ?>
        <?= e($config['app']['app_name']) ?>
      <?php endif; ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"
            aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <?php if ($u): ?>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Dashboard -->
        <li class="nav-item"><a class="nav-link" href="index.php?route=dashboard">Dashboard</a></li>

        <!-- Klanten -->
        <?php if ($isMgr): ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=users_list">Klanten</a></li>
        <?php endif; ?>

        <!-- Abonnementen -->
        <?php if ($isSuper): ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=plans_list">Abonnementen</a></li>
        <?php endif; ?>

        <!-- Simkaarten (nieuw hoofdmenu item) -->
        <?php if ($isMgr): ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=sims_list">Simkaarten</a></li>
        <?php endif; ?>

        <!-- Bestellen -->
        <?php if ($isMgr): ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=orders_list">Bestellen</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=orders_list">Mijn bestellingen</a></li>
        <?php endif; ?>
      </ul>

      <!-- Rechts uitgelijnd -->
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
        <!-- Account menu -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="accountDropdown" role="button" data-bs-toggle="dropdown">
            Mijn account
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a href="<?= url('profile') ?>">Profiel</a></li>
            <?php if ($isSuper): ?>
              <li><a class="dropdown-item" href="index.php?route=system_admin">Systeembeheer</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="index.php?route=logout">Uitloggen</a></li>
          </ul>
        </li>
      </ul>
    </div>
    <?php endif; ?>
<?php if (is_super_admin()): ?>
<li class="dropdown">
  <a href="#">Admin</a>
  <ul class="dropdown-menu">
    <li><a href="<?= url('admin_users') ?>">Gebruikers</a></li>
    <li><a href="<?= url('admin_integrations') ?>">Koppelingen</a></li>
  </ul>
</li>
<?php endif; ?>

  </div>
</nav>

<!-- Impersonatie banner -->
<?php if (!empty($_SESSION['impersonator_id'])): ?>
  <div class="bg-warning-subtle border-bottom border-warning">
    <div class="container py-2 d-flex justify-content-between align-items-center">
      <div>
        Je bent momenteel ingelogd als <strong><?= e($u['name']) ?> (<?= e($u['role']) ?>)</strong>.
      </div>
      <form method="post" action="index.php?route=impersonate_stop" class="m-0">
        <?php csrf_field(); ?>
        <button class="btn btn-sm btn-outline-dark">Terug naar mijn account</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="container mt-4">

<?php
echo "<div style='background:#cfc;padding:4px'>DEBUG end header.php</div>";
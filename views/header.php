<?php
// views/header.php
// Vereist: auth_user(), role constants (optioneel), db(), e(), base_url()
// Optioneel: system_settings tabel met key 'brand_logo_url'

$u = auth_user();
$config = require __DIR__ . '/../config.php';

// Role flags
$role      = $u['role'] ?? null;
$isSuper   = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes     = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes  = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr     = ($isSuper || $isRes || $isSubRes);
$isCustomer= !$isMgr;

// Probeer het logo uit system_settings te halen
$brandLogoUrl = null;
try {
    if (function_exists('db')) {
        $pdo = db();
        if ($pdo) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) NULL)");
            $st = $pdo->prepare("SELECT v FROM system_settings WHERE k = 'brand_logo_url' LIMIT 1");
            $st->execute();
            $brandLogoUrl = $st->fetchColumn();
            if ($brandLogoUrl !== false) {
                $brandLogoUrl = trim((string)$brandLogoUrl);
                if ($brandLogoUrl === '') $brandLogoUrl = null;
            } else {
                $brandLogoUrl = null;
            }
        }
    }
} catch (Throwable $e) {
    // Stil falen; val terug op app-naam
    $brandLogoUrl = null;
}
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
  .navbar-custom .navbar-text,
  .navbar-custom .dropdown-toggle,
  .navbar-custom .btn-outline-light { color: #fff !important; }
  .navbar-custom .nav-link:hover,
  .navbar-custom .dropdown-toggle:hover { color: #f0f0f0 !important; }

  .brand-logo {
    height: 32px;
    width: auto;
    display: block;
  }
  .brand-fallback {
    font-weight: 600;
    color: #fff !important;
    text-decoration: none;
  }

  /* Dropdown menu contrasterend (licht) op gekleurde navbar */
  .navbar-custom .dropdown-menu {
    border: none;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
  }
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-custom mb-0">
  <div class="container-fluid">
    <!-- Logo / Brand -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(base_url()) ?>/index.php?route=dashboard" aria-label="Home">
      <?php if ($brandLogoUrl): ?>
        <img src="<?= e($brandLogoUrl) ?>" alt="Logo" class="brand-logo">
      <?php else: ?>
        <span class="brand-fallback"><?= e($config['app']['app_name']) ?></span>
      <?php endif; ?>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Menu openen">
      <span class="navbar-toggler-icon"></span>
    </button>

    <?php if ($u): ?>
    <div class="collapse navbar-collapse" id="nav">
      <!-- Linkerzijde: Hoofdmenu's -->
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <!-- Dashboard -->
        <li class="nav-item">
          <a class="nav-link" href="<?= e(base_url()) ?>/index.php?route=dashboard">Dashboard</a>
        </li>

        <!-- Klanten -->
        <li class="nav-item">
          <a class="nav-link" href="<?= e(base_url()) ?>/index.php?route=users_list">Klanten</a>
        </li>

        <!-- Abonnementen (meestal voor Super-admin) -->
        <?php if ($isSuper): ?>
        <li class="nav-item">
          <a class="nav-link" href="<?= e(base_url()) ?>/index.php?route=plans_list">Abonnementen</a>
        </li>
        <?php endif; ?>

        <!-- Bestellen -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="bestellenDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Bestellen
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=orders_list">Overzicht bestellingen</a></li>
            <?php if ($isMgr): ?>
              <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=order_add">Nieuwe bestelling</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=sims_list">Simkaarten</a></li>
            <?php endif; ?>
          </ul>
        </li>
      </ul>

      <!-- Rechterzijde: Mijn account -->
      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-center">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="accountDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Mijn account
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="accountDropdown">
            <li class="dropdown-header">
              <?= e($u['name']) ?> <span class="text-muted">(<?= e($u['role']) ?>)</span>
            </li>
            <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=profile"><i class="bi bi-person"></i> Mijn profiel</a></li>
            <?php if ($isSuper): ?>
              <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=system_admin"><i class="bi bi-gear"></i> Systeembeheer</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(base_url()) ?>/index.php?route=logout"><i class="bi bi-box-arrow-right"></i> Uitloggen</a></li>
          </ul>
        </li>
      </ul>
    </div>
    <?php endif; ?>

<?php if (!empty($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'Super-admin'): ?>
<li class="nav-item">
  <a class="nav-link" href="index.php?route=vendor_orders">Order activatie</a>
</li>
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
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <button class="btn btn-sm btn-outline-dark">Terug naar mijn account</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="container mt-4">
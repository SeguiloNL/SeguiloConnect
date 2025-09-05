<?php
declare(strict_types=1);

/**
 * views/header.php — fail-safe header
 * - Start sessie vroeg
 * - Auth en config zijn try/catch → nooit fatal
 * - Toont optioneel een gele waarschuwing als DB/auth stuk is
 */

require_once __DIR__ . '/../helpers.php';
app_session_start();

$u = null;
$authError = null;
try {
    // auth kan DB connectie triggeren → mag niet fatalen in header
    $u = auth_user();
} catch (Throwable $e) {
    $authError = $e->getMessage();
    $u = null;
}

$config = [];
try {
    $cfg = require __DIR__ . '/../config.php';
    if (is_array($cfg)) { $config = $cfg; }
} catch (Throwable $e) {
    // Niet fatalen; ga met defaults verder
}

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
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= isset($config['app_name']) ? e($config['app_name']) : 'Seguilo Connect' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand" href="index.php">Seguilo Connect</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="mainNav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <?php if ($u): ?>
          <li class="nav-item"><a class="nav-link" href="index.php?route=dashboard">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="index.php?route=sims_list">SIMs</a></li>
          <li class="nav-item"><a class="nav-link" href="index.php?route=users_list">Gebruikers</a></li>
          <?php if ($isSuper): ?>
            <li class="nav-item"><a class="nav-link" href="index.php?route=system_admin">Admin</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php if ($u): ?>
          <li class="nav-item">
            <span class="navbar-text me-3"><?= e($u['name'] ?? $u['email'] ?? 'Account') ?></span>
          </li>
          <li class="nav-item">
            <form class="d-inline" method="post" action="index.php?route=logout">
              <?php if (function_exists('csrf_field')) csrf_field(); ?>
              <button class="btn btn-sm btn-outline-light">Uitloggen</button>
            </form>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-sm btn-outline-light" href="index.php?route=login">Inloggen</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<?php if ($authError): ?>
  <div class="bg-warning-subtle border-bottom border-warning">
    <div class="container py-2 small text-dark">
      Waarschuwing: kan gebruiker niet laden (DB/auth): <code><?= e($authError) ?></code>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($_SESSION['impersonator_id']) && $u): ?>
  <div class="bg-warning-subtle border-bottom border-warning">
    <div class="container py-2 d-flex justify-content-between align-items-center">
      <div>
        Je bent momenteel ingelogd als <strong><?= e($u['name'] ?? '') ?> (<?= e($u['role'] ?? '') ?>)</strong>.
      </div>
      <form method="post" action="index.php?route=impersonate_stop" class="m-0">
        <?php if (function_exists('csrf_field')) csrf_field(); ?>
        <button class="btn btn-sm btn-outline-dark">Terug naar mijn account</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="container mt-4">

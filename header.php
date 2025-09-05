<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers.php';
app_session_start();
$u=null;$authError=null;
try { $u = auth_user(); } catch (Throwable $e) { $authError=$e->getMessage(); $u=null; }
$role = $u['role'] ?? null;
$isSuper = ($role==='super_admin') || (defined('ROLE_SUPER') && $role===ROLE_SUPER);
?><!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(app_config()['app_name'] ?? 'Seguilo Connect') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <i class="bi bi-diagram-3 me-2"></i> Seguilo Connect
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="mainNav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <?php if ($u): ?>
          <li class="nav-item"><a class="nav-link" href="<?= url('dashboard') ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= url('sims_list') ?>"><i class="bi bi-sim me-1"></i>SIMs</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= url('users_list') ?>"><i class="bi bi-people me-1"></i>Gebruikers</a></li>
          <?php if ($isSuper): ?>
            <li class="nav-item"><a class="nav-link" href="<?= url('system_admin') ?>"><i class="bi bi-gear-wide-connected me-1"></i>Admin</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php if ($u): ?>
          <li class="nav-item">
            <span class="navbar-text me-3"><i class="bi bi-person-circle me-1"></i><?= e($u['name'] ?? $u['email'] ?? 'Account') ?></span>
          </li>
          <li class="nav-item">
            <form class="d-inline" method="post" action="<?= url('logout') ?>">
              <?php if (function_exists('csrf_field')) csrf_field(); ?>
              <button class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i>Uitloggen</button>
            </form>
          </li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-sm btn-outline-light" href="<?= url('login') ?>"><i class="bi bi-box-arrow-in-right me-1"></i>Inloggen</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php if ($authError): ?>
  <div class="bg-warning-subtle border-bottom border-warning">
    <div class="container py-2 small text-dark">
      Waarschuwing: gebruiker niet geladen (DB/auth): <code><?= e($authError) ?></code>
    </div>
  </div>
<?php endif; ?>
<div class="container mt-4">

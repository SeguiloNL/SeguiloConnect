<?php
$config = require __DIR__ . '/../../config.php';
?>
<!doctype html>
<html lang="nl">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      body{ background:#f7f7fb; }
      .card{ border-radius: 1rem; }
      .navbar-brand{ font-weight:700; }
    </style>
  </head>
  <body>
<nav class="navbar navbar-expand-lg bg-body-tertiary">
  <div class="container">
    <a class="navbar-brand" href="/index.php">MDO Portal</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if(current_user()): ?>
          <li class="nav-item"><a class="nav-link" href="/index.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="/index.php?page=orders"><i class="bi bi-bag"></i> Bestellingen</a></li>
          <li class="nav-item"><a class="nav-link" href="/index.php?page=simcards"><i class="bi bi-sim"></i> Simkaarten</a></li>
          <li class="nav-item"><a class="nav-link" href="/index.php?page=users"><i class="bi bi-people"></i> Gebruikers</a></li>
          <?php if(is_super()): ?>
            <li class="nav-item"><a class="nav-link" href="/index.php?page=plans"><i class="bi bi-list-check"></i> Plannen</a></li>
            <li class="nav-item"><a class="nav-link" href="/index.php?page=suppliers"><i class="bi bi-building"></i> Leveranciers</a></li>
            <li class="nav-item"><a class="nav-link" href="/index.php?page=admin"><i class="bi bi-gear"></i> /admin</a></li>
          <?php endif; ?>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <?php if(current_user()): ?>
          <li class="nav-item"><span class="navbar-text me-3"><i class="bi bi-person-badge"></i> <?= e(current_user()['email']) ?> (<?= e(current_user()['role']) ?>)</span></li>
          <li class="nav-item"><a class="btn btn-outline-secondary" href="/index.php?page=logout">Uitloggen</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="btn btn-primary" href="/index.php?page=login">Inloggen</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<div class="container mt-4">
<?php foreach(get_flash() as $f): ?>
  <div class="alert alert-<?= e($f['t']) ?>"><?= e($f['m']) ?></div>
<?php endforeach; ?>

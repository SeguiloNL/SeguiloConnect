<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();
$me = auth_user(); if (!$me) { header('Location: '.url('login')); exit; }
$role = (string)($me['role'] ?? '');

$activeSims = $stockSims = $pending = $activeCustomers = 0;
try {
  $pdo = db();
  $activeSims = (int)(($pdo->query("SELECT COUNT(*) c FROM sims WHERE status='active'")->fetch()['c'] ?? 0));
  $stockSims  = (int)(($pdo->query("SELECT COUNT(*) c FROM sims WHERE status='stock'")->fetch()['c'] ?? 0));
  $pending    = (int)(($pdo->query("SELECT COUNT(*) c FROM orders WHERE status='awaiting_activation'")->fetch()['c'] ?? 0));
  $activeCustomers = (int)(($pdo->query("SELECT COUNT(*) c FROM users WHERE role='customer' AND is_active=1")->fetch()['c'] ?? 0));
} catch (Throwable $e) { /* laat 0's zien */ }
?>
<div class="row g-4">
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card stat">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="text-muted"><i class="bi bi-sim me-1"></i> Actieve SIM’s</div><div class="fs-3 fw-bold"><?= (int)$activeSims ?></div></div>
        <div class="display-6"><i class="bi bi-activity"></i></div>
      </div>
      <div class="card-footer bg-transparent border-0"><a class="stretched-link" href="<?= url('sims_list', ['status'=>'active']) ?>">Bekijken</a></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card stat">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="text-muted"><i class="bi bi-box-seam me-1"></i> SIM’s op voorraad</div><div class="fs-3 fw-bold"><?= (int)$stockSims ?></div></div>
        <div class="display-6"><i class="bi bi-archive"></i></div>
      </div>
      <div class="card-footer bg-transparent border-0"><a class="stretched-link" href="<?= url('sims_list', ['status'=>'stock']) ?>">Bekijken</a></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card stat">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="text-muted"><i class="bi bi-hourglass-split me-1"></i> Wachten op activatie</div><div class="fs-3 fw-bold"><?= (int)$pending ?></div></div>
        <div class="display-6"><i class="bi bi-hourglass"></i></div>
      </div>
      <div class="card-footer bg-transparent border-0"><a class="stretched-link" href="<?= url('order_status', ['status'=>'awaiting_activation']) ?>">Bekijken</a></div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card stat">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div><div class="text-muted"><i class="bi bi-people me-1"></i> Actieve klanten</div><div class="fs-3 fw-bold"><?= (int)$activeCustomers ?></div></div>
        <div class="display-6"><i class="bi bi-people-fill"></i></div>
      </div>
      <div class="card-footer bg-transparent border-0"><a class="stretched-link" href="<?= url('users_list', ['role'=>'customer','is_active'=>1]) ?>">Bekijken</a></div>
    </div>
  </div>
</div>

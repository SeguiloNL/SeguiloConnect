<?php
// pages/dashboard.php — Tegels met correcte scoping voor VOORRAAD

require_once __DIR__ . '/../helpers.php';
require_login();
$db = db(); // ← BELANGRIJK
$user = auth_user();
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$myId = (int)($me['id'] ?? 0);
$role = (string)($me['role'] ?? '');
$isSuper   = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes     = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes  = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try { $pdo = db(); }
catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
  return;
}

// -------- Helpers --------
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId];
  $seen = [$rootId => true];
  $queue = [$rootId];
  while ($queue) {
    $chunk = array_splice($queue, 0, 200);
    if (!$chunk) break;
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
    $st->execute($chunk);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
      $cid = (int)$cid;
      if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $queue[]=$cid; }
    }
  }
  return $ids;
}

// scope-ids voor orders/klanten (niet voor voorraad-teller)
$scopeCustomerIds = [];
if (!$isSuper) {
  $scopeCustomerIds = build_tree_ids($pdo, $myId);
  if (!$scopeCustomerIds) { $scopeCustomerIds = [$myId]; }
}

// ---------- Teller: Actieve SIM's (laatste order completed + sim bij eindklant) ----------
try {
  if ($isSuper) {
    $sql = "
      SELECT COUNT(DISTINCT s.id)
      FROM sims s
      JOIN users u ON u.id = s.assigned_to_user_id AND u.role = 'customer'
      JOIN (SELECT sim_id, MAX(id) AS last_order_id FROM orders GROUP BY sim_id) lo ON lo.sim_id = s.id
      JOIN orders o ON o.id = lo.last_order_id AND o.status = 'completed'
    ";
    $st = $pdo->prepare($sql);
    $st->execute();
  } else {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $sql = "
      SELECT COUNT(DISTINCT s.id)
      FROM sims s
      JOIN users u ON u.id = s.assigned_to_user_id AND u.role = 'customer'
      JOIN (SELECT sim_id, MAX(id) AS last_order_id FROM orders GROUP BY sim_id) lo ON lo.sim_id = s.id
      JOIN orders o ON o.id = lo.last_order_id AND o.status = 'completed'
      WHERE u.id IN ($ph)
    ";
    $st = $pdo->prepare($sql);
    $st->execute($scopeCustomerIds);
  }
  $activeSims = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Actieve SIM’s tellen mislukt: '.e($e->getMessage()).'</div>';
  $activeSims = 0;
}

// ---------- Teller: SIM's op voorraad ----------
// Definitie: niet retired EN géén order met status in ('concept','awaiting_activation','completed').
// Super-admin: ALLE voorraad; Reseller/Sub-reseller: ALLEEN eigen voorraad (assigned_to_user_id = $myId).
try {
  if ($isSuper) {
    $sql = "
      SELECT COUNT(*)
      FROM sims s
      WHERE (s.status IS NULL OR s.status <> 'retired')
        AND NOT EXISTS (
          SELECT 1 FROM orders o
          WHERE o.sim_id = s.id
            AND o.status IN ('concept','awaiting_activation','completed')
        )
    ";
    $st = $pdo->prepare($sql);
    $st->execute();
  } else {
    $sql = "
      SELECT COUNT(*)
      FROM sims s
      WHERE (s.status IS NULL OR s.status <> 'retired')
        AND s.assigned_to_user_id = ?
        AND NOT EXISTS (
          SELECT 1 FROM orders o
          WHERE o.sim_id = s.id
            AND o.status IN ('concept','awaiting_activation','completed')
        )
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$myId]);
  }
  $stockSims = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Voorraad tellen mislukt: '.e($e->getMessage()).'</div>';
  $stockSims = 0;
}

// ---------- Teller: Wachten op activatie ----------
try {
  if ($isSuper) {
    $sql = "SELECT COUNT(*) FROM orders o WHERE o.status = 'awaiting_activation'";
    $st = $pdo->prepare($sql);
    $st->execute();
  } else {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $sql = "SELECT COUNT(*) FROM orders o WHERE o.status = 'awaiting_activation' AND o.customer_id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($scopeCustomerIds);
  }
  $awaiting = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Aantal “Wachten op activatie” mislukt: '.e($e->getMessage()).'</div>';
  $awaiting = 0;
}

// ---------- Teller: Actieve klanten ----------
try {
  if ($isSuper) {
    $sql = "SELECT COUNT(*) FROM users WHERE role='customer' AND is_active = 1";
    $st = $pdo->prepare($sql);
    $st->execute();
  } else {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $sql = "SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1 AND id IN ($ph)";
    $st = $pdo->prepare($sql);
    $st->execute($scopeCustomerIds);
  }
  $activeCustomers = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Actieve klanten tellen mislukt: '.e($e->getMessage()).'</div>';
  $activeCustomers = 0;
}

// -------- UI --------
echo function_exists('flash_output') ? flash_output() : '';
?>
<div class="row g-3">
  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-muted">Actieve SIM’s</div>
            <div class="fs-3 fw-bold"><?= (int)$activeSims ?></div>
          </div>
          <div class="display-6 text-success"><i class="bi bi-sim"></i></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=sims_list&status=active">Bekijken</a>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-muted">SIM’s op voorraad</div>
            <div class="fs-3 fw-bold"><?= (int)$stockSims ?></div>
          </div>
          <div class="display-6 text-primary"><i class="bi bi-box-seam"></i></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <?php if ($isSuper): ?>
          <a class="stretched-link" href="index.php?route=sims_list&status=stock">Bekijken</a>
        <?php else: ?>
          <!-- Je eigen voorraad; dezelfde lijst kan via filter op owner=myId -->
          <a class="stretched-link" href="index.php?route=sims_list&status=stock&owner=me">Bekijken</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-muted">Wachten op activatie</div>
            <div class="fs-3 fw-bold"><?= (int)$awaiting ?></div>
          </div>
          <div class="display-6 text-warning"><i class="bi bi-hourglass-split"></i></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=orders_list&status=awaiting_activation">Bekijken</a>
      </div>
    </div>
  </div>

  <div class="col-md-3">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="text-muted">Actieve klanten</div>
            <div class="fs-3 fw-bold"><?= (int)$activeCustomers ?></div>
          </div>
          <div class="display-6 text-info"><i class="bi bi-people"></i></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0">
        <a class="stretched-link" href="index.php?route=users_list&role=customer&is_active=1">Bekijken</a>
      </div>
    </div>
  </div>
</div>
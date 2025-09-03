<?php
// pages/dashboard.php — Tegels met o.a. “Wachten op activatie” (scoped)
require_once __DIR__ . '/../helpers.php';
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

/** Alle user-ids in de boom (incl. root) via users.parent_user_id */
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
      if (!isset($seen[$cid])) { $seen[$cid] = true; $ids[] = $cid; $queue[] = $cid; }
    }
  }
  return $ids;
}

// Scope-ids voor klantselecties (super = geen beperking)
$scopeCustomerIds = [];
if (!$isSuper) {
  $scopeCustomerIds = build_tree_ids($pdo, $myId);
  if (!$scopeCustomerIds) { $scopeCustomerIds = [$myId]; }
}

// --------- Tellers opbouwen (met scope) ----------
try {
  // 1) Actieve SIMs: sim toegewezen aan eindklant + order op completed voor die sim
  // We definiëren “eindklant” als users.role='customer'. We tellen unieke sim-id’s.
  $params = [];
  $whereCustomer = "u.role='customer'";
  if (!$isSuper) {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $whereCustomer .= " AND u.id IN ($ph)";
    array_push($params, ...$scopeCustomerIds);
  }
  $sqlActiveSims = "
    SELECT COUNT(DISTINCT s.id)
    FROM sims s
    JOIN users u ON u.id = s.assigned_to_user_id
    WHERE $whereCustomer
      AND EXISTS (
        SELECT 1 FROM orders o
        WHERE o.sim_id = s.id AND o.status = 'completed'
      )
  ";
  $activeSims = (int)$pdo->prepare($sqlActiveSims)->execute($params) ? (int)$pdo->prepare($sqlActiveSims)->fetchColumn() : 0;
} catch (Throwable $e) {
  // prepared twee keer aanroepen is onhandig; herstellen:
  $st = $pdo->prepare($sqlActiveSims);
  $st->execute($params);
  $activeSims = (int)$st->fetchColumn();
}

try {
  // 2) SIMs op voorraad: niet retired en géén order met status concept/awaiting_activation/completed
  // (cancelled telt niet als gekoppeld)
  $params = [];
  $whereStock = " (s.status IS NULL OR s.status <> 'retired') ";
  if (!$isSuper) {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    // voorraad voor reseller/sub: toon voorraad die in eigen keten valt.
    // Er zijn 2 varianten in de praktijk:
    // - SIM is nog niet toegewezen (assigned_to_user_id IS NULL): dan laten we hem alleen zien als hij 'onder' jou valt is lastig te bepalen.
    //   In deze portal gebruiken we vaak assigned_to_user_id als eigenaar. Dus neem SIMs met assigned_to_user_id IN scope óf NULL.
    $whereStock .= " AND (s.assigned_to_user_id IS NULL OR s.assigned_to_user_id IN ($ph))";
    array_push($params, ...$scopeCustomerIds);
  }
  $sqlStock = "
    SELECT COUNT(*)
    FROM sims s
    WHERE $whereStock
      AND NOT EXISTS (
        SELECT 1 FROM orders o
        WHERE o.sim_id = s.id AND o.status IN ('concept','awaiting_activation','completed')
      )
  ";
  $st = $pdo->prepare($sqlStock);
  $st->execute($params);
  $stockSims = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Voorraad tellen mislukt: '.e($e->getMessage()).'</div>';
  $stockSims = 0;
}

try {
  // 3) Wachten op activatie: orders.status = awaiting_activation
  $params = [];
  $whereOrders = " o.status = 'awaiting_activation' ";
  if (!$isSuper) {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $whereOrders .= " AND o.customer_id IN ($ph)";
    array_push($params, ...$scopeCustomerIds);
  }
  $sqlAwait = "SELECT COUNT(*) FROM orders o WHERE $whereOrders";
  $st = $pdo->prepare($sqlAwait);
  $st->execute($params);
  $awaiting = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Aantal “Wachten op activatie” mislukt: '.e($e->getMessage()).'</div>';
  $awaiting = 0;
}

try {
  // 4) Actieve klanten: users.role='customer' AND is_active=1
  $params = [];
  $whereCust = " role = 'customer' AND is_active = 1 ";
  if (!$isSuper) {
    $ph = implode(',', array_fill(0, count($scopeCustomerIds), '?'));
    $whereCust .= " AND id IN ($ph)";
    array_push($params, ...$scopeCustomerIds);
  }
  $sqlActiveCustomers = "SELECT COUNT(*) FROM users WHERE $whereCust";
  $st = $pdo->prepare($sqlActiveCustomers);
  $st->execute($params);
  $activeCustomers = (int)$st->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-warning">Actieve klanten tellen mislukt: '.e($e->getMessage()).'</div>';
  $activeCustomers = 0;
}

// -------- UI --------
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
        <a class="stretched-link" href="index.php?route=sims_list&status=stock">Bekijken</a>
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

<?php
// (optioneel) extra: laatst gewijzigde bestellingen/snippets kun je hier toevoegen.
<?php
// pages/orders_list.php — overzicht bestellingen + "Nieuwe bestelling"-knop
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role       = $me['role'] ?? '';
$isSuper    = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes      = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes   = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isCustomer = !($isSuper || $isRes || $isSubRes);

// --- DB ---
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>'; return; }

// --- helpers ---
function column_exists(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->quote($col);
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->quote($table);
    return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}
function build_tree_ids(PDO $pdo, int $rootId): array {
    if (!column_exists($pdo, 'users', 'parent_user_id')) return [$rootId];
    $ids = [$rootId];
    $queue = [$rootId];
    $seen  = [$rootId => true];
    while ($queue) {
        $chunk = array_splice($queue, 0, 100);
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

// --- kolommen/joins mogelijk? ---
$hasCreatedBy   = column_exists($pdo, 'orders', 'created_by_user_id');
$hasCustomerCol = column_exists($pdo, 'orders', 'customer_user_id');
$hasResellerCol = column_exists($pdo, 'orders', 'reseller_user_id'); // optioneel
$hasSimId       = column_exists($pdo, 'orders', 'sim_id');
$hasPlanId      = column_exists($pdo, 'orders', 'plan_id');
$hasCreatedAt   = column_exists($pdo, 'orders', 'created_at');
$hasUpdatedAt   = column_exists($pdo, 'orders', 'updated_at');

$tblSims  = table_exists($pdo, 'sims');
$tblPlans = table_exists($pdo, 'plans');

// --- filters ---
$status   = trim((string)($_GET['status'] ?? ''));
$q        = trim((string)($_GET['q'] ?? ''));

// paginatie
$allowedPerPage = [25,50,100,1000,5000];
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// --- FROM / JOIN ---
$selectCols = ['o.id', 'o.status'];
if ($hasCreatedBy)   $selectCols[] = 'o.created_by_user_id';
if ($hasCustomerCol) $selectCols[] = 'o.customer_user_id';
if ($hasResellerCol) $selectCols[] = 'o.reseller_user_id';
if ($hasSimId)       $selectCols[] = 'o.sim_id';
if ($hasPlanId)      $selectCols[] = 'o.plan_id';
if ($hasCreatedAt)   $selectCols[] = 'o.created_at';
if ($hasUpdatedAt)   $selectCols[] = 'o.updated_at';

$sqlFrom = " FROM orders o ";
$sqlJoin = "";

// klant
if ($hasCustomerCol) {
    $sqlJoin .= " LEFT JOIN users u_customer ON u_customer.id = o.customer_user_id ";
} elseif ($hasCreatedBy) {
    $sqlJoin .= " LEFT JOIN users u_customer ON u_customer.id = o.created_by_user_id ";
}

// sim
if ($hasSimId && $tblSims) {
    $sqlJoin .= " LEFT JOIN sims s ON s.id = o.sim_id ";
}
// plan
if ($hasPlanId && $tblPlans) {
    $sqlJoin .= " LEFT JOIN plans p ON p.id = o.plan_id ";
}

// --- WHERE + params ---
$where  = [];
$params = [];

// status
if ($status !== '') {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}

// zoeken
if ($q !== '') {
    $or = [];
    if (ctype_digit($q)) { $or[]='o.id = ?'; $params[]=(int)$q; }
    $or[] = '(u_customer.name LIKE ?)';
    $params[] = '%' . $q . '%';
    if ($tblSims && column_exists($pdo,'sims','iccid') && $hasSimId) { $or[]='(s.iccid LIKE ?)'; $params[]='%'.$q.'%'; }
    if ($tblPlans && column_exists($pdo,'plans','name') && $hasPlanId) { $or[]='(p.name LIKE ?)'; $params[]='%'.$q.'%'; }
    $where[] = '(' . implode(' OR ', $or) . ')';
}

// scope
if (!$isSuper) {
    $treeIds = build_tree_ids($pdo, (int)$me['id']);
    $scopes = [];
    if ($hasCreatedBy) {
        $ph = implode(',', array_fill(0, count($treeIds), '?'));
        $scopes[] = "o.created_by_user_id IN ($ph)";
        array_push($params, ...$treeIds);
    }
    if ($hasCustomerCol) {
        $ph2 = implode(',', array_fill(0, count($treeIds), '?'));
        $scopes[] = "o.customer_user_id IN ($ph2)";
        array_push($params, ...$treeIds);
    }
    if ($scopes) {
        $where[] = '(' . implode(' OR ', $scopes) . ')';
    } else {
        $where[] = '1=0';
    }
}

$sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// --- COUNT ---
$sqlCount = "SELECT COUNT(*) " . $sqlFrom . $sqlJoin . $sqlWhere;
$stCount  = $pdo->prepare($sqlCount);
$stCount->execute($params);
$total = (int)$stCount->fetchColumn();

// --- DATA ---
$sqlSelect = "SELECT " . implode(', ', $selectCols)
           . ", u_customer.name AS customer_name"
           . ($tblSims && column_exists($pdo,'sims','iccid') && $hasSimId ? ", s.iccid AS sim_iccid" : "")
           . ($tblPlans && column_exists($pdo,'plans','name') && $hasPlanId ? ", p.name AS plan_name" : "")
           . $sqlFrom . $sqlJoin . $sqlWhere
           . " ORDER BY o.id DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sqlSelect);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));

// url helper
function orders_url(array $extra = []): string {
    $base = 'index.php';
    $params = array_merge([
        'route'    => 'orders_list',
        'status'   => $_GET['status'] ?? null,
        'q'        => $_GET['q'] ?? null,
        'per_page' => $_GET['per_page'] ?? null,
    ], $extra);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $base . '?' . http_build_query($params);
}
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Bestellingen</h4>

  <div class="d-flex align-items-center gap-2">
    <form class="d-flex gap-2" method="get" action="index.php">
      <input type="hidden" name="route" value="orders_list">
      <?php if ($status !== ''): ?>
        <input type="hidden" name="status" value="<?= e($status) ?>">
      <?php endif; ?>
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Zoek op #id / klant / ICCID / plan">
      <select class="form-select" name="per_page" onchange="this.form.submit()">
        <?php foreach ([25,50,100,1000,5000] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?>/pag.</option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-outline-light border">Zoeken</button>
    </form>

    <?php if (!$isCustomer): ?>
      <a class="btn btn-primary" href="index.php?route=order_add">
        Nieuwe bestelling
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="mb-3">
  <div class="btn-group" role="group">
    <a class="btn btn<?= $status==='' ? '' : '-outline' ?>-primary" href="index.php?route=orders_list">Alle</a>
    <a class="btn btn<?= $status==='Concept' ? '' : '-outline' ?>-primary" href="index.php?route=orders_list&status=Concept">Concept</a>
    <a class="btn btn<?= $status==='Wachten op activatie' ? '' : '-outline' ?>-primary" href="index.php?route=orders_list&status=Wachten%20op%20activatie">Wachten op activatie</a>
    <a class="btn btn<?= $status==='Voltooid' ? '' : '-outline' ?>-primary" href="index.php?route=orders_list&status=Voltooid">Voltooid</a>
    <a class="btn btn<?= $status==='geannuleerd' ? '' : '-outline' ?>-primary" href="index.php?route=orders_list&status=geannuleerd">Geannuleerd</a>
  </div>
</div>

<?php if ($total === 0): ?>
  <div class="alert alert-info">Geen bestellingen gevonden.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:80px;">#</th>
          <th>Klant</th>
          <th>SIM</th>
          <th>Abonnement</th>
          <th>Status</th>
          <th style="width:160px;">Aangemaakt</th>
          <th style="width:220px;">Acties</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td>
              <?php
                $custName = $r['customer_name'] ?? '';
                if ($hasCustomerCol && !empty($r['customer_user_id'])) {
                    echo e('#'.$r['customer_user_id'].' — '.($custName ?: 'Onbekend'));
                } elseif ($hasCreatedBy && !empty($r['created_by_user_id'])) {
                    echo e('#'.$r['created_by_user_id'].' — '.($custName ?: 'Onbekend'));
                } else {
                    echo '<span class="text-muted">—</span>';
                }
              ?>
            </td>
            <td><?= e($r['sim_iccid'] ?? '') ?></td>
            <td><?= e($r['plan_name'] ?? '') ?></td>
            <td><?= e($r['status'] ?? '') ?></td>
            <td>
              <?php
                if (!empty($r['created_at'])) echo e($r['created_at']);
                elseif (!empty($r['updated_at'])) echo e($r['updated_at']);
                else echo '<span class="text-muted">—</span>';
              ?>
            </td>
            <td>
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-outline-primary" href="index.php?route=order_edit&id=<?= (int)$r['id'] ?>">Bekijken</a>
                <?php
                  // Verwijderen: super-admin altijd; reseller alleen binnen eigen scope
                  $canDelete = false;
                  if ($isSuper) {
                      $canDelete = true;
                  } elseif ($isRes || $isSubRes) {
                      $treeIds = build_tree_ids($pdo, (int)$me['id']);
                      if ($hasCreatedBy && in_array((int)($r['created_by_user_id'] ?? 0), $treeIds, true)) $canDelete = true;
                      if ($hasCustomerCol && in_array((int)($r['customer_user_id'] ?? 0), $treeIds, true)) $canDelete = true;
                  }
                ?>
                <?php if ($canDelete): ?>
                  <form method="post" action="index.php?route=order_delete" onsubmit="return confirm('Weet je zeker dat je deze bestelling wil verwijderen?');">
                    <?php if (function_exists('csrf_field')) csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-outline-danger">Verwijderen</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginatie -->
  <nav aria-label="Paginatie">
    <ul class="pagination">
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= e(orders_url(['page'=>1])) ?>">«</a>
      </li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= e(orders_url(['page'=>max(1,$page-1)])) ?>">‹</a>
      </li>
      <li class="page-item active">
        <span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span>
      </li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
        <a class="page-link" href="<?= e(orders_url(['page'=>min($totalPages,$page+1)])) ?>">›</a>
      </li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
        <a class="page-link" href="<?= e(orders_url(['page'=>$totalPages])) ?>">»</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
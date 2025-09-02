<?php
// pages/sims_list.php — lijst met simkaarten
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try {
    $pdo = db();
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>';
    return;
}

/** Helpers */
function column_exists(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->quote($col);
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->quote($table);
    return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}

/** Input */
$status = $_GET['status'] ?? ''; // '', 'stock'
$q      = trim($_GET['q'] ?? '');

// Paginatie
$allowedPerPage = [25, 50, 100, 1000, 5000];
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

/** Kolommen detecteren */
$hasAssigned   = column_exists($pdo, 'sims', 'assigned_to_user_id');
$hasRetired    = column_exists($pdo, 'sims', 'retired');
$hasICCID      = column_exists($pdo, 'sims', 'iccid');
$hasIMSI       = column_exists($pdo, 'sims', 'imsi');
$hasMSISDN     = column_exists($pdo, 'sims', 'msisdn');
$hasPIN        = column_exists($pdo, 'sims', 'pin');
$hasPUK        = column_exists($pdo, 'sims', 'puk');

$ordersTable   = table_exists($pdo, 'orders');

/** Zoeken: in bestaande kolommen */
$where  = [];
$params = [];
$searchCols = [];
foreach ([['name'=>'iccid','has'=>$hasICCID],['name'=>'imsi','has'=>$hasIMSI],['name'=>'msisdn','has'=>$hasMSISDN],['name'=>'pin','has'=>$hasPIN],['name'=>'puk','has'=>$hasPUK]] as $c) {
    if ($c['has']) $searchCols[] = $c['name'];
}
if ($q !== '' && $searchCols) {
    $like = '%' . $q . '%';
    $ors = [];
    foreach ($searchCols as $c) {
        $ors[]    = "s.`{$c}` LIKE ?";
        $params[] = $like;
    }
    $where[] = '(' . implode(' OR ', $ors) . ')';
}

/** Status=stock: NIET retired én GEEN voltooid abonnement */
$joinOrdersCompleted = false;
if ($status === 'stock') {
    if ($hasRetired) {
        $where[] = 'COALESCE(s.retired,0) = 0';
    }
    if ($ordersTable) {
        $joinOrdersCompleted = true;
        $where[] = 'o_completed.sim_id IS NULL';
    }
}

/** Scope voor reseller/sub-reseller: laat sims zien binnen hun “tree” (assigned_to_user_id) */
if (!$isSuper && $hasAssigned) {
    $treeIds = [$me['id']];
    if (column_exists($pdo, 'users', 'parent_user_id')) {
        $queue = [$me['id']];
        $seen  = [$me['id'] => true];
        while ($queue) {
            $chunk = array_splice($queue, 0, 100);
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $cid = (int)$cid;
                if (!isset($seen[$cid])) { $seen[$cid]=true; $treeIds[]=$cid; $queue[]=$cid; }
            }
        }
    }
    if ($treeIds) {
        $ph = implode(',', array_fill(0, count($treeIds), '?'));
        // Toont zowel niet-toegewezen (NULL) als toegewezen binnen boom
        $where[] = " (s.assigned_to_user_id IN ($ph) OR s.assigned_to_user_id IS NULL) ";
        array_push($params, ...$treeIds);
    }
}

/** SQL opbouw */
$sqlFrom = " FROM sims s ";
$sqlJoin = "";
$sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : "";

// Join voor “voltooide orders” (zodat we stock = zonder completed order kunnen filteren)
if ($joinOrdersCompleted) {
    $sqlJoin .= " LEFT JOIN (
                    SELECT sim_id
                    FROM orders
                    WHERE status = 'Voltooid'
                    GROUP BY sim_id
                  ) o_completed ON o_completed.sim_id = s.id ";
}

// Join naar users om de naam van toegewezen gebruiker te tonen
if ($hasAssigned) {
    $sqlJoin .= " LEFT JOIN users u_assign ON u_assign.id = s.assigned_to_user_id ";
}

/** Count */
$sqlCount = "SELECT COUNT(*) " . $sqlFrom . $sqlJoin . $sqlWhere;
$stCount = $pdo->prepare($sqlCount);
$stCount->execute($params);
$total = (int)$stCount->fetchColumn();

/** Data select */
$selectCols = ["s.*"];
if ($hasAssigned) {
    $selectCols[] = "u_assign.name AS assigned_name";
}
$sqlSelect = "SELECT " . implode(', ', $selectCols) . $sqlFrom . $sqlJoin . $sqlWhere . " ORDER BY s.id DESC LIMIT {$perPage} OFFSET {$offset}";
$st = $pdo->prepare($sqlSelect);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$totalPages = max(1, (int)ceil($total / $perPage));

/** URL helper */
function sims_url(array $extra = []): string {
    $base = 'index.php';
    $params = array_merge([
        'route'    => 'sims_list',
        'status'   => $_GET['status'] ?? null,
        'q'        => $_GET['q'] ?? null,
        'per_page' => $_GET['per_page'] ?? null,
    ], $extra);
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return $base . '?' . http_build_query($params);
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Simkaarten</h4>
  <form class="d-flex gap-2" method="get" action="index.php">
    <input type="hidden" name="route" value="sims_list">
    <?php if ($status !== ''): ?>
      <input type="hidden" name="status" value="<?= e($status) ?>">
    <?php endif; ?>
    <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Zoeken op ICCID / IMSI / MSISDN / PIN / PUK">
    <select class="form-select" name="per_page" onchange="this.form.submit()">
      <?php foreach ([25,50,100,1000,5000] as $opt): ?>
        <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?>/pag.</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-secondary">Zoeken</button>
  </form>
</div>

<div class="mb-3">
  <div class="btn-group" role="group">
    <a class="btn btn<?= $status==='' ? '' : '-outline' ?>-primary" href="index.php?route=sims_list">Alles</a>
    <a class="btn btn<?= $status==='stock' ? '' : '-outline' ?>-primary" href="index.php?route=sims_list&status=stock">SIMs op voorraad</a>
  </div>
</div>

<?php if ($total === 0): ?>
  <div class="alert alert-info">Geen simkaarten gevonden.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:80px;">ID</th>
          <?php if ($hasICCID):  ?><th>ICCID</th><?php endif; ?>
          <?php if ($hasIMSI):   ?><th>IMSI</th><?php endif; ?>
          <?php if ($hasMSISDN): ?><th>MSISDN</th><?php endif; ?>
          <?php if ($hasAssigned): ?><th>Toegewezen aan</th><?php endif; ?>
          <?php if ($hasRetired):  ?><th>Retired</th><?php endif; ?>
          <th style="width:220px;">Acties</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <?php if ($hasICCID):  ?><td><?= e($r['iccid']  ?? '') ?></td><?php endif; ?>
            <?php if ($hasIMSI):   ?><td><?= e($r['imsi']   ?? '') ?></td><?php endif; ?>
            <?php if ($hasMSISDN): ?><td><?= e($r['msisdn'] ?? '') ?></td><?php endif; ?>
            <?php if ($hasAssigned): ?>
              <td>
                <?php
                  $uid   = $r['assigned_to_user_id'] ?? null;
                  $uname = $r['assigned_name'] ?? '';
                  if ($uid) {
                      echo e((string)$uid) . ' — ' . e($uname !== '' ? $uname : '(naam onbekend)');
                  } else {
                      echo '<span class="text-muted">—</span>';
                  }
                ?>
              </td>
            <?php endif; ?>
            <?php if ($hasRetired): ?>
              <td><?= (int)($r['retired'] ?? 0) ? 'Ja' : 'Nee' ?></td>
            <?php endif; ?>
            <td>
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-outline-primary" href="index.php?route=sim_edit&id=<?= (int)$r['id'] ?>">Bewerken</a>
                <a class="btn btn-outline-secondary" href="index.php?route=sim_assign&sim_id=<?= (int)$r['id'] ?>">Toewijzen</a>
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
        <a class="page-link" href="<?= e(sims_url(['page'=>1])) ?>">«</a>
      </li>
      <li class="page-item <?= $page<=1?'disabled':'' ?>">
        <a class="page-link" href="<?= e(sims_url(['page'=>max(1,$page-1)])) ?>">‹</a>
      </li>
      <li class="page-item active">
        <span class="page-link"><?= (int)$page ?> / <?= (int)$totalPages ?></span>
      </li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
        <a class="page-link" href="<?= e(sims_url(['page'=>min($totalPages,$page+1)])) ?>">›</a>
      </li>
      <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
        <a class="page-link" href="<?= e(sims_url(['page'=>$totalPages])) ?>">»</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>
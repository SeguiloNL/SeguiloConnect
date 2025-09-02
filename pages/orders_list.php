<?php
// pages/orders_list.php — robuuste lijst met eigen PDO, scope, filters, paginatie en acties
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);
if (!$isMgr) {
    http_response_code(403);
    echo '<div class="alert alert-danger mt-3">Geen toegang.</div>';
    return;
}

/* ===== PDO bootstrap ===== */
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            require_once $f;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        }
    }
    $cfg = app_config(); $db = $cfg['db'] ?? []; $dsn = $db['dsn'] ?? null;
    if ($dsn) {
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } else {
        $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
        if ($name==='') throw new RuntimeException('DB-naam ontbreekt in config');
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    return $GLOBALS['pdo'] = $pdo;
}
$pdo = get_pdo();

/* ===== helpers ===== */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
function users_under(PDO $pdo, int $rootId): array {
    $ids = [$rootId];
    $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
    if (!$st || !$st->fetch()) return $ids;
    $queue = [$rootId]; $seen = [$rootId=>true];
    while ($queue) {
        $chunk = array_splice($queue, 0, 100);
        $params=[]; foreach ($chunk as $i=>$v) $params['p'.$i]=(int)$v;
        $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($params)));
        $sql = "SELECT id FROM users WHERE parent_user_id IN ($ph)";
        $st2 = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $st2->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st2->execute();
        foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $queue[]=$cid; }
        }
    }
    return $ids;
}
function in_named(array $ints, string $prefix='i'): array {
    $ints = array_values(array_unique(array_map('intval',$ints)));
    if (!$ints) return ['ph'=>'0','params'=>[]];
    $params=[]; foreach ($ints as $i=>$v) $params[$prefix.$i]=$v;
    $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($params)));
    return ['ph'=>$ph,'params'=>$params];
}
function order_status_label($s) {
    // accepteer zowel NL als EN varianten
    $map = [
        'concept' => 'Concept',
        'draft' => 'Concept',
        'awaiting_activation' => 'Wachten op activatie',
        'wachten_op_activatie' => 'Wachten op activatie',
        'cancelled' => 'Geannuleerd',
        'geannuleerd' => 'Geannuleerd',
        'completed' => 'Voltooid',
        'voltooid' => 'Voltooid',
    ];
    $s = strtolower((string)$s);
    return $map[$s] ?? $s;
}

/* ===== kolommen detecteren (dynamisch) ===== */
$hasOrders = [
    'order_no'           => column_exists($pdo,'orders','order_no'),
    'status'             => column_exists($pdo,'orders','status'),
    'customer_user_id'   => column_exists($pdo,'orders','customer_user_id'),
    'plan_id'            => column_exists($pdo,'orders','plan_id'),
    'sim_id'             => column_exists($pdo,'orders','sim_id'),
    'created_by_user_id' => column_exists($pdo,'orders','created_by_user_id'), // jouw “definitieve simpele regel”
    'created_at'         => column_exists($pdo,'orders','created_at'),
    'updated_at'         => column_exists($pdo,'orders','updated_at'),
];

$hasPlans = [
    'name'               => column_exists($pdo,'plans','name'),
];

$hasSims = [
    'iccid'              => column_exists($pdo,'sims','iccid'),
    'label'              => column_exists($pdo,'sims','label'),
];

$hasUsers = [
    'name'               => column_exists($pdo,'users','name'),
];

/* ===== filters ===== */
$q       = trim((string)($_GET['q'] ?? ''));
$statusQ = trim((string)($_GET['status'] ?? '')); // concept|awaiting_activation|cancelled|completed
$per     = (int)($_GET['per'] ?? 25); if (!in_array($per,[25,50,100],true)) $per = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$off     = ($page-1)*$per;

/* ===== SELECT + JOINs (alleen wat bestaat) ===== */
$select = ['o.id'];
if ($hasOrders['order_no'])           $select[] = 'o.order_no';
if ($hasOrders['status'])             $select[] = 'o.status';
if ($hasOrders['created_at'])         $select[] = 'o.created_at';

$joins = [];
if ($hasOrders['plan_id'] && $hasPlans['name']) {
    $joins[] = 'LEFT JOIN plans p ON p.id = o.plan_id';
    $select[] = 'p.name AS plan_name';
}
if ($hasOrders['sim_id']) {
    if ($hasSims['iccid']) $select[] = 's.iccid AS sim_iccid';
    if ($hasSims['label']) $select[] = 's.label AS sim_label';
    $joins[] = 'LEFT JOIN sims s ON s.id = o.sim_id';
}
if ($hasOrders['customer_user_id'] && $hasUsers['name']) {
    $joins[] = 'LEFT JOIN users cu ON cu.id = o.customer_user_id';
    $select[] = 'cu.name AS customer_name';
}
if ($hasOrders['created_by_user_id'] && $hasUsers['name']) {
    $joins[] = 'LEFT JOIN users cb ON cb.id = o.created_by_user_id';
    $select[] = 'cb.name AS created_by_name';
}
$selectSql = implode(', ', $select);
$joinSql   = implode(' ', $joins);

/* ===== WHERE (scope + zoek/filters) ===== */
$where = [];
$params = [];

// Scope:
// - Super: alles
// - Reseller/Sub: alle orders waarvan óf created_by_user_id óf customer_user_id in eigen boom zit (welke kolommen bestaan)
if (!$isSuper) {
    $ids = users_under($pdo, (int)$me['id']);
    $in  = in_named($ids,'u');
    $scopeParts = [];
    if ($hasOrders['created_by_user_id']) {
        $scopeParts[] = "o.created_by_user_id IN (".$in['ph'].")";
        foreach ($in['params'] as $k=>$v) $params[':cb_'.$k] = $v; // prefix om botsingen te voorkomen
    }
    if ($hasOrders['customer_user_id']) {
        // gebruik aparte parameter set
        $in2 = in_named($ids,'v');
        $scopeParts[] = "o.customer_user_id IN (".$in2['ph'].")";
        foreach ($in2['params'] as $k=>$v) $params[':cu_'.$k] = $v;
    }
    if ($scopeParts) {
        $where[] = '('.implode(' OR ', $scopeParts).')';
    } else {
        // als geen van beide kolommen bestaat: niks tonen voor niet-super
        $where[] = '0=1';
    }
}

// Zoek
$searchParts = [];
if ($q !== '') {
    if ($hasOrders['order_no']) $searchParts[] = "o.order_no LIKE :q";
    if (in_array('p.name AS plan_name', $select, true)) $searchParts[] = "p.name LIKE :q";
    if (in_array('s.iccid AS sim_iccid', $select, true)) $searchParts[] = "s.iccid LIKE :q";
    if (in_array('cu.name AS customer_name', $select, true)) $searchParts[] = "cu.name LIKE :q";
    if ($searchParts) {
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
        $params[':q'] = '%'.$q.'%';
    }
}

// Status-filter
if ($statusQ !== '' && $hasOrders['status']) {
    $where[] = "o.status = :st";
    $params[':st'] = $statusQ;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

/* ===== total ===== */
$total = 0;
$rows  = [];
$err   = '';

try {
    $sqlCount = "SELECT COUNT(*) FROM orders o $joinSql $whereSql";
    $st = $pdo->prepare($sqlCount);
    foreach ($params as $k=>$v) {
        $st->bindValue($k, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    // bind IN-lijsten (cb_/cu_)
    foreach ($params as $k=>$v) {
        if (str_starts_with($k, ':cb_u') || str_starts_with($k, ':cu_v')) {
            $st->bindValue($k, (int)$v, PDO::PARAM_INT);
        }
    }
    $st->execute();
    $total = (int)$st->fetchColumn();

    $sqlRows = "SELECT $selectSql FROM orders o $joinSql $whereSql ORDER BY o.id DESC LIMIT :lim OFFSET :off";
    $st2 = $pdo->prepare($sqlRows);
    foreach ($params as $k=>$v) {
        $st2->bindValue($k, is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);
    }
    foreach ($params as $k=>$v) {
        if (str_starts_with($k, ':cb_u') || str_starts_with($k, ':cu_v')) {
            $st2->bindValue($k, (int)$v, PDO::PARAM_INT);
        }
    }
    $st2->bindValue(':lim', $per, PDO::PARAM_INT);
    $st2->bindValue(':off', $off, PDO::PARAM_INT);
    $st2->execute();
    $rows = $st2->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $err = 'Laden mislukt: '.$e->getMessage();
}

/* ===== permissies helpers ===== */
function can_delete_order(array $me, array $row, bool $isSuper, bool $isRes): bool {
    if ($isSuper) return true;
    if ($isRes) {
        // reseller mag alleen eigen orders verwijderen (created_by_user_id == me)
        if (isset($row['_created_by_user_id'])) {
            return (int)$row['_created_by_user_id'] === (int)$me['id'];
        }
    }
    return false;
}

/* ===== Verrijk rows met raw created_by voor delete check (zonder extra JOINs als kolom bestaat) ===== */
if ($rows && $hasOrders['created_by_user_id']) {
    try {
        $ids = array_column($rows, 'id');
        $in = in_named($ids, 'o');
        $stC = $pdo->prepare("SELECT id, created_by_user_id FROM orders WHERE id IN (".$in['ph'].")");
        foreach ($in['params'] as $k=>$v) $stC->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $stC->execute();
        $map = [];
        foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['id']] = (int)$r['created_by_user_id'];
        foreach ($rows as &$r) {
            $r['_created_by_user_id'] = $map[(int)$r['id']] ?? null;
        }
        unset($r);
    } catch (Throwable $e) {
        // niet fataal
    }
}

/* ===== UI ===== */
?>
<h3>Bestellingen</h3>

<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-danger"><?= e((string)$_GET['error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['msg'])): ?>
  <div class="alert alert-success"><?= e((string)$_GET['msg']) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endif; ?>

<div class="mb-3">
  <form class="row g-2" method="get" action="index.php">
    <input type="hidden" name="route" value="orders_list">
    <div class="col-md-4">
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Zoek op ordernr / plan / SIM / klant">
    </div>
    <div class="col-md-3">
      <select name="status" class="form-select">
        <option value="">— alle statussen —</option>
        <?php
          $statuses = [
            'concept'              => 'Concept',
            'awaiting_activation'  => 'Wachten op activatie',
            'cancelled'            => 'Geannuleerd',
            'completed'            => 'Voltooid',
          ];
          foreach ($statuses as $k=>$lbl):
        ?>
          <option value="<?= e($k) ?>" <?= $statusQ===$k?'selected':'' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="per" class="form-select">
        <option value="25"  <?= $per===25?'selected':'' ?>>25</option>
        <option value="50"  <?= $per===50?'selected':'' ?>>50</option>
        <option value="100" <?= $per===100?'selected':'' ?>>100</option>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-outline-secondary" href="index.php?route=orders_list">Reset</a>
      <?php if ($isRes || $isSubRes): ?>
        <a class="btn btn-success ms-auto" href="index.php?route=order_add">Nieuwe bestelling</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead>
      <tr>
        <th>ID</th>
        <?php if ($hasOrders['order_no']): ?><th>Ordernr</th><?php endif; ?>
        <?php if ($hasOrders['status']): ?><th>Status</th><?php endif; ?>
        <?php if ($hasPlans['name']): ?><th>Abonnement</th><?php endif; ?>
        <th>SIM</th>
        <th>Klant</th>
        <?php if ($hasOrders['created_at']): ?><th>Aangemaakt</th><?php endif; ?>
        <th>Acties</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="10" class="text-center text-muted">Geen bestellingen gevonden.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <?php if ($hasOrders['order_no']): ?>
            <td><?= e($r['order_no'] ?? '') ?></td>
          <?php endif; ?>
          <?php if ($hasOrders['status']): ?>
            <?php $lbl = order_status_label($r['status'] ?? ''); ?>
            <td>
              <?php
                $badge = 'secondary';
                if ($lbl === 'Concept') $badge = 'warning';
                elseif ($lbl === 'Wachten op activatie') $badge = 'info';
                elseif ($lbl === 'Voltooid') $badge = 'success';
                elseif ($lbl === 'Geannuleerd') $badge = 'dark';
              ?>
              <span class="badge text-bg-<?= $badge ?>"><?= e($lbl) ?></span>
            </td>
          <?php endif; ?>
          <?php if ($hasPlans['name']): ?>
            <td><?= e($r['plan_name'] ?? '') ?></td>
          <?php endif; ?>
          <td>
            <?php
              $simTxt = '';
              if (!empty($r['sim_iccid'])) $simTxt = $r['sim_iccid'];
              elseif (!empty($r['sim_label'])) $simTxt = $r['sim_label'];
              echo $simTxt !== '' ? e($simTxt) : '<span class="text-muted">—</span>';
            ?>
          </td>
          <td><?= !empty($r['customer_name']) ? e($r['customer_name']) : '<span class="text-muted">—</span>' ?></td>
          <?php if ($hasOrders['created_at']): ?>
            <td><?= e($r['created_at'] ?? '') ?></td>
          <?php endif; ?>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="index.php?route=order_edit&id=<?= (int)$r['id'] ?>">Bekijken</a>
            <?php
              // Alleen concept-bewerkbaar? (optioneel)
              // Toon "Bewerken"-knop als status concept is en in scope
              if (($r['status'] ?? '') === 'concept') {
                  echo ' <a class="btn btn-sm btn-outline-secondary" href="index.php?route=order_edit&id='.(int)$r['id'].'&edit=1">Bewerken</a>';
              }
            ?>
            <?php if (can_delete_order($me, $r, $isSuper, $isRes)): ?>
              <form method="post" action="index.php?route=order_delete&id=<?= (int)$r['id'] ?>" class="d-inline" onsubmit="return confirm('Weet je zeker dat je deze bestelling wilt verwijderen?')">
                <?php csrf_field(); ?>
                <button class="btn btn-sm btn-outline-danger">Verwijderen</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php
// eenvoudige paginatie-helper uit helpers.php gebruiken
render_pagination($total, $per, $page, 'orders_list', ['q'=>$q, 'status'=>$statusQ, 'per'=>$per]);
?>
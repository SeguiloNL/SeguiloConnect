<?php
// pages/plans_list.php — robuust, met eigen PDO, zoek, badges en acties
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

// Alleen Super-admin
$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<div class="alert alert-danger mt-3">Geen toegang.</div>';
    return;
}

/* ===== PDO bootstrap ===== */
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    $candidates = [
        __DIR__ . '/../db.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../config/db.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            require_once $f;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        }
    }

    $cfg = app_config();
    $db  = $cfg['db'] ?? [];
    $dsn = $db['dsn'] ?? null;

    if ($dsn) {
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        $host    = $db['host'] ?? 'localhost';
        $name    = $db['name'] ?? $db['database'] ?? '';
        $user    = $db['user'] ?? $db['username'] ?? '';
        $pass    = $db['pass'] ?? $db['password'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';
        if ($name === '') throw new RuntimeException('DB-naam ontbreekt in config');
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
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

/* ===== filters & paginatie ===== */
$q     = trim((string)($_GET['q'] ?? ''));
$per   = (int)($_GET['per'] ?? 25); if (!in_array($per,[25,50,100],true)) $per = 25;
$page  = max(1, (int)($_GET['page'] ?? 1));
$off   = ($page-1)*$per;

/* ===== dynamische select ===== */
$has = [
  'description'                      => column_exists($pdo,'plans','description'),
  'buy_price_monthly_ex_vat'         => column_exists($pdo,'plans','buy_price_monthly_ex_vat'),
  'sell_price_monthly_ex_vat'        => column_exists($pdo,'plans','sell_price_monthly_ex_vat'),
  'buy_price_overage_per_mb_ex_vat'  => column_exists($pdo,'plans','buy_price_overage_per_mb_ex_vat'),
  'sell_price_overage_per_mb_ex_vat' => column_exists($pdo,'plans','sell_price_overage_per_mb_ex_vat'),
  'setup_fee_ex_vat'                 => column_exists($pdo,'plans','setup_fee_ex_vat'),
  'bundle_gb'                        => column_exists($pdo,'plans','bundle_gb'),
  'network_operator'                 => column_exists($pdo,'plans','network_operator'),
  'is_active'                        => column_exists($pdo,'plans','is_active'),
];

$selectCols = ['p.id','p.name','p.created_at'];
foreach ($has as $col=>$exists) {
    if ($exists) $selectCols[] = "p.`$col`";
}
$select = implode(', ', $selectCols);

/* ===== WHERE + params (alleen named placeholders) ===== */
$where = [];
$params = [];
if ($q !== '') {
    $searchParts = ["p.`name` LIKE :q"];
    if ($has['description'])       $searchParts[] = "p.`description` LIKE :q";
    if ($has['network_operator'])  $searchParts[] = "p.`network_operator` LIKE :q";
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $params[':q'] = '%'.$q.'%';
}
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* ===== total ===== */
$total = 0;
$err = '';
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `plans` p $whereSql");
    foreach ($params as $k=>$v) $st->bindValue($k,$v,PDO::PARAM_STR);
    $st->execute();
    $total = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $err = 'Laden mislukt: ' . $e->getMessage();
}

/* ===== fetch rows ===== */
$rows = [];
if (!$err) {
    try {
        $sql = "SELECT $select FROM `plans` p $whereSql ORDER BY p.id DESC LIMIT :lim OFFSET :off";
        $st = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $st->bindValue($k,$v,PDO::PARAM_STR);
        $st->bindValue(':lim', $per, PDO::PARAM_INT);
        $st->bindValue(':off', $off, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = 'Laden mislukt: ' . $e->getMessage();
    }
}

/* ===== UI ===== */
?>
<h3>Abonnementen</h3>

<?php if ($err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endif; ?>

<div class="mb-3">
  <form class="row g-2" method="get" action="index.php">
    <input type="hidden" name="route" value="plans_list">
    <div class="col-md-5">
      <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Zoek op naam / operator / omschrijving">
    </div>
    <div class="col-md-2">
      <select name="per" class="form-select">
        <option value="25"  <?= $per===25?'selected':'' ?>>25 per pagina</option>
        <option value="50"  <?= $per===50?'selected':'' ?>>50 per pagina</option>
        <option value="100" <?= $per===100?'selected':'' ?>>100 per pagina</option>
      </select>
    </div>
    <div class="col-md-5 d-flex gap-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-outline-secondary" href="index.php?route=plans_list">Reset</a>
      <a class="btn btn-success ms-auto" href="index.php?route=plan_add">Nieuw abonnement</a>
    </div>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead>
      <tr>
        <th>ID</th>
        <th>Naam</th>
        <?php if ($has['buy_price_monthly_ex_vat']): ?><th>Inkoopprijs (ex/maand)</th><?php endif; ?>
        <?php if ($has['sell_price_monthly_ex_vat']): ?><th>Verkoopprijs (ex/maand)</th><?php endif; ?>
        <?php if ($has['buy_price_overage_per_mb_ex_vat']): ?><th>Inkoop buiten bundel /MB (ex)</th><?php endif; ?>
        <?php if ($has['sell_price_overage_per_mb_ex_vat']): ?><th>Advies buiten bundel /MB (ex)</th><?php endif; ?>
        <?php if ($has['setup_fee_ex_vat']): ?><th>Setup (ex)</th><?php endif; ?>
        <?php if ($has['bundle_gb']): ?><th>Bundel (GB)</th><?php endif; ?>
        <?php if ($has['network_operator']): ?><th>Netwerk operator</th><?php endif; ?>
        <?php if ($has['is_active']): ?><th>Status</th><?php endif; ?>
        <th>Acties</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="20" class="text-center text-muted">Geen abonnementen gevonden.</td></tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td>
            <div class="fw-semibold"><?= e($r['name']) ?></div>
            <?php if ($has['description'] && !empty($r['description'])): ?>
              <div class="small text-muted"><?= e(mb_strimwidth($r['description'],0,120,'…','UTF-8')) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($has['buy_price_monthly_ex_vat']): ?>
            <td><?= e(number_format((float)($r['buy_price_monthly_ex_vat'] ?? 0), 2, ',', '.')) ?></td>
          <?php endif; ?>
          <?php if ($has['sell_price_monthly_ex_vat']): ?>
            <td><?= e(number_format((float)($r['sell_price_monthly_ex_vat'] ?? 0), 2, ',', '.')) ?></td>
          <?php endif; ?>
          <?php if ($has['buy_price_overage_per_mb_ex_vat']): ?>
            <td><?= e(number_format((float)($r['buy_price_overage_per_mb_ex_vat'] ?? 0), 4, ',', '.')) ?></td>
          <?php endif; ?>
          <?php if ($has['sell_price_overage_per_mb_ex_vat']): ?>
            <td><?= e(number_format((float)($r['sell_price_overage_per_mb_ex_vat'] ?? 0), 4, ',', '.')) ?></td>
          <?php endif; ?>
          <?php if ($has['setup_fee_ex_vat']): ?>
            <td><?= e(number_format((float)($r['setup_fee_ex_vat'] ?? 0), 2, ',', '.')) ?></td>
          <?php endif; ?>
          <?php if ($has['bundle_gb']): ?>
            <td><?= e((string)($r['bundle_gb'] ?? '')) ?></td>
          <?php endif; ?>
          <?php if ($has['network_operator']): ?>
            <td><?= e((string)($r['network_operator'] ?? '')) ?></td>
          <?php endif; ?>
          <?php if ($has['is_active']): ?>
            <td>
              <?php $active = (int)($r['is_active'] ?? 0) === 1; ?>
              <span class="badge <?= $active ? 'text-bg-success':'text-bg-secondary' ?>">
                <?= $active ? 'actief' : 'inactief' ?>
              </span>
            </td>
          <?php endif; ?>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="index.php?route=plan_edit&id=<?= (int)$r['id'] ?>">Bewerken</a>
            <a class="btn btn-sm btn-outline-secondary" href="index.php?route=plan_duplicate&id=<?= (int)$r['id'] ?>">Dupliceren</a>
            <form method="post" action="index.php?route=plan_delete&id=<?= (int)$r['id'] ?>" class="d-inline" onsubmit="return confirm('Weet je zeker dat je dit abonnement wilt verwijderen?')">
              <?php csrf_field(); ?>
              <button class="btn btn-sm btn-outline-danger">Verwijderen</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php render_pagination($total, $per, $page, 'plans_list', ['q'=>$q, 'per'=>$per]); ?>
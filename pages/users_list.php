<?php
// pages/users_list.php — gebruikersoverzicht met "Inloggen als" (POST + CSRF)
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

// ---- PDO ----
function get_pdo(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
  foreach ($candidates as $f) {
    if (is_file($f)) { require_once $f; if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; }
  }
  $cfg = app_config(); $db=$cfg['db']??[]; $dsn=$db['dsn']??null;
  if ($dsn) {
    $pdo = new PDO($dsn, $db['user']??null, $db['pass']??null, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
  } else {
    $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
    if ($name==='') throw new RuntimeException('DB-naam ontbreekt in config');
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=$charset",$user,$pass,[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
  }
  return $GLOBALS['pdo'] = $pdo;
}
$pdo = get_pdo();

// ---- helpers ----
function column_exists(PDO $pdo, string $table, string $column): bool {
  $q = $pdo->quote($column);
  $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
function users_under(PDO $pdo, int $rootId): array {
  // breadth-first: alle onderliggende id's
  $ids = [$rootId];
  $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
  if (!$st || !$st->fetch()) return $ids; // geen boom → alleen jezelf
  $queue = [$rootId]; $seen = [$rootId=>true];
  while ($queue) {
    $chunk = array_splice($queue,0,100);
    $ph = implode(',', array_fill(0,count($chunk),'?'));
    $st2 = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
    $st2->execute(array_map('intval',$chunk));
    foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $cid) {
      $cid=(int)$cid; if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $queue[]=$cid; }
    }
  }
  return $ids;
}

// ---- filters/paging ----
$q       = trim((string)($_GET['q'] ?? ''));
$roleF   = trim((string)($_GET['role'] ?? ''));
$per     = (int)($_GET['per'] ?? 25);
$allowedPer = [25,50,100,1000,5000];
if (!in_array($per,$allowedPer,true)) $per = 25;
$page    = max(1,(int)($_GET['page'] ?? 1));
$off     = ($page-1)*$per;

// ---- where/scope ----
$where = [];
$params = [];

if (!$isSuper) {
  $ids = users_under($pdo,(int)$me['id']);          // alles “onder” mij
  $ids[] = (int)$me['id'];                           // en ikzelf mag ik ook zien
  $in = implode(',', array_fill(0,count($ids),'?'));
  $where[] = "u.id IN ($in)";
  $params = array_merge($params, $ids);
}

if ($q !== '') {
  $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
  $params[] = "%$q%"; $params[] = "%$q%";
}
if ($roleF !== '') {
  $where[] = 'u.role = ?';
  $params[] = $roleF;
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// ---- tellen + ophalen ----
$total = 0; $rows = []; $err = '';
try {
  $st = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  // geen placeholders in LIMIT/OFFSET i.c.m. emulate prepares = false
  $per_i = (int)$per; $off_i = (int)$off;
  $sql = "SELECT u.id, u.name, u.email, u.role, u.is_active
          FROM users u
          $whereSql
          ORDER BY u.id DESC
          LIMIT $per_i OFFSET $off_i";
  $st2 = $pdo->prepare($sql);
  $st2->execute($params);
  $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $err = 'Kon gebruikerslijst niet laden: '.$e->getMessage();
}

// ---- UI helpers ----
function render_pagination_compact_users(int $total,int $per,int $page,array $q=[]): void {
  if ($total <= $per) return;
  $pages = max(1, (int)ceil($total/$per));
  $page  = max(1, min($page, $pages));
  $base='index.php?route=users_list';
  foreach ($q as $k=>$v) $base.='&'.rawurlencode($k).'='.rawurlencode((string)$v);
  $link = function(int $p,string $label=null) use($base){ $label=$label??(string)$p; return '<a class="page-link" href="'.$base.'&page='.$p.'">'.$label.'</a>'; };

  echo '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">';
  echo '<nav><ul class="pagination pagination-sm mb-0">';
  echo '<li class="page-item '.($page<=1?'disabled':'').'">'.$link(1,'&laquo;').'</li>';
  echo '<li class="page-item '.($page<=1?'disabled':'').'">'.$link(max(1,$page-1),'&lsaquo;').'</li>';

  $win=2; $start=max(1,$page-$win); $end=min($pages,$page+$win);
  if ($start>2) { echo '<li class="page-item">'.$link(1,'1').'</li><li class="page-item disabled"><span class="page-link">…</span></li>'; }
  elseif ($start===2) { echo '<li class="page-item">'.$link(1,'1').'</li>'; }

  for($p=$start;$p<=$end;$p++){
    if ($p===$page) echo '<li class="page-item active"><span class="page-link">'.$p.'</span></li>';
    else echo '<li class="page-item">'.$link($p,(string)$p).'</li>';
  }

  if ($end<$pages-1) { echo '<li class="page-item disabled"><span class="page-link">…</span></li><li class="page-item">'.$link($pages,(string)$pages).'</li>'; }
  elseif ($end===$pages-1) { echo '<li class="page-item">'.$link($pages,(string)$pages).'</li>'; }

  echo '<li class="page-item '.($page>=$pages?'disabled':'').'">'.$link(min($pages,$page+1),'&rsaquo;').'</li>';
  echo '<li class="page-item '.($page>=$pages?'disabled':'').'">'.$link($pages,'&raquo;').'</li>';
  echo '</ul></nav>';
  echo '<div class="small text-muted">Pagina <strong>'.$page.'</strong> van <strong>'.$pages.'</strong> — totaal <strong>'.$total.'</strong> gebruiker(s)</div>';
  echo '</div>';
}

// ---- UI ----
?>
<h3>Gebruikers</h3>

<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= e((string)$_GET['error']) ?></div><?php endif; ?>
<?php if (!empty($_GET['msg'])):   ?><div class="alert alert-success"><?= e((string)$_GET['msg'])   ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="mb-3">
  <form class="row g-2" method="get" action="index.php">
    <input type="hidden" name="route" value="users_list">
    <div class="col-md-4">
      <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Zoek op naam of e-mail">
    </div>
    <div class="col-md-3">
      <select name="role" class="form-select">
        <option value="">— alle rollen —</option>
        <option value="super_admin"   <?= $roleF==='super_admin'?'selected':''; ?>>Super-admin</option>
        <option value="reseller"      <?= $roleF==='reseller'?'selected':''; ?>>Reseller</option>
        <option value="sub_reseller"  <?= $roleF==='sub_reseller'?'selected':''; ?>>Sub-reseller</option>
        <option value="customer"      <?= $roleF==='customer'?'selected':''; ?>>Eindklant</option>
        <?php
          // indien ROLE_* constants gebruikt worden, desnoods extra opties tonen
          if (defined('ROLE_SUPER'))       echo '<option value="'.e(ROLE_SUPER).'" '.($roleF===ROLE_SUPER?'selected':'').'>Super-admin</option>';
          if (defined('ROLE_RESELLER'))    echo '<option value="'.e(ROLE_RESELLER).'" '.($roleF===ROLE_RESELLER?'selected':'').'>Reseller</option>';
          if (defined('ROLE_SUBRESELLER')) echo '<option value="'.e(ROLE_SUBRESELLER).'" '.($roleF===ROLE_SUBRESELLER?'selected':'').'>Sub-reseller</option>';
          if (defined('ROLE_CUSTOMER'))    echo '<option value="'.e(ROLE_CUSTOMER).'" '.($roleF===ROLE_CUSTOMER?'selected':'').'>Eindklant</option>';
        ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="per" class="form-select">
        <?php foreach ([25,50,100,1000,5000] as $opt): ?>
          <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-2">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-outline-secondary" href="index.php?route=users_list">Reset</a>
      <?php if ($isMgr): ?>
        <a class="btn btn-success ms-auto" href="index.php?route=user_add">Nieuwe gebruiker</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-sm table-hover align-middle">
    <thead>
      <tr>
        <th style="width:80px;">ID</th>
        <th>Naam</th>
        <th>E-mail</th>
        <th>Rol</th>
        <th>Status</th>
        <th style="width:260px;">Acties</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="text-center text-muted">Geen gebruikers gevonden.</td></tr>
      <?php else: foreach ($rows as $u): ?>
        <tr>
          <td><?= (int)$u['id'] ?></td>
          <td><?= e($u['name'] ?? '') ?></td>
          <td><?= e($u['email'] ?? '') ?></td>
          <td><?= e(role_label($u['role'] ?? '')) ?></td>
          <td>
            <?php $act = (int)($u['is_active'] ?? 1) === 1; ?>
            <span class="badge text-bg-<?= $act?'success':'secondary' ?>"><?= $act?'actief':'inactief' ?></span>
          </td>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="index.php?route=user_edit&id=<?= (int)$u['id'] ?>">Bewerken</a>
            <?php
              // Mag ik inloggen als deze gebruiker?
              $canImpersonate = false;
              if ($isSuper) {
                $canImpersonate = true;
              } elseif ($isRes || $isSubRes) {
                $tree = users_under($pdo,(int)$me['id']);
                $canImpersonate = in_array((int)$u['id'], $tree, true) && (int)$u['id'] !== (int)$me['id'];
              }
              if ($canImpersonate):
            ?>
              <form method="post" action="index.php?route=impersonate_start" class="d-inline">
  <?php csrf_field(); ?>
  <input type="hidden" name="impersonate_user_id" value="<?= (int)$u['id'] ?>">
  <button class="btn btn-sm btn-outline-secondary">Inloggen als</button>
</form>
            <?php endif; ?>

            <?php if ($isSuper): ?>
              <a class="btn btn-sm btn-outline-danger" href="index.php?route=user_delete&id=<?= (int)$u['id'] ?>" onclick="return confirm('Weet je zeker dat je deze gebruiker wilt verwijderen?');">Verwijderen</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php
render_pagination_compact_users($total,$per,$page,[
  'q'=>$q,'role'=>$roleF,'per'=>$per,
]);
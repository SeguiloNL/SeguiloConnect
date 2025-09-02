<?php
// pages/users_list.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

// ---------- DB ----------
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ---------- helpers ----------
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId]; $queue = [$rootId]; $seen = [$rootId=>true];
  while ($queue) {
    $chunk = array_splice($queue, 0, 200);
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

// ---------- filters ----------
$paramRole     = trim((string)($_GET['role'] ?? ''));
$paramIsActive = trim((string)($_GET['is_active'] ?? ''));
$q             = trim((string)($_GET['q'] ?? ''));

$allowedRoles = ['super_admin','reseller','sub_reseller','customer'];

// ---------- paginering ----------
$perPage = 100;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ---------- WHERE + scope ----------
$where = [];
$args  = [];

// scope: super → geen beperking; reseller/sub → eigen boom
$scopeSql = '';
$scopeArgs = [];
if (!$isSuper) {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if ($tree) {
    $ph = implode(',', array_fill(0, count($tree), '?'));
    $scopeSql = "u.id IN ($ph)";
    $scopeArgs = $tree;
    $where[] = $scopeSql;
  }
}

// filters
if ($paramRole !== '' && in_array($paramRole, $allowedRoles, true)) {
  $where[] = "u.role = ?";
  $args[]  = $paramRole;
}
if ($paramIsActive !== '') {
  if ($paramIsActive === '1' || $paramIsActive === '0') {
    $where[] = "u.is_active = ?";
    $args[]  = (int)$paramIsActive;
  }
}
if ($q !== '') {
  $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
  $args[] = '%'.$q.'%';
  $args[] = '%'.$q.'%';
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// ---------- total ----------
try {
  $sqlCnt = "SELECT COUNT(*) FROM users u {$whereSql}";
  $stCnt = $pdo->prepare($sqlCnt);
  $stCnt->execute(array_merge($scopeArgs, $args));
  $total = (int)$stCnt->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: '.e($e->getMessage()).'</div>';
  return;
}
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// ---------- data ----------
try {
  $sql = "SELECT u.id, u.name, u.email, u.role, u.is_active, u.parent_user_id
          FROM users u
          {$whereSql}
          ORDER BY u.id DESC
          LIMIT {$perPage} OFFSET {$offset}";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($scopeArgs, $args));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Gebruikers laden mislukt: '.e($e->getMessage()).'</div>';
  return;
}

// Mapping parent names voor weergave
$parentNames = [];
if ($rows) {
  $parentIds = array_unique(array_filter(array_map('intval', array_column($rows,'parent_user_id'))));
  if ($parentIds) {
    $ph = implode(',', array_fill(0, count($parentIds), '?'));
    $stp = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($ph)");
    $stp->execute($parentIds);
    $parentNames = $stp->fetchAll(PDO::FETCH_KEY_PAIR); // [id => name]
  }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Gebruikers</h4>
  <?php if ($isMgr): ?>
    <a class="btn btn-primary" href="index.php?route=user_add">Nieuwe gebruiker</a>
  <?php endif; ?>
</div>

<?= function_exists('flash_output') ? flash_output() : '' ?>

<!-- Filters -->
<form class="row g-2 mb-3" method="get" action="index.php">
  <input type="hidden" name="route" value="users_list">
  <div class="col-12 col-md-3">
    <label class="form-label mb-1">Rol</label>
    <select class="form-select" name="role">
      <option value="">— alle rollen —</option>
      <?php foreach ($allowedRoles as $r): ?>
        <option value="<?= e($r) ?>" <?= $paramRole===$r ? 'selected' : '' ?>>
          <?= e($r) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-12 col-md-3">
    <label class="form-label mb-1">Actief</label>
    <select class="form-select" name="is_active">
      <option value="">— alle —</option>
      <option value="1" <?= $paramIsActive==='1' ? 'selected' : '' ?>>Actief</option>
      <option value="0" <?= $paramIsActive==='0' ? 'selected' : '' ?>>Inactief</option>
    </select>
  </div>
  <div class="col-12 col-md-4">
    <label class="form-label mb-1">Zoek</label>
    <input class="form-control" type="text" name="q" value="<?= e($q) ?>" placeholder="Naam of e-mail">
  </div>
  <div class="col-12 col-md-2 d-flex align-items-end">
    <button class="btn btn-outline-secondary w-100">Filter</button>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="alert alert-info">Geen gebruikers gevonden.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th style="width:80px;">ID</th>
          <th>Naam</th>
          <th>E-mail</th>
          <th>Rol</th>
          <th>Actief</th>
          <th>Parent</th>
          <th style="width:320px;">Acties</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $u): ?>
        <?php
          $uid   = (int)$u['id'];
          $inTree = true;
          if (!$isSuper) {
            // Als we hier komen, is al via WHERE gescope’d; extra check is defensief:
            $inTree = true;
          }
          $canEdit = $isSuper || $inTree;
          // Verwijderen alleen super; (wil je reseller ook laten verwijderen binnen scope, zet $canDelete = $isSuper || $inTree;)
          $canDelete = $isSuper;
          $canImpersonate = $isSuper || ($inTree && $uid !== (int)$me['id']);
        ?>
        <tr>
          <td><?= $uid ?></td>
          <td><?= e($u['name'] ?? '') ?></td>
          <td><?= e($u['email'] ?? '') ?></td>
          <td><?= e($u['role'] ?? '') ?></td>
          <td><?= ((int)($u['is_active'] ?? 0) === 1) ? 'Ja' : 'Nee' ?></td>
          <td>
            <?php
              $pid = (int)($u['parent_user_id'] ?? 0);
              echo $pid ? e("#$pid — ".($parentNames[$pid] ?? 'onbekend')) : '<span class="text-muted">—</span>';
            ?>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-2">
              <?php if ($canEdit): ?>
                <a class="btn btn-outline-secondary btn-sm" href="index.php?route=user_edit&id=<?= $uid ?>">Bewerken</a>
              <?php endif; ?>

              <?php if ($canImpersonate): ?>
                <form method="post" action="index.php?route=impersonate_start" class="d-inline">
                  <?php if (function_exists('csrf_field')) csrf_field(); ?>
                  <input type="hidden" name="user_id" value="<?= $uid ?>">
                  <button class="btn btn-outline-primary btn-sm" type="submit">Inloggen als</button>
                </form>
              <?php endif; ?>

              <?php if ($canDelete): ?>
                <form method="post" action="index.php?route=user_delete" class="d-inline" onsubmit="return confirm('Weet je zeker dat je deze gebruiker wil verwijderen?');">
                  <?php if (function_exists('csrf_field')) csrf_field(); ?>
                            <input type="hidden" name="id" value="<?= $uid ?>">
                            <button class="btn btn-outline-danger btn-sm" type="submit">Verwijderen</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginering -->
  <?php if ($totalPages > 1): ?>
    <nav aria-label="Paginering">
      <ul class="pagination mt-3">
        <?php
          // bouw querystring zonder page
          $qs = $_GET;
          unset($qs['page']);
          $base = 'index.php?'.http_build_query(array_merge(['route'=>'users_list'], $qs));
          $mk = function(int $p, string $label = null, bool $disabled=false, bool $active=false) use ($base) {
            $label = $label ?? (string)$p;
            $cls = 'page-item';
            if ($disabled) $cls .= ' disabled';
            if ($active)   $cls .= ' active';
            $href = $base.($base ? '&' : '').'page='.$p;
            return '<li class="'.$cls.'"><a class="page-link" href="'.$href.'">'.$label.'</a></li>';
          };
          echo $mk(max(1,$page-1), '‹', $page<=1);
          $window = 2;
          $start  = max(1, $page - $window);
          $end    = min($totalPages, $page + $window);
          if ($start > 1) {
            echo $mk(1, '1', false, $page===1);
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for ($p=$start; $p<=$end; $p++) echo $mk($p, (string)$p, false, $p===$page);
          if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo $mk($totalPages, (string)$totalPages, false, $page===$totalPages);
          }
          echo $mk(min($totalPages,$page+1), '›', $page>=$totalPages);
        ?>
      </ul>
    </nav>
  <?php endif; ?>
<?php endif; ?>
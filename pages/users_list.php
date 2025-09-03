<?php
// pages/users_list.php — Overzicht (Reseller / Sub-reseller / Eindklant) met scope + paginering + icoon-acties
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$myId  = (int)($me['id'] ?? 0);
$role  = $me['role'] ?? '';
$isSuper   = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes     = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes  = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

/**
 * Geef alle id's in de “boom” van $rootId (gebruikt users.parent_user_id).
 * Retourneert array met ook de root zelf.
 */
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId];
  $queue = [$rootId];
  $seen = [$rootId => true];
  while ($queue) {
    $chunk = array_splice($queue, 0, 200);
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

function role_label_local(string $role): string {
  return match ($role) {
    'reseller'     => 'Reseller',
    'sub_reseller' => 'Sub-reseller',
    'customer'     => 'Eindklant',
    default        => $role,
  };
}

// ---- filters / paginering
$allowedRoles = ['reseller','sub_reseller','customer'];
$filterRole = isset($_GET['role']) ? strtolower(trim((string)$_GET['role'])) : '';
if (!in_array($filterRole, $allowedRoles, true)) $filterRole = '';

if ($isSubRes) {
  // Sub-reseller mag ALLEEN eindklanten zien
  $filterRole = 'customer';
}

$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25,50,100], true)) $perPage = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// ---- WHERE samenstellen (geen super_admin, geen jezelf)
$where = [];
$params = [];

// rolfilter
if ($filterRole !== '') {
  $where[] = "role = ?";
  $params[] = $filterRole;
} else {
  // standaard alle drie
  $in = implode(',', array_fill(0, count($allowedRoles), '?'));
  $where[] = "role IN ($in)";
  array_push($params, ...$allowedRoles);
}
// jezelf niet tonen (optioneel netter)
$where[] = "id <> ?";
$params[] = $myId;

// scope
if (!$isSuper) {
  $scopeIds = build_tree_ids($pdo, $myId);
  if (!$scopeIds) $scopeIds = [$myId];
  $ph = implode(',', array_fill(0, count($scopeIds), '?'));
  $where[] = "id IN ($ph)";
  array_push($params, ...$scopeIds);
}

$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

// ---- totaal
try {
  $sqlCount = "SELECT COUNT(*) FROM users $whereSql";
  $stc = $pdo->prepare($sqlCount);
  $stc->execute($params);
  $total = (int)$stc->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: '.e($e->getMessage()).'</div>'; return;
}

$totalPages = max(1, (int)ceil($total / $perPage));

// ---- rows
try {
  $sql = "SELECT id,name,email,role,is_active,parent_user_id
          FROM users
          $whereSql
          ORDER BY name ASC, id ASC
          LIMIT $perPage OFFSET $offset";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// ---- permissies voor acties
// impersonate: super-admin of reseller (binnen scope). Sub-reseller: niet.
$canImpersonate = function(array $row) use ($isSuper, $isRes, $isSubRes, $myId, $pdo): bool {
  if ($row['id'] == $myId) return false; // niet jezelf
  if ($isSuper) return true;
  if ($isRes) {
    $scope = build_tree_ids($pdo, $myId);
    return in_array((int)$row['id'], $scope, true);
  }
  return false;
};
// delete: super mag alles (behalve super_admin zie je hier toch niet); reseller binnen scope; sub-reseller alleen customer binnen scope
$canDelete = function(array $row) use ($isSuper, $isRes, $isSubRes, $myId, $pdo): bool {
  if ($row['id'] == $myId) return false; // niet jezelf
  if ($isSuper) return true;
  $scope = build_tree_ids($pdo, $myId);
  if (!in_array((int)$row['id'], $scope, true)) return false;
  if ($isRes) return true;
  if ($isSubRes) return ($row['role'] === 'customer');
  return false;
};

// ---- helpers UI
function users_list_url_keep(array $extra): string {
  $base = 'index.php?route=users_list';
  $qs = array_merge($_GET, $extra);
  return $base.'&'.http_build_query($qs);
}

echo function_exists('flash_output') ? flash_output() : '';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4>Gebruikers</h4>
  <div class="d-flex align-items-center gap-2">
    <!-- per page -->
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="users_list">
      <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= e($filterRole) ?>"><?php endif; ?>
      <label class="form-label m-0">Per pagina</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ([25,50,100] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- filter rol -->
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="users_list">
      <label class="form-label m-0">Rol</label>
      <select name="role" class="form-select form-select-sm" onchange="this.form.submit()" <?= $isSubRes ? 'disabled' : '' ?>>
        <option value="" <?= $filterRole===''?'selected':'' ?>>Alle</option>
        <option value="reseller"     <?= $filterRole==='reseller'?'selected':'' ?>>Reseller</option>
        <option value="sub_reseller" <?= $filterRole==='sub_reseller'?'selected':'' ?>>Sub-reseller</option>
        <option value="customer"     <?= $filterRole==='customer'?'selected':'' ?>>Eindklant</option>
      </select>
      <?php if ($isSubRes): ?>
        <input type="hidden" name="role" value="customer">
      <?php endif; ?>
      <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
    </form>

    <?php if ($isSuper || $isRes || $isSubRes): ?>
      <a href="index.php?route=user_add" class="btn btn-primary btn-sm">Nieuwe gebruiker</a>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if ($total === 0): ?>
      <div class="text-muted">Geen gebruikers gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th>Naam</th>
              <th>E-mail</th>
              <th>Rol</th>
              <th>Status</th>
              <th class="text-end" style="width:140px;">Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= e($r['name'] ?: '—') ?></td>
                <td><?= e($r['email'] ?: '—') ?></td>
                <td><?= e(role_label_local((string)$r['role'])) ?></td>
                <td>
                  <?= ((int)$r['is_active'] === 1)
                        ? '<span class="badge bg-success">Actief</span>'
                        : '<span class="badge bg-secondary">Inactief</span>' ?>
                </td>
                <td class="text-end">
                  <!-- Bewerken -->
                  <a class="btn btn-sm btn-outline-primary" title="Bewerken"
                     href="index.php?route=user_edit&id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <!-- Inloggen als (alleen super + reseller binnen scope) -->
                  <?php if ($canImpersonate($r)): ?>
                    <form method="post" action="index.php?route=impersonate_start" class="d-inline">
                      <?php if (function_exists('csrf_field')) csrf_field(); ?>
                      <input type="hidden" name="target_user_id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary" title="Inloggen als">
                        <i class="bi bi-person"></i>
                      </button>
                    </form>
                  <?php endif; ?>

                  <!-- Verwijderen (rood) -->
                  <?php if ($canDelete($r)): ?>
                    <form method="post" action="index.php?route=user_delete" class="d-inline"
                          onsubmit="return confirm('Gebruiker verwijderen? Deze actie kan niet ongedaan worden gemaakt.');">
                      <?php if (function_exists('csrf_field')) csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" title="Verwijderen">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Paginering -->
      <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small">
          Totaal: <?= (int)$total ?> · Pagina <?= (int)$page ?> van <?= (int)$totalPages ?>
        </div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php
              $prevDisabled = ($page <= 1) ? ' disabled' : '';
              $nextDisabled = ($page >= $totalPages) ? ' disabled' : '';
              $baseQs = $_GET;
              $baseQs['route'] = 'users_list';
              $baseQs['per_page'] = $perPage;
              if ($filterRole) $baseQs['role'] = $filterRole;
            ?>
            <li class="page-item<?= $prevDisabled ?>">
              <a class="page-link" href="<?= $page > 1 ? ('index.php?'.http_build_query(array_merge($baseQs,['page'=>$page-1]))) : '#' ?>">Vorige</a>
            </li>
            <?php
              $window = 2;
              $start = max(1, $page - $window);
              $end   = min($totalPages, $page + $window);
              if ($start > 1) {
                echo '<li class="page-item"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>1])).'">1</a></li>';
                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              }
              for ($p=$start; $p<=$end; $p++) {
                $active = ($p === $page) ? ' active' : '';
                echo '<li class="page-item'.$active.'"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>$p])).'">'.$p.'</a></li>';
              }
              if ($end < $totalPages) {
                if ($end < $totalPages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                echo '<li class="page-item"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>$totalPages])).'">'.$totalPages.'</a></li>';
              }
            ?>
            <li class="page-item<?= $nextDisabled ?>">
              <a class="page-link" href="<?= $page < $totalPages ? ('index.php?'.http_build_query(array_merge($baseQs,['page'=>$page+1]))) : '#' ?>">Volgende</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>
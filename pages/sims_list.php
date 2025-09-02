<?php
// pages/sims_list.php
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
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo, 'users', 'parent_user_id')) return [$rootId];
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
/** Geef lijst met doelgebruikers (reseller, sub_reseller, customer) binnen scope. */
function fetch_assignable_users(PDO $pdo, array $me, bool $isSuper, bool $isRes, bool $isSubRes): array {
  $hasRole = column_exists($pdo,'users','role');
  $hasParent = column_exists($pdo,'users','parent_user_id');
  // Rollen die we toestaan als doel
  $allowedRoles = ['reseller','sub_reseller','customer'];

  if ($isSuper) {
    if ($hasRole) {
      $ph = implode(',', array_fill(0, count($allowedRoles), '?'));
      $st = $pdo->prepare("SELECT id,name,role FROM users WHERE role IN ($ph) ORDER BY name");
      $st->execute($allowedRoles);
      return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $pdo->query("SELECT id,name,NULL AS role FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }

  // Reseller/Sub-reseller → alleen binnen eigen boom
  $tree = $hasParent ? build_tree_ids($pdo, (int)$me['id']) : [(int)$me['id']];
  $phTree = implode(',', array_fill(0, count($tree), '?'));

  if ($hasRole) {
    $phRoles = implode(',', array_fill(0, count($allowedRoles), '?'));
    $sql = "SELECT id,name,role FROM users 
            WHERE id IN ($phTree) AND role IN ($phRoles)
            ORDER BY name";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge($tree, $allowedRoles));
  } else {
    $st = $pdo->prepare("SELECT id,name,NULL AS role FROM users WHERE id IN ($phTree) ORDER BY name");
    $st->execute($tree);
  }
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // plus: jezelf als doel (handig voor eigen voorraad)
  $self = ['id'=>(int)$me['id'], 'name'=>$me['name'] ?? '—', 'role'=>$me['role'] ?? null];
  $in = array_map('intval', array_column($rows,'id'));
  if (!in_array($self['id'], $in, true)) array_unshift($rows, $self);

  return $rows;
}

// ---------- paginering ----------
$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// ---------- scope ----------
$scopeWhere = '';
$scopeArgs  = [];
$hasAssignedCol = column_exists($pdo,'sims','assigned_to_user_id');

if (!$isSuper && $hasAssignedCol) {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if ($tree) {
    $ph = implode(',', array_fill(0, count($tree), '?'));
    // Res/Sub: alleen eigen boom of voorraad (NULL)
    $scopeWhere = " WHERE (s.assigned_to_user_id IS NULL OR s.assigned_to_user_id IN ($ph))";
    $scopeArgs  = $tree;
  }
}

// ---------- total count ----------
try {
  $sqlCnt = "SELECT COUNT(*) AS cnt FROM sims s" . $scopeWhere;
  $stCnt = $pdo->prepare($sqlCnt);
  $stCnt->execute($scopeArgs);
  $total = (int)$stCnt->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: '.e($e->getMessage()).'</div>';
  return;
}
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

// ---------- data ----------
try {
  $sql = "SELECT s.*,
                 u.id   AS assigned_to_user_id,
                 u.name AS assigned_name
          FROM sims s
          LEFT JOIN users u ON u.id = s.assigned_to_user_id"
        . $scopeWhere .
        " ORDER BY s.id DESC
          LIMIT {$perPage} OFFSET {$offset}";
  $st = $pdo->prepare($sql);
  $st->execute($scopeArgs);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
  return;
}

// ---------- doelgebruikers dropdown ----------
$assignableUsers = $isMgr ? fetch_assignable_users($pdo, $me, $isSuper, $isRes, $isSubRes) : [];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Simkaarten</h4>
  <?php if ($isSuper): ?>
    <a class="btn btn-primary" href="index.php?route=sim_add">Nieuwe simkaart toevoegen</a>
  <?php endif; ?>
</div>

<?= function_exists('flash_output') ? flash_output() : '' ?>

<?php if (!$rows): ?>
  <div class="alert alert-info">Geen simkaarten gevonden.</div>
<?php else: ?>
  <form method="post" action="index.php?route=sim_bulk_action" id="bulkForm">
    <?php if (function_exists('csrf_field')) csrf_field(); ?>

    <?php if ($isMgr): ?>
      <!-- Bulk-assign: kies doelgebruiker -->
      <div class="row g-3 align-items-end mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label mb-1">Toewijzen aan</label>
          <select class="form-select" name="target_user_id" id="bulkTarget">
            <option value="">— kies gebruiker —</option>
            <?php foreach ($assignableUsers as $u): ?>
              <option value="<?= (int)$u['id'] ?>">
                #<?= (int)$u['id'] ?> — <?= e($u['name']) ?><?php if (!empty($u['role'])): ?> (<?= e($u['role']) ?>)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Resellers/sub-resellers zien alleen gebruikers binnen eigen beheer.</div>
        </div>
        <div class="col-12 col-md-auto">
          <div class="btn-group" role="group">
            <button type="submit" name="action" value="assign"   class="btn btn-primary">Bulk toewijzen</button>
            <button type="submit" name="action" value="unassign" class="btn btn-outline-secondary">Bulk naar voorraad</button>
            <?php if ($isSuper): ?>
              <button type="submit" name="action" value="delete" class="btn btn-danger"
                onclick="return confirm('Weet je zeker dat je de geselecteerde simkaarten wil verwijderen?');">
                Verwijder geselecteerde
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th style="width:28px;"><input type="checkbox" id="selectAll"></th>
            <th style="width:70px;">ID</th>
            <th>ICCID</th>
            <th>IMSI</th>
            <th>PIN</th>
            <th>PUK</th>
            <th>Status</th>
            <th>Toegewezen aan</th>
            <th style="width:340px;">Acties</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $sid=(int)$r['id']; ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= $sid ?>"></td>
            <td><?= $sid ?></td>
            <td><?= e($r['iccid'] ?? '') ?></td>
            <td><?= e($r['imsi'] ?? '') ?></td>
            <td><?= e($r['pin'] ?? '') ?></td>
            <td><?= e($r['puk'] ?? '') ?></td>
            <td><?= e($r['status'] ?? '') ?></td>
            <td>
              <?php
                $uid   = $r['assigned_to_user_id'] ?? null;
                $uname = $r['assigned_name'] ?? '';
                if ($uid) {
                  echo e('#'.$uid.' — '.($uname !== '' ? $uname : '(naam onbekend)'));
                } else {
                  echo '<span class="text-muted">—</span>';
                }
              ?>
            </td>
            <td>
              <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php if ($isMgr): ?>
                  <!-- Toewijzen per rij -->
                  <div class="input-group input-group-sm" style="max-width: 360px;">
                    <select class="form-select" id="rowTarget-<?= $sid ?>">
                      <option value="">— kies gebruiker —</option>
                      <?php foreach ($assignableUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>">
                          #<?= (int)$u['id'] ?> — <?= e($u['name']) ?><?php if (!empty($u['role'])): ?> (<?= e($u['role']) ?>)<?php endif; ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="button"
                      onclick="assignSingle(<?= $sid ?>)">Toewijzen</button>
                    <button class="btn btn-outline-secondary" type="button"
                      onclick="unassignSingle(<?= $sid ?>)">Naar voorraad</button>
                  </div>
                <?php endif; ?>

                <div class="btn-group btn-group-sm" role="group">
                  <?php if ($isSuper): ?>
                    <a class="btn btn-outline-secondary" href="index.php?route=sim_edit&id=<?= $sid ?>">Bewerken</a>
                    <form method="post" action="index.php?route=sim_delete" onsubmit="return confirm('Weet je zeker dat je deze simkaart wil verwijderen?');" class="d-inline">
                      <?php if (function_exists('csrf_field')) csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= $sid ?>">
                      <button class="btn btn-outline-danger">Verwijderen</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginering -->
    <?php
      $queryBase = 'index.php?route=sims_list';
    ?>
    <?php if ($totalPages > 1): ?>
      <nav aria-label="Paginering">
        <ul class="pagination mt-3">
          <?php
            $window = 2;
            $start  = max(1, $page - $window);
            $end    = min($totalPages, $page + $window);
            $mk = function(int $p, string $label = null, bool $disabled=false, bool $active=false) use ($queryBase) {
              $label = $label ?? (string)$p;
              $cls = 'page-item';
              if ($disabled) $cls .= ' disabled';
              if ($active)   $cls .= ' active';
              $href = $queryBase.'&page='.$p;
              return '<li class="'.$cls.'"><a class="page-link" href="'.$href.'">'.$label.'</a></li>';
            };
            echo $mk(max(1,$page-1), '‹', $page<=1);
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

    <!-- Verborgen inputs voor single-row acties -->
    <input type="hidden" name="do_action" id="bulkAction" value="">
    <input type="hidden" name="single_id" id="singleId" value="">
  </form>
<?php endif; ?>

<script>
// Select all
document.getElementById('selectAll')?.addEventListener('change', function(e) {
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = e.target.checked);
});

// Single-row assign/unassign via bulk endpoint
function assignSingle(simId) {
  const sel = document.getElementById('rowTarget-' + simId);
  const val = sel ? sel.value : '';
  if (!val) {
    alert('Kies eerst een doelgebruiker in de dropdown.');
    return;
  }
  // maak selectie leeg en voeg enkel deze ID toe
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = false);
  addSingleId(simId);
  setActionAndTarget('assign', val);
  document.getElementById('bulkForm').submit();
}
function unassignSingle(simId) {
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = false);
  addSingleId(simId);
  setActionAndTarget('unassign', '');
  document.getElementById('bulkForm').submit();
}
function addSingleId(simId) {
  let cb = Array.from(document.querySelectorAll('input[name="ids[]"]')).find(c => parseInt(c.value,10) === simId);
  if (!cb) {
    cb = document.createElement('input');
    cb.type = 'checkbox';
    cb.name = 'ids[]';
    cb.value = String(simId);
    cb.checked = true;
    cb.hidden = true;
    document.getElementById('bulkForm').appendChild(cb);
  } else {
    cb.checked = true;
  }
}
function setActionAndTarget(action, targetUserId) {
  document.getElementById('bulkAction').value = action; // let op: do_action
  // zet (of maak) een target_user_id input voor single-row assign
  let t = document.querySelector('select[name="target_user_id"]');
  if (!t) {
    t = document.createElement('input');
    t.type = 'hidden';
    t.name = 'target_user_id';
    document.getElementById('bulkForm').appendChild(t);
  }
  if (t.tagName.toLowerCase() === 'select') {
    if (targetUserId) t.value = targetUserId;
  } else {
    t.value = targetUserId || '';
  }
}
</script>
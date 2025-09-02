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

// ---------- UI ----------
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

    <!-- Bulk-acties -->
    <?php if ($isMgr): ?>
      <div class="row g-3 align-items-end mb-3">
        <div class="col-12 col-md-auto">
          <label class="form-label mb-1">Toewijzen aan (user ID)</label>
          <input type="number" class="form-control" name="target_user_id" placeholder="Bijv. 42">
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
            <th style="width:260px;">Acties</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
            <td><?= (int)$r['id'] ?></td>
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
              <div class="btn-group btn-group-sm" role="group">
                <?php if ($isSuper): ?>
                  <a class="btn btn-outline-secondary" href="index.php?route=sim_edit&id=<?= (int)$r['id'] ?>">Bewerken</a>
                  <form method="post" action="index.php?route=sim_delete" onsubmit="return confirm('Weet je zeker dat je deze simkaart wil verwijderen?');" class="d-inline">
                    <?php if (function_exists('csrf_field')) csrf_field(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-outline-danger">Verwijderen</button>
                  </form>
                <?php endif; ?>
                <?php if ($isMgr): ?>
                  <a class="btn btn-outline-primary" href="index.php?route=sim_assign&sim_id=<?= (int)$r['id'] ?>">Toewijzen</a>
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
            // eenvoudige window
            $window = 2;
            $start  = max(1, $page - $window);
            $end    = min($totalPages, $page + $window);
            $mk = function(int $p, string $label = null, bool $disabled=false, bool $active=false) {
              $label = $label ?? (string)$p;
              $cls = 'page-item';
              if ($disabled) $cls .= ' disabled';
              if ($active)   $cls .= ' active';
              $href = 'index.php?route=sims_list&page='.$p;
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

    <!-- Bulk actie bevestiging onderaan (optioneel extra knoppen) -->
    <?php if ($isMgr): ?>
      <div class="mt-3 d-flex flex-wrap gap-2">
        <button type="submit" name="action" value="assign"   class="btn btn-primary">Bulk toewijzen</button>
        <button type="submit" name="action" value="unassign" class="btn btn-outline-secondary">Bulk naar voorraad</button>
        <?php if ($isSuper): ?>
          <button type="submit" name="action" value="delete" class="btn btn-danger"
            onclick="return confirm('Weet je zeker dat je de geselecteerde simkaarten wil verwijderen?');">
            Verwijder geselecteerde
          </button>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </form>
<?php endif; ?>

<script>
// Select all
document.getElementById('selectAll')?.addEventListener('change', function(e) {
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = e.target.checked);
});
</script>
<?php
// pages/orders_list.php — Bestellingen met filters, acties en paginering
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$myId = (int)($me['id'] ?? 0);
$role = (string)($me['role'] ?? '');
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

// Alleen super/res/sub
if (!$isSuper && !$isRes && !$isSubRes) {
  echo '<div class="alert alert-danger">Je hebt geen toegang tot deze pagina.</div>';
  return;
}

// --- DB
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// --- helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

/** Bouw alle user-ids in de boom van $rootId (inclusief root), via users.parent_user_id */
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
      if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $queue[]=$cid; }
    }
  }
  return $ids;
}

function status_label(string $s): string {
  return match ($s) {
    'concept'             => 'Concept',
    'awaiting_activation' => 'Wachten op activatie',
    'completed'           => 'Voltooid',
    'cancelled'           => 'Geannuleerd',
    default               => ucfirst($s),
  };
}

echo function_exists('flash_output') ? flash_output() : '';

// --- Filters (status)
$validStatus = ['all','concept','awaiting_activation','completed','cancelled'];
$status = strtolower((string)($_GET['status'] ?? 'all'));
if (!in_array($status, $validStatus, true)) $status = 'all';

// --- Paginering
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25,50,100], true)) $perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// --- WHERE + scope
$where = [];
$params = [];

// Status
if ($status !== 'all') {
  $where[] = "o.status = ?";
  $params[] = $status;
}

// Scope op klant (customer_id in boom)
if (!$isSuper) {
  $scopeIds = build_tree_ids($pdo, $myId);
  if (!$scopeIds) $scopeIds = [$myId];
  $ph = implode(',', array_fill(0, count($scopeIds), '?'));
  $where[] = "o.customer_id IN ($ph)";
  array_push($params, ...$scopeIds);
}

// WHERE compose
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// --- Count
try {
  $sqlCount = "SELECT COUNT(*)
               FROM orders o
               $whereSql";
  $stc = $pdo->prepare($sqlCount);
  $stc->execute($params);
  $total = (int)$stc->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: '.e($e->getMessage()).'</div>'; return;
}
$totalPages = max(1, (int)ceil($total / $perPage));

// --- Fetch rows
try {
  $sql = "SELECT
            o.id,
            o.customer_id,
            o.sim_id,
            o.plan_id,
            o.status,
            o.created_at,
            u.name   AS customer_name,
            s.iccid  AS sim_iccid,
            p.name   AS plan_name
          FROM orders o
          LEFT JOIN users u ON u.id = o.customer_id
          LEFT JOIN sims  s ON s.id = o.sim_id
          LEFT JOIN plans p ON p.id = o.plan_id
          $whereSql
          ORDER BY o.created_at DESC, o.id DESC
          LIMIT $perPage OFFSET $offset";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// --- URL helper
function orders_list_url_keep(array $extra): string {
  $base = 'index.php?route=orders_list';
  $qs = array_merge($_GET, $extra);
  return $base.'&'.http_build_query($qs);
}
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4>Bestellingen</h4>
  <div class="d-flex align-items-center gap-2">
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="orders_list">
      <label class="form-label m-0">Per pagina</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ([25,50,100] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <input type="hidden" name="page" value="1">
    </form>

    <a href="index.php?route=order_add" class="btn btn-success">
      <i class="bi bi-plus-lg"></i> Nieuwe bestelling
    </a>
  </div>
</div>

<!-- Status filters -->
<div class="btn-group mb-3" role="group" aria-label="Status filter">
  <?php
    $filters = [
      'all'                 => 'Alle',
      'concept'             => 'Concept',
      'awaiting_activation' => 'Wachten op activatie',
      'completed'           => 'Voltooid',
      'cancelled'           => 'Geannuleerd',
    ];
    foreach ($filters as $key => $label):
      $active = ($status === $key) ? ' active' : '';
      $url = orders_list_url_keep(['status'=>$key, 'page'=>1]);
  ?>
    <a class="btn btn-outline-primary<?= $active ?>" href="<?= e($url) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body">
    <?php if ($total === 0): ?>
      <div class="text-muted">Geen bestellingen gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>Klant</th>
              <th>SIM</th>
              <th>Abonnement</th>
              <th>Status</th>
              <th>Aangemaakt</th>
              <th class="text-end" style="width:160px;">Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= e($r['customer_name'] ?: ('#'.(int)$r['customer_id'])) ?></td>
                <td><?= e($r['sim_iccid'] ?: ('#'.(int)$r['sim_id'])) ?></td>
                <td><?= e($r['plan_name'] ?: ('#'.(int)$r['plan_id'])) ?></td>
                <td>
                  <?php
                    $s = (string)($r['status'] ?? '');
                    $badge = match ($s) {
                      'concept'             => 'bg-secondary',
                      'awaiting_activation' => 'bg-warning text-dark',
                      'completed'           => 'bg-success',
                      'cancelled'           => 'bg-danger',
                      default               => 'bg-light text-dark'
                    };
                  ?>
                  <span class="badge <?= $badge ?>"><?= e(status_label($s)) ?></span>
                </td>
                <td><?= e($r['created_at'] ?? '') ?></td>
                <td class="text-end">
                  <!-- Bekijken -->
                  <a class="btn btn-sm btn-outline-primary" title="Bekijken"
                     href="index.php?route=order_edit&id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-eye"></i>
                  </a>

                  <!-- Annuleren -->
                  <?php if (($r['status'] ?? '') !== 'cancelled' && ($r['status'] ?? '') !== 'completed'): ?>
                    <form method="post" action="index.php?route=order_cancel" class="d-inline"
                          onsubmit="return confirm('Deze bestelling annuleren?');">
                      <?php if (function_exists('csrf_field')) csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary" title="Annuleren">
                        <i class="bi bi-slash-circle"></i>
                      </button>
                    </form>
                  <?php endif; ?>

                  <!-- Verwijderen (alleen Super-admin) -->
                  <?php if ($isSuper): ?>
                    <form method="post" action="index.php?route=order_delete" class="d-inline"
                          onsubmit="return confirm('Bestelling VERWIJDEREN? Dit is niet omkeerbaar.');">
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
              $baseQs['route'] = 'orders_list';
              $baseQs['per_page'] = $perPage;
              $baseQs['status'] = $status;
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
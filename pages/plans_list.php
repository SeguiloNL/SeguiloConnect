<?php
// pages/plans_list.php — Overzicht abonnementen (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role    = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);

if (!$isSuper) {
  echo '<div class="alert alert-danger">Alleen Super-admin mag deze pagina gebruiken.</div>';
  return;
}

// DB
try { $pdo = db(); }
catch (Throwable $e) {
  echo '<div class="alert alert-danger">DB niet beschikbaar: ' . e($e->getMessage()) . '</div>';
  return;
}

// Paginering
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25,50,100], true)) $perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Tellen
try {
  $total = (int)$pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: ' . e($e->getMessage()) . '</div>';
  return;
}
$totalPages = max(1, (int)ceil($total / $perPage));

// Ophalen
try {
  $sql = "SELECT
            id,
            name,
            buy_price_monthly_ex_vat,
            sell_price_monthly_ex_vat,
            buy_price_overage_per_mb_ex_vat,
            sell_price_overage_per_mb_ex_vat,
            setup_fee_ex_vat,
            bundle_gb,
            network_operator,
            is_active
          FROM plans
          ORDER BY name ASC, id ASC
          LIMIT $perPage OFFSET $offset";
  $st = $pdo->prepare($sql);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: ' . e($e->getMessage()) . '</div>';
  return;
}

// Helpers
function money_or_dash($v): string {
  if ($v === null || $v === '' ) return '—';
  $num = (float)$v;
  return '€ ' . number_format($num, 2, ',', '.');
}
function num_or_dash($v): string {
  if ($v === null || $v === '' ) return '—';
  if (is_numeric($v)) {
    // toon zonder trailing .00 als integer
    if ((float)$v == (int)$v) return (string)(int)$v;
    return str_replace('.', ',', (string)$v);
  }
  return e((string)$v);
}
function plans_list_url_keep(array $extra): string {
  $base = 'index.php?route=plans_list';
  $qs = array_merge($_GET, $extra);
  return $base . '&' . http_build_query($qs);
}

echo function_exists('flash_output') ? flash_output() : '';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4>Abonnementen</h4>
  <div class="d-flex align-items-center gap-2">
    <!-- Per pagina -->
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="plans_list">
      <label class="form-label m-0">Per pagina</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ([25,50,100] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <!-- Nieuw Abonnement -->
    <a href="index.php?route=plan_add" class="btn btn-success">
      <i class="bi bi-plus-lg"></i> Nieuw Abonnement
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if ($total === 0): ?>
      <div class="text-muted">Geen abonnementen gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th>Naam</th>
              <th>Inkoopprijs (ex/maand)</th>
              <th>Verkoopprijs (ex/maand)</th>
              <th>Inkoop buiten bundel /MB (ex)</th>
              <th>Advies buiten bundel /MB (ex)</th>
              <th>Setup (ex)</th>
              <th>Bundel (GB)</th>
              <th>Netwerk operator</th>
              <th>Status</th>
              <th class="text-end" style="width:140px;">Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= e($r['name'] ?? '—') ?></td>
                <td><?= money_or_dash($r['buy_price_monthly_ex_vat']) ?></td>
                <td><?= money_or_dash($r['sell_price_monthly_ex_vat']) ?></td>
                <td><?= money_or_dash($r['buy_price_overage_per_mb_ex_vat']) ?></td>
                <td><?= money_or_dash($r['sell_price_overage_per_mb_ex_vat']) ?></td>
                <td><?= money_or_dash($r['setup_fee_ex_vat']) ?></td>
                <td><?= num_or_dash($r['bundle_gb']) ?></td>
                <td><?= e($r['network_operator'] ?? '—') ?></td>
                <td>
                  <?php if ((int)($r['is_active'] ?? 0) === 1): ?>
                    <span class="badge bg-success">Actief</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inactief</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <!-- Bewerken -->
                  <a class="btn btn-sm btn-outline-primary" title="Bewerken"
                     href="index.php?route=plan_edit&id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <!-- Dupliceren -->
                  <a class="btn btn-sm btn-outline-secondary" title="Dupliceren"
                     href="index.php?route=plan_duplicate&id=<?= (int)$r['id'] ?>">
                    <i class="bi bi-files"></i>
                  </a>
                  <!-- Verwijderen -->
                  <form method="post" action="index.php?route=plan_delete&id=<?= (int)$r['id'] ?>" class="d-inline"
                        onsubmit="return confirm('Abonnement verwijderen? Deze actie kan niet ongedaan worden gemaakt.');">
                    <?php if (function_exists('csrf_field')) csrf_field(); ?>
                    <button class="btn btn-sm btn-outline-danger" title="Verwijderen">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
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
              $baseQs['route'] = 'plans_list';
              $baseQs['per_page'] = $perPage;
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
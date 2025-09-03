<?php
// pages/sims_list.php — Overzicht SIM-kaarten (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role    = (string)($me['role'] ?? '');
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);

// Alleen Super-admin
if (!$isSuper) {
  echo '<div class="alert alert-danger">Alleen Super-admin mag deze pagina gebruiken.</div>';
  return;
}

// DB connectie
try { $pdo = db(); }
catch (Throwable $e) {
  echo '<div class="alert alert-danger">DB niet beschikbaar: ' . e($e->getMessage()) . '</div>';
  return;
}

// Paginering
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25,50,100], true)) $perPage = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Totaal aantal
try {
  $total = (int)$pdo->query("SELECT COUNT(*) FROM sims")->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: ' . e($e->getMessage()) . '</div>';
  return;
}
$totalPages = max(1, (int)ceil($total / $perPage));

// Ophalen
try {
  // Let op: LIMIT/OFFSET als integers in SQL string (veilig door validatie hierboven)
  $sql = "SELECT id, iccid, imsi, pin, puk, status
          FROM sims
          ORDER BY id DESC
          LIMIT $perPage OFFSET $offset";
  $st = $pdo->prepare($sql);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: ' . e($e->getMessage()) . '</div>';
  return;
}

// Helpers UI
function sims_url_keep(array $extra): string {
  $base = 'index.php?route=sims_list';
  $qs = array_merge($_GET, $extra);
  return $base . '&' . http_build_query($qs);
}
function status_badge(?string $s): string {
  $s = (string)$s;
  $label = $s === '' ? 'onbekend' : $s;
  $class = match ($s) {
    'active', 'assigned' => 'bg-success',
    'inactive'           => 'bg-secondary',
    'awaiting'           => 'bg-warning text-dark',
    'retired'            => 'bg-dark',
    default              => 'bg-light text-dark'
  };
  return '<span class="badge '.$class.'">'.e(ucfirst($label)).'</span>';
}

echo function_exists('flash_output') ? flash_output() : '';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Simkaarten</h4>
  <div class="d-flex align-items-center gap-2">
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="sims_list">
      <label class="form-label m-0">Per pagina</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ([25,50,100] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="page" value="1">
    </form>

    <a href="index.php?route=sim_add" class="btn btn-primary">
      <i class="bi bi-plus-lg"></i> Simkaart(en) toevoegen
    </a>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if ($total === 0): ?>
      <div class="text-muted">Geen simkaarten gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th>ICCID</th>
              <th>IMSI</th>
              <th>PIN</th>
              <th>PUK</th>
              <th>Status</th>
              <th class="text-end" style="width:180px;">Acties</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td><?= e($r['iccid'] ?? '—') ?></td>
              <td><?= e($r['imsi']  ?? '—') ?></td>

              <!-- PIN met oogje -->
              <td>
                <?php
                  $pin = (string)($r['pin'] ?? '');
                  $pinMasked = $pin !== '' ? '••••' : '—';
                ?>
                <span class="secret" data-value="<?= e($pin) ?>"><?= e($pinMasked) ?></span>
                <?php if ($pin !== ''): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary ms-2 toggle-secret" title="Toon/verberg PIN">
                    <i class="bi bi-eye"></i>
                  </button>
                <?php endif; ?>
              </td>

              <!-- PUK met oogje -->
              <td>
                <?php
                  $puk = (string)($r['puk'] ?? '');
                  $pukMasked = $puk !== '' ? '••••' : '—';
                ?>
                <span class="secret" data-value="<?= e($puk) ?>"><?= e($pukMasked) ?></span>
                <?php if ($puk !== ''): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary ms-2 toggle-secret" title="Toon/verberg PUK">
                    <i class="bi bi-eye"></i>
                  </button>
                <?php endif; ?>
              </td>

              <td><?= status_badge($r['status'] ?? null) ?></td>

              <td class="text-end">
                <!-- Bewerken -->
                <a class="btn btn-sm btn-outline-primary" title="Bewerken"
                   href="index.php?route=sim_edit&id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-pencil"></i>
                </a>

                <!-- Toewijzen -->
                <a class="btn btn-sm btn-outline-secondary" title="Toewijzen"
                   href="index.php?route=sim_assign&sim_id=<?= (int)$r['id'] ?>">
                  <i class="bi bi-arrow-right"></i>
                </a>

                <!-- Verwijderen (alleen Super-admin; pagina is al super-only) -->
               <form method="post" action="index.php?route=sim_delete"
                  onsubmit="return confirm('Simkaart verwijderen? Dit kan niet ongedaan worden gemaakt.');">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Verwijderen">
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
              $baseQs = $_GET; $baseQs['route'] = 'sims_list'; $baseQs['per_page'] = $perPage;
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

<!-- Geheimen (PIN/PUK) togglen -->
<script>
document.addEventListener('click', function(e) {
  if (e.target.closest('.toggle-secret')) {
    const btn = e.target.closest('.toggle-secret');
    const cell = btn.previousElementSibling; // span.secret
    if (!cell || !cell.classList.contains('secret')) return;
    const shown = cell.dataset.shown === '1';
    if (shown) {
      cell.textContent = '••••';
      cell.dataset.shown = '0';
      btn.innerHTML = '<i class="bi bi-eye"></i>';
    } else {
      cell.textContent = cell.dataset.value || '';
      cell.dataset.shown = '1';
      btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
    }
  }
});
</script>
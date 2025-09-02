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

// --- DB connectie ---
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.$e->getMessage().'</div>'; return; }

// Helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->quote($col);
    $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE $q");
    return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->quote($table);
    return (bool)$pdo->query("SHOW TABLES LIKE $q")->fetchColumn();
}

// Query sims met gekoppelde gebruiker
$sql = "SELECT s.*, u.id AS assigned_to_user_id, u.name AS assigned_name
        FROM sims s
        LEFT JOIN users u ON u.id = s.assigned_to_user_id
        ORDER BY s.id DESC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Simkaarten</h4>
  <?php if ($isSuper): ?>
    <a class="btn btn-primary" href="index.php?route=sim_add">
      Nieuwe simkaart toevoegen
    </a>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="alert alert-info">Geen simkaarten gevonden.</div>
<?php else: ?>
  <div class="table-responsive">
    <form method="post" action="index.php?route=sim_bulk_action" id="bulkForm">
      <?php if (function_exists('csrf_field')) csrf_field(); ?>
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th><input type="checkbox" id="selectAll"></th>
            <th>ID</th>
            <th>ICCID</th>
            <th>IMSI</th>
            <th>PIN</th>
            <th>PUK</th>
            <th>Status</th>
            <th>Toegewezen aan</th>
            <th style="width:220px;">Acties</th>
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
                      echo e("#$uid — " . ($uname !== '' ? $uname : '(naam onbekend)'));
                  } else {
                      echo '<span class="text-muted">—</span>';
                  }
                ?>
              </td>
              <td>
                <div class="btn-group btn-group-sm" role="group">
                  <?php if ($isSuper): ?>
                    <a class="btn btn-outline-secondary" href="index.php?route=sim_edit&id=<?= (int)$r['id'] ?>">Bewerken</a>
                    <form method="post" action="index.php?route=sim_delete" onsubmit="return confirm('Weet je zeker dat je deze simkaart wil verwijderen?');">
                      <?php if (function_exists('csrf_field')) csrf_field(); ?>
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-outline-danger">Verwijderen</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($isRes || $isSubRes || $isSuper): ?>
                    <a class="btn btn-outline-primary" href="index.php?route=sim_assign&sim_id=<?= (int)$r['id'] ?>">Toewijzen</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="mt-3 d-flex gap-2">
        <?php if ($isSuper): ?>
          <button type="submit" name="action" value="delete" class="btn btn-danger"
            onclick="return confirm('Weet je zeker dat je de geselecteerde simkaarten wil verwijderen?');">
            Verwijder geselecteerde
          </button>
        <?php endif; ?>
        <?php if ($isRes || $isSubRes || $isSuper): ?>
          <button type="submit" name="action" value="assign" class="btn btn-primary">
            Bulk toewijzen
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
<?php endif; ?>

<script>
document.getElementById('selectAll')?.addEventListener('change', function(e) {
  const checkboxes = document.querySelectorAll('input[name="ids[]"]');
  checkboxes.forEach(cb => cb.checked = e.target.checked);
});
</script>
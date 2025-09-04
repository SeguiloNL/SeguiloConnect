<?php
// pages/sim_assign.php — Simkaart toewijzen aan (sub)reseller of eindklant
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$myId = (int)($me['id'] ?? 0);
$role = (string)($me['role'] ?? '');
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

if (!$isSuper && !$isRes && !$isSubRes) {
  flash_set('danger','Je hebt geen rechten om simkaarten toe te wijzen.');
  redirect('index.php?route=sims_list'); exit;
}

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ---- helpers ----
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
/** bouw ids van alle onderliggende users (incl. root) via parent_user_id */
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId];
  $seen = [$rootId => true];
  $q = [$rootId];
  while ($q) {
    $chunk = array_splice($q, 0, 200);
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
    $st->execute($chunk);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
      $cid = (int)$cid;
      if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $q[]=$cid; }
    }
  }
  return $ids;
}
function user_in_scope(PDO $pdo, int $viewerId, int $targetUserId): bool {
  $tree = build_tree_ids($pdo, $viewerId);
  return in_array($targetUserId, $tree, true) || $viewerId === $targetUserId;
}

// ---- POST (bovenaan, zonder output!) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) {
    flash_set('danger','Sessie verlopen. Probeer opnieuw.');
    redirect('index.php?route=sims_list'); exit;
  }

  $simId   = (int)($_POST['sim_id'] ?? 0);
  $targetId= (int)($_POST['assigned_to_user_id'] ?? 0);

  if ($simId <= 0 || $targetId <= 0) {
    flash_set('danger','Ongeldige invoer.');
    redirect('index.php?route=sims_list'); exit;
  }

  try {
    // Sim ophalen
    $st = $pdo->prepare("SELECT id, iccid, assigned_to_user_id FROM sims WHERE id=? LIMIT 1");
    $st->execute([$simId]);
    $sim = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sim) {
      flash_set('danger','Simkaart niet gevonden.');
      redirect('index.php?route=sims_list'); exit;
    }

    // Target user ophalen
    $st = $pdo->prepare("SELECT id, role, name FROM users WHERE id=? LIMIT 1");
    $st->execute([$targetId]);
    $target = $st->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
      flash_set('danger','Doelgebruiker niet gevonden.');
      redirect('index.php?route=sims_list'); exit;
    }

    // Scope: Super mag alles; reseller/sub alleen binnen eigen boom
    if (!$isSuper && !user_in_scope($pdo, $myId, (int)$target['id'])) {
      flash_set('danger','Je kunt niet toewijzen buiten je eigen structuur.');
      redirect('index.php?route=sims_list'); exit;
    }

    // Update: toewijzen + status = assigned (als kolom bestaat)
    $set = "assigned_to_user_id = ?";
    if (column_exists($pdo,'sims','status')) $set .= ", status='assigned'";
    if (column_exists($pdo,'sims','updated_at')) $set .= ", updated_at=NOW()";

    $upd = $pdo->prepare("UPDATE sims SET $set WHERE id=? LIMIT 1");
    $upd->execute([$targetId, $simId]);

    flash_set('success','Simkaart toegewezen aan: '.e($target['name'] ?? ('#'.$targetId)));
    redirect('index.php?route=sims_list'); exit;
  } catch (Throwable $e) {
    flash_set('danger','Toewijzen mislukt: '.$e->getMessage());
    redirect('index.php?route=sims_list'); exit;
  }
}

// ---- GET (vanaf hier mag er output zijn) ----
echo function_exists('flash_output') ? flash_output() : '';

$simId = (int)($_GET['sim_id'] ?? 0);
if ($simId <= 0) {
  echo '<div class="alert alert-warning">Geen simkaart opgegeven.</div>';
  echo '<a class="btn btn-secondary" href="index.php?route=sims_list">Terug</a>';
  return;
}

// Sim ophalen
try {
  $st = $pdo->prepare("SELECT id, iccid, imsi, pin, puk, status, assigned_to_user_id FROM sims WHERE id=? LIMIT 1");
  $st->execute([$simId]);
  $sim = $st->fetch(PDO::FETCH_ASSOC);
  if (!$sim) {
    echo '<div class="alert alert-danger">Simkaart niet gevonden.</div>';
    echo '<a class="btn btn-secondary" href="index.php?route=sims_list">Terug</a>';
    return;
  }
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
  return;
}

// Doelgebruikers lijst opbouwen
try {
  if ($isSuper) {
    // Super: alle gebruikers (excl. super_admin als je dat niet wil)
    $st = $pdo->query("SELECT id, name, role FROM users ORDER BY role, name");
    $targets = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $ids = build_tree_ids($pdo, $myId); // eigen boom
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, role FROM users WHERE id IN ($ph) ORDER BY role, name");
    $st->execute($ids);
    $targets = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Doelgebruikers laden mislukt: '.e($e->getMessage()).'</div>';
  return;
}

function role_nl(string $r): string {
  return match ($r) {
    'super_admin'   => 'Super-admin',
    'reseller'      => 'Reseller',
    'sub_reseller'  => 'Sub-reseller',
    'customer'      => 'Eindklant',
    default         => ucfirst($r),
  };
}
?>

<h4>Simkaart toewijzen</h4>

<div class="card mb-3">
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">SIM ID</dt>
      <dd class="col-sm-9">#<?= (int)$sim['id'] ?></dd>

      <dt class="col-sm-3">ICCID</dt>
      <dd class="col-sm-9"><?= e($sim['iccid'] ?? '—') ?></dd>

      <dt class="col-sm-3">Huidig toegewezen aan</dt>
      <dd class="col-sm-9">
        <?php
          if (!empty($sim['assigned_to_user_id'])) {
            $uid = (int)$sim['assigned_to_user_id'];
            try {
              $st = $pdo->prepare("SELECT name, role FROM users WHERE id=?");
              $st->execute([$uid]);
              if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
                echo '#'.(int)$uid.' — '.e($u['name']).' ('.e(role_nl($u['role'] ?? '')).')';
              } else {
                echo '#'.(int)$uid;
              }
            } catch (Throwable $e) {
              echo '#'.(int)$uid;
            }
          } else {
            echo '<span class="text-muted">Niet toegewezen</span>';
          }
        ?>
      </dd>
    </dl>
  </div>
</div>

<form method="post" class="row gy-3">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>
  <input type="hidden" name="sim_id" value="<?= (int)$sim['id'] ?>">

  <div class="col-12 col-md-8">
    <label class="form-label">Toewijzen aan</label>
    <select name="assigned_to_user_id" class="form-select" required>
      <option value="">— Kies gebruiker —</option>
      <?php
        // groepeer op rol voor overzicht
        $byRole = [];
        foreach ($targets as $t) { $byRole[$t['role']][] = $t; }
        foreach (['reseller','sub_reseller','customer','super_admin'] as $groupRole):
          if (empty($byRole[$groupRole])) continue;
      ?>
        <optgroup label="<?= e(role_nl($groupRole)) ?>">
          <?php foreach ($byRole[$groupRole] as $t): ?>
            <option value="<?= (int)$t['id'] ?>">
              #<?= (int)$t['id'] ?> — <?= e($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Kies de (sub)reseller of eindklant aan wie je de simkaart toewijst.</div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-arrow-right"></i> Toewijzen</button>
    <a href="index.php?route=sims_list" class="btn btn-outline-secondary">Annuleren</a>
  </div>
</form>
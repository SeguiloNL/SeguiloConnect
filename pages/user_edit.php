<?php
// pages/user_edit.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role      = $me['role'] ?? '';
$isSuper   = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes     = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes  = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>'; return; }

function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function in_tree(PDO $pdo, int $rootId, int $candidateId): bool {
  if ($rootId === $candidateId) return true;
  if (!column_exists($pdo, 'users', 'parent_user_id')) return false;
  $queue = [$rootId]; $seen = [$rootId=>true];
  while ($queue) {
    $chunk = array_splice($queue, 0, 100);
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
    $st->execute($chunk);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
      $cid = (int)$cid;
      if ($cid === $candidateId) return true;
      if (!isset($seen[$cid])) { $seen[$cid]=true; $queue[]=$cid; }
    }
  }
  return false;
}

// ---- Target ----
$targetId = (int)($_GET['id'] ?? 0);
if ($targetId <= 0) { flash_set('danger','Geen geldige gebruiker.'); redirect('index.php?route=users_list'); }

$st = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$st->execute([$targetId]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) { flash_set('danger','Gebruiker niet gevonden.'); redirect('index.php?route=users_list'); }

// ---- Rechten ----
$allowed = false;
if ($isSuper) {
  $allowed = true;
} elseif ($isRes || $isSubRes) {
  $allowed = in_tree($pdo, (int)$me['id'], (int)$user['id']) || ((int)$me['id'] === (int)$user['id']);
} else {
  $allowed = ((int)$me['id'] === (int)$user['id']);
}
if (!$allowed) { flash_set('danger','Geen toegang.'); redirect('index.php?route=users_list'); }

// ---- Kolommen (toon als kolom bestaat OF de key in $user staat) ----
$hasIsActive = column_exists($pdo,'users','is_active') || array_key_exists('is_active',$user);
$hasRole     = column_exists($pdo,'users','role')      || array_key_exists('role',$user);

// Administratief adres
$hasAdminContact  = column_exists($pdo,'users','admin_contact')  || array_key_exists('admin_contact',$user);
$hasAdminAddress  = column_exists($pdo,'users','admin_address')  || array_key_exists('admin_address',$user);
$hasAdminPostcode = column_exists($pdo,'users','admin_postcode') || array_key_exists('admin_postcode',$user);
$hasAdminCity     = column_exists($pdo,'users','admin_city')     || array_key_exists('admin_city',$user);

// Aansluitadres (belangrijk deel van jouw issue)
$hasConnContact   = column_exists($pdo,'users','connect_contact')  || array_key_exists('connect_contact',$user);
$hasConnAddress   = column_exists($pdo,'users','connect_address')  || array_key_exists('connect_address',$user);
$hasConnPostcode  = column_exists($pdo,'users','connect_postcode') || array_key_exists('connect_postcode',$user);
$hasConnCity      = column_exists($pdo,'users','connect_city')     || array_key_exists('connect_city',$user);

// ---- POST: opslaan ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch(Throwable $e){ flash_set('danger','Sessie verlopen. Probeer opnieuw.'); redirect('index.php?route=user_edit&id='.$targetId); }

  $name  = trim((string)($_POST['name']  ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  if ($name === '' || $email === '') {
    flash_set('danger','Naam en e-mail zijn verplicht.');
    redirect('index.php?route=user_edit&id='.$targetId);
  }

  $newRole = $user['role'] ?? 'customer';
  if ($hasRole) {
    if ($isSuper) {
      $cand = (string)($_POST['role'] ?? $newRole);
      if (in_array($cand, ['super_admin','reseller','sub_reseller','customer'], true)) $newRole = $cand;
    } elseif ($isRes) {
      $cand = (string)($_POST['role'] ?? $newRole);
      if (in_array($cand, ['sub_reseller','customer'], true)) $newRole = $cand;
    } elseif ($isSubRes) {
      $newRole = 'customer';
    }
  }
  $isActive = $hasIsActive ? (int)($_POST['is_active'] ?? ($user['is_active'] ?? 1)) : null;

  $fields = [
    'name'  => $name,
    'email' => $email,
  ];
  if ($hasRole)     $fields['role']      = $newRole;
  if ($hasIsActive) $fields['is_active'] = $isActive;

  // Administratief adres
  if ($hasAdminContact)  $fields['admin_contact']  = (string)($_POST['admin_contact']  ?? '');
  if ($hasAdminAddress)  $fields['admin_address']  = (string)($_POST['admin_address']  ?? '');
  if ($hasAdminPostcode) $fields['admin_postcode'] = (string)($_POST['admin_postcode'] ?? '');
  if ($hasAdminCity)     $fields['admin_city']     = (string)($_POST['admin_city']     ?? '');

  // Aansluitadres (altijd meenemen als kolommen bestaan of keys aanwezig waren)
  if ($hasConnContact)   $fields['connect_contact']  = (string)($_POST['connect_contact']  ?? '');
  if ($hasConnAddress)   $fields['connect_address']  = (string)($_POST['connect_address']  ?? '');
  if ($hasConnPostcode)  $fields['connect_postcode'] = (string)($_POST['connect_postcode'] ?? '');
  if ($hasConnCity)      $fields['connect_city']     = (string)($_POST['connect_city']     ?? '');

  // Dynamische UPDATE
  $sets=[]; $vals=[];
  foreach ($fields as $k=>$v) { $sets[]="`$k`=?"; $vals[]=$v; }
  $vals[]=$targetId;

  try {
    $sql="UPDATE users SET ".implode(', ',$sets)." WHERE id = ?";
    $st=$pdo->prepare($sql);
    $st->execute($vals);
    flash_set('success','Gebruiker opgeslagen.');
    redirect('index.php?route=users_list');
  } catch (Throwable $e) {
    flash_set('danger','Opslaan mislukt: '.$e->getMessage());
    redirect('index.php?route=user_edit&id='.$targetId);
  }
}

// ---- Rol-opties voor formulier ----
$roleOptions = [];
if ($hasRole) {
  if ($isSuper) {
    $roleOptions = [
      'super_admin'  => 'Super-admin',
      'reseller'     => 'Reseller',
      'sub_reseller' => 'Sub-reseller',
      'customer'     => 'Eindklant',
    ];
  } elseif ($isRes) {
    $roleOptions = [
      $user['role'] => role_label($user['role']),
      'sub_reseller' => 'Sub-reseller',
      'customer'     => 'Eindklant',
    ];
  } elseif ($isSubRes) {
    $roleOptions = [
      $user['role'] => role_label($user['role']),
      'customer'    => 'Eindklant',
    ];
  }
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Gebruiker bewerken</h4>
  <a class="btn btn-secondary" href="index.php?route=users_list">Terug</a>
</div>

<form method="post" action="index.php?route=user_edit&id=<?= (int)$targetId ?>">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Naam</label>
      <input type="text" class="form-control" name="name" value="<?= e($user['name'] ?? '') ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">E-mail</label>
      <input type="email" class="form-control" name="email" value="<?= e($user['email'] ?? '') ?>" required>
    </div>

    <?php if ($hasRole && $roleOptions): ?>
      <div class="col-md-4">
        <label class="form-label">Rol</label>
        <select class="form-select" name="role">
          <?php $curRole = $user['role'] ?? 'customer';
          foreach ($roleOptions as $val=>$label): ?>
            <option value="<?= e($val) ?>" <?= ($val===$curRole?'selected':'') ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($hasIsActive): ?>
      <div class="col-md-4">
        <label class="form-label">Status</label>
        <select class="form-select" name="is_active">
          <?php $ia = (int)($user['is_active'] ?? 1); ?>
          <option value="1" <?= $ia===1?'selected':'' ?>>Actief</option>
          <option value="0" <?= $ia===0?'selected':'' ?>>Inactief</option>
        </select>
      </div>
    <?php endif; ?>
  </div>

  <hr class="my-4">

  <h5>Administratief adres</h5>
  <div class="row g-3">
    <?php if ($hasAdminContact): ?>
      <div class="col-md-6">
        <label class="form-label">Contactpersoon</label>
        <input type="text" class="form-control" name="admin_contact" value="<?= e($user['admin_contact'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminAddress): ?>
      <div class="col-md-6">
        <label class="form-label">Adres</label>
        <input type="text" class="form-control" name="admin_address" value="<?= e($user['admin_address'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminPostcode): ?>
      <div class="col-md-3">
        <label class="form-label">Postcode</label>
        <input type="text" class="form-control" name="admin_postcode" value="<?= e($user['admin_postcode'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminCity): ?>
      <div class="col-md-5">
        <label class="form-label">Woonplaats</label>
        <input type="text" class="form-control" name="admin_city" value="<?= e($user['admin_city'] ?? '') ?>">
      </div>
    <?php endif; ?>
  </div>

  <hr class="my-4">

  <h5>Aansluitadres</h5>
  <div class="row g-3">
    <?php if ($hasConnContact): ?>
      <div class="col-md-6">
        <label class="form-label">Contactpersoon</label>
        <input type="text" class="form-control" name="connect_contact" value="<?= e($user['connect_contact'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasConnAddress): ?>
      <div class="col-md-6">
        <label class="form-label">Adres</label>
        <input type="text" class="form-control" name="connect_address" value="<?= e($user['connect_address'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasConnPostcode): ?>
      <div class="col-md-3">
        <label class="form-label">Postcode</label>
        <input type="text" class="form-control" name="connect_postcode" value="<?= e($user['connect_postcode'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($hasConnCity): ?>
      <div class="col-md-5">
        <label class="form-label">Woonplaats</label>
        <input type="text" class="form-control" name="connect_city" value="<?= e($user['connect_city'] ?? '') ?>">
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary" type="submit">Opslaan</button>
    <a class="btn btn-outline-secondary" href="index.php?route=users_list">Annuleren</a>
  </div>
</form>
<?php
// pages/user_add.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

if (!($isSuper || $isRes || $isSubRes)) {
  flash_set('danger','Je hebt geen rechten om gebruikers aan te maken.');
  redirect('index.php?route=users_list');
}

try { $pdo = db(); }
catch (Throwable $e) {
  echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>';
  return;
}

// Helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

// Kolommen detecteren
$hasParent     = column_exists($pdo,'users','parent_user_id');
$hasIsActive   = column_exists($pdo,'users','is_active');
$hasRole       = column_exists($pdo,'users','role');

// Admin adres
$hasAdminContact  = column_exists($pdo,'users','admin_contact');
$hasAdminAddress  = column_exists($pdo,'users','admin_address');
$hasAdminPostcode = column_exists($pdo,'users','admin_postcode');
$hasAdminCity     = column_exists($pdo,'users','admin_city');

// Aansluitadres
$hasConnContact   = column_exists($pdo,'users','connect_contact');
$hasConnAddress   = column_exists($pdo,'users','connect_address');
$hasConnPostcode  = column_exists($pdo,'users','connect_postcode');
$hasConnCity      = column_exists($pdo,'users','connect_city');

// Parent-keuze (alleen super-admin krijgt een selector)
$parentChoices = [];
if ($isSuper && $hasParent) {
  // eenvoudige lijst van alle gebruikers (je kunt dit later filteren of zoeken)
  $parentChoices = $pdo->query("SELECT id,name,role FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// POST: opslaan
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch(Throwable $e){ flash_set('danger','Sessie verlopen. Probeer opnieuw.'); redirect('index.php?route=user_add'); }

  $name  = trim((string)($_POST['name']  ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  if ($name === '' || $email === '') {
    flash_set('danger','Naam en e-mail zijn verplicht.');
    redirect('index.php?route=user_add');
  }

  // Rol bepalen binnen rechten
  $newRole = 'customer';
  if ($hasRole) {
    $requested = (string)($_POST['role'] ?? 'customer');
    if ($isSuper) {
      $newRole = in_array($requested, ['super_admin','reseller','sub_reseller','customer'], true) ? $requested : 'customer';
    } elseif ($isRes) {
      $newRole = in_array($requested, ['sub_reseller','customer'], true) ? $requested : 'customer';
    } elseif ($isSubRes) {
      $newRole = 'customer';
    }
  }

  // parent_user_id bepalen
  $parentId = null;
  if ($hasParent) {
    if ($isSuper) {
      $p = (int)($_POST['parent_user_id'] ?? 0);
      $parentId = $p > 0 ? $p : null;
    } elseif ($isRes || $isSubRes) {
      $parentId = (int)$me['id']; // valt onder de aanmaker
    }
  }

  // Optioneel: status
  $isActive = $hasIsActive ? (int)($_POST['is_active'] ?? 1) : null;

  // Dynamische INSERT
  $fields = [
    'name'  => $name,
    'email' => $email,
  ];
  if ($hasRole)     $fields['role']      = $newRole;
  if ($hasIsActive) $fields['is_active'] = $isActive;
  if ($hasParent && $parentId !== null) $fields['parent_user_id'] = $parentId;

  // Admin adres
  if ($hasAdminContact)  $fields['admin_contact']  = (string)($_POST['admin_contact']  ?? '');
  if ($hasAdminAddress)  $fields['admin_address']  = (string)($_POST['admin_address']  ?? '');
  if ($hasAdminPostcode) $fields['admin_postcode'] = (string)($_POST['admin_postcode'] ?? '');
  if ($hasAdminCity)     $fields['admin_city']     = (string)($_POST['admin_city']     ?? '');

  // Aansluitadres
  if ($hasConnContact)   $fields['connect_contact']  = (string)($_POST['connect_contact']  ?? '');
  if ($hasConnAddress)   $fields['connect_address']  = (string)($_POST['connect_address']  ?? '');
  if ($hasConnPostcode)  $fields['connect_postcode'] = (string)($_POST['connect_postcode'] ?? '');
  if ($hasConnCity)      $fields['connect_city']     = (string)($_POST['connect_city']     ?? '');

  // Als password kolommen bestaan, kun je hier desgewenst initiële wachtwoorden zetten.
  $hasPassHash = column_exists($pdo,'users','password_hash');
  if ($hasPassHash && !empty($_POST['password'] ?? '')) {
    $fields['password_hash'] = password_hash((string)$_POST['password'], PASSWORD_DEFAULT);
  }

  // Bouw SQL
  $cols = array_keys($fields);
  $placeholders = array_fill(0, count($cols), '?');
  $values = array_values($fields);

  try {
    $sql = "INSERT INTO users (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $st  = $pdo->prepare($sql);
    $st->execute($values);
    flash_set('success','Gebruiker aangemaakt.');
    redirect('index.php?route=users_list');
  } catch (Throwable $e) {
    flash_set('danger','Opslaan mislukt: ' . $e->getMessage());
    redirect('index.php?route=user_add');
  }
}

// Form tonen
// Rol-opties per aanmaker
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
      'sub_reseller' => 'Sub-reseller',
      'customer'     => 'Eindklant',
    ];
  } elseif ($isSubRes) {
    $roleOptions = [
      'customer'     => 'Eindklant',
    ];
  }
}
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Nieuwe gebruiker</h4>
  <a class="btn btn-secondary" href="index.php?route=users_list">Terug</a>
</div>

<form method="post" action="index.php?route=user_add">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Naam</label>
      <input type="text" class="form-control" name="name" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">E-mail</label>
      <input type="email" class="form-control" name="email" required>
    </div>

    <?php if ($hasRole && $roleOptions): ?>
      <div class="col-md-6">
        <label class="form-label">Rol</label>
        <select class="form-select" name="role" required>
          <?php foreach ($roleOptions as $val => $label): ?>
            <option value="<?= e($val) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($hasIsActive): ?>
      <div class="col-md-6">
        <label class="form-label">Status</label>
        <select class="form-select" name="is_active">
          <option value="1" selected>Actief</option>
          <option value="0">Inactief</option>
        </select>
      </div>
    <?php endif; ?>

    <?php if ($isSuper && $hasParent): ?>
      <div class="col-md-6">
        <label class="form-label">Parent (optioneel)</label>
        <select class="form-select" name="parent_user_id">
          <option value="">— geen —</option>
          <?php foreach ($parentChoices as $p): ?>
            <option value="<?= (int)$p['id'] ?>">
              #<?= (int)$p['id'] ?> — <?= e($p['name']) ?> (<?= e(role_label($p['role'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>

    <?php if (column_exists($pdo,'users','password_hash')): ?>
      <div class="col-md-6">
        <label class="form-label">Wachtwoord (optioneel)</label>
        <input type="password" class="form-control" name="password" autocomplete="new-password">
      </div>
    <?php endif; ?>
  </div>

  <hr class="my-4">
  <h5>Administratief adres</h5>
  <div class="row g-3">
    <?php if ($hasAdminContact): ?>
      <div class="col-md-6">
        <label class="form-label">Contactpersoon</label>
        <input type="text" class="form-control" name="admin_contact">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminAddress): ?>
      <div class="col-md-6">
        <label class="form-label">Adres</label>
        <input type="text" class="form-control" name="admin_address">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminPostcode): ?>
      <div class="col-md-3">
        <label class="form-label">Postcode</label>
        <input type="text" class="form-control" name="admin_postcode">
      </div>
    <?php endif; ?>
    <?php if ($hasAdminCity): ?>
      <div class="col-md-5">
        <label class="form-label">Woonplaats</label>
        <input type="text" class="form-control" name="admin_city">
      </div>
    <?php endif; ?>
  </div>

  <hr class="my-4">
  <h5>Aansluitadres</h5>
  <div class="row g-3">
    <?php if ($hasConnContact): ?>
      <div class="col-md-6">
        <label class="form-label">Contactpersoon</label>
        <input type="text" class="form-control" name="connect_contact">
      </div>
    <?php endif; ?>
    <?php if ($hasConnAddress): ?>
      <div class="col-md-6">
        <label class="form-label">Adres</label>
        <input type="text" class="form-control" name="connect_address">
      </div>
    <?php endif; ?>
    <?php if ($hasConnPostcode): ?>
      <div class="col-md-3">
        <label class="form-label">Postcode</label>
        <input type="text" class="form-control" name="connect_postcode">
      </div>
    <?php endif; ?>
    <?php if ($hasConnCity): ?>
      <div class="col-md-5">
        <label class="form-label">Woonplaats</label>
        <input type="text" class="form-control" name="connect_city">
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-4 d-flex gap-2">
    <button class="btn btn-primary" type="submit">Aanmaken</button>
    <a class="btn btn-outline-secondary" href="index.php?route=users_list">Annuleren</a>
  </div>
</form>
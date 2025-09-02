<?php
// pages/user_add.php — gebruiker toevoegen met Administratief + Aansluitadres
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

// Alleen managers mogen gebruikers toevoegen
if (!$isMgr) {
  flash_set('danger','Je hebt geen rechten om gebruikers toe te voegen.');
  redirect('index.php?route=users_list');
}

// ----- DB -----
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ----- helpers -----
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
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

// detecteer optionele kolommen (adresvelden)
$hasParent           = column_exists($pdo,'users','parent_user_id');
$hasIsActive         = column_exists($pdo,'users','is_active');
$hasPhone            = column_exists($pdo,'users','phone');

$hasAdminContact     = column_exists($pdo,'users','admin_contact');
$hasAdminAddress     = column_exists($pdo,'users','admin_address');
$hasAdminPostcode    = column_exists($pdo,'users','admin_postcode');
$hasAdminCity        = column_exists($pdo,'users','admin_city');

$hasConnectContact   = column_exists($pdo,'users','connect_contact');
$hasConnectAddress   = column_exists($pdo,'users','connect_address');
$hasConnectPostcode  = column_exists($pdo,'users','connect_postcode');
$hasConnectCity      = column_exists($pdo,'users','connect_city');

// Toegestane rollen per aanmaker
$allRoles = ['super_admin','reseller','sub_reseller','customer'];
if ($isSuper) {
  $allowedNewRoles = ['reseller','sub_reseller','customer']; // super kan iedereen aanmaken behalve nóg een super? (pas desgewenst aan)
} elseif ($isRes) {
  $allowedNewRoles = ['sub_reseller','customer'];
} else { // sub_reseller
  $allowedNewRoles = ['customer'];
}

// Parent-selectie:
// - super: mag elke parent kiezen (of geen)
// - reseller: parent binnen eigen boom (inclusief zichzelf)
// - sub_reseller: parent is zichzelf (voor customers); we tonen dropdown met alleen zichzelf, of verbergen hem.
function fetch_parent_options(PDO $pdo, array $me, bool $isSuper, bool $isRes, bool $isSubRes): array {
  if (!column_exists($pdo,'users','parent_user_id')) {
    // geen hiërarchie → alleen jezelf tonen (praktisch)
    return [ ['id'=>(int)$me['id'], 'name'=>$me['name'] ?? '—', 'role'=>$me['role'] ?? null] ];
  }

  if ($isSuper) {
    $st = $pdo->query("SELECT id,name,role FROM users ORDER BY name");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  // reseller/sub: eigen boom
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if (!$tree) $tree = [(int)$me['id']];
  $ph = implode(',', array_fill(0, count($tree), '?'));
  $st = $pdo->prepare("SELECT id,name,role FROM users WHERE id IN ($ph) ORDER BY name");
  $st->execute($tree);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // zeker weten dat 'self' erin zit
  $in = array_map('intval', array_column($rows,'id'));
  if (!in_array((int)$me['id'], $in, true)) {
    array_unshift($rows, ['id'=>(int)$me['id'],'name'=>$me['name'] ?? '—','role'=>$me['role'] ?? null]);
  }
  return $rows;
}

$parentOptions = $hasParent ? fetch_parent_options($pdo, $me, $isSuper, $isRes, $isSubRes) : [];

// ----- POST -----
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) {
    $errors[] = 'Sessie verlopen. Probeer opnieuw.';
  }

  $name     = trim((string)($_POST['name'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $confirm  = (string)($_POST['password_confirm'] ?? '');
  $roleNew  = trim((string)($_POST['role'] ?? ''));
  $phone    = trim((string)($_POST['phone'] ?? ''));
  $isActive = (int)($_POST['is_active'] ?? 1);

  // parent
  $parentId = null;
  if ($hasParent) {
    $parentId = isset($_POST['parent_user_id']) && $_POST['parent_user_id'] !== ''
      ? (int)$_POST['parent_user_id'] : null;

    if ($isSubRes) {
      // sub-reseller: forceer parent op zichzelf
      $parentId = (int)$me['id'];
    } elseif ($isRes) {
      // reseller: parent moet in eigen boom liggen (of zichzelf)
      if ($parentId !== null) {
        $tree = build_tree_ids($pdo, (int)$me['id']);
        if (!in_array((int)$parentId, array_map('intval',$tree), true)) {
          $errors[] = 'Ongeldige ouder (valt niet binnen jouw beheer).';
        }
      } else {
        // geen keuze → zelf
        $parentId = (int)$me['id'];
      }
    }
  }

  // validatie basis
  if ($name === '')   $errors[] = 'Naam is verplicht.';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';
  if (!in_array($roleNew, $allowedNewRoles, true)) $errors[] = 'Rol niet toegestaan voor jouw account.';
  if (strlen($password) < 8) $errors[] = 'Wachtwoord moet minimaal 8 tekens zijn.';
  if ($password !== $confirm) $errors[] = 'Wachtwoord en bevestiging komen niet overeen.';

  // unieke e-mail?
  if (!$errors) {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';
  }

  // admin adres
  $admin_contact  = trim((string)($_POST['admin_contact'] ?? ''));
  $admin_address  = trim((string)($_POST['admin_address'] ?? ''));
  $admin_postcode = trim((string)($_POST['admin_postcode'] ?? ''));
  $admin_city     = trim((string)($_POST['admin_city'] ?? ''));

  // aansluit adres
  $connect_contact  = trim((string)($_POST['connect_contact'] ?? ''));
  $connect_address  = trim((string)($_POST['connect_address'] ?? ''));
  $connect_postcode = trim((string)($_POST['connect_postcode'] ?? ''));
  $connect_city     = trim((string)($_POST['connect_city'] ?? ''));

  if (!$errors) {
    try {
      // dynamische INSERT op basis van bestaande kolommen
      $cols = ['name','email','role','password_hash'];
      $vals = [ $name, $email, $roleNew, password_hash($password, PASSWORD_DEFAULT) ];

      if ($hasPhone && $phone !== '') { $cols[]='phone'; $vals[]=$phone; }
      if ($hasIsActive) { $cols[]='is_active'; $vals[]=(int)$isActive; }
      if ($hasParent)   { $cols[]='parent_user_id'; $vals[]=$parentId; }

      // admin adres kolommen
      if ($hasAdminContact)    { $cols[]='admin_contact';    $vals[]=$admin_contact; }
      if ($hasAdminAddress)    { $cols[]='admin_address';    $vals[]=$admin_address; }
      if ($hasAdminPostcode)   { $cols[]='admin_postcode';   $vals[]=$admin_postcode; }
      if ($hasAdminCity)       { $cols[]='admin_city';       $vals[]=$admin_city; }

      // connect/adres kolommen
      if ($hasConnectContact)  { $cols[]='connect_contact';  $vals[]=$connect_contact; }
      if ($hasConnectAddress)  { $cols[]='connect_address';  $vals[]=$connect_address; }
      if ($hasConnectPostcode) { $cols[]='connect_postcode'; $vals[]=$connect_postcode; }
      if ($hasConnectCity)     { $cols[]='connect_city';     $vals[]=$connect_city; }

      $ph = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO users (".implode(',',$cols).") VALUES ({$ph})";
      $st = $pdo->prepare($sql);
      $st->execute($vals);

      flash_set('success','Gebruiker aangemaakt.');
      redirect('index.php?route=users_list');
    } catch (Throwable $e) {
      $errors[] = 'Opslaan mislukt: '.$e->getMessage();
    }
  }
}

// ----- Form weergave -----
include __DIR__ . '/../views/header.php';
?>

<h4>Nieuwe gebruiker</h4>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $er): ?>
        <li><?= e($er) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?route=user_add" class="row g-3">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="col-md-6">
    <label class="form-label">Naam</label>
    <input type="text" name="name" class="form-control" required value="<?= e($_POST['name'] ?? '') ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">E-mail</label>
    <input type="email" name="email" class="form-control" required value="<?= e($_POST['email'] ?? '') ?>">
  </div>

  <?php if ($hasPhone): ?>
  <div class="col-md-6">
    <label class="form-label">Telefoon</label>
    <input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <div class="col-md-3">
    <label class="form-label">Rol</label>
    <select name="role" class="form-select" required>
      <option value="">— kies een rol —</option>
      <?php foreach ($allowedNewRoles as $r): ?>
        <option value="<?= e($r) ?>" <?= (($_POST['role'] ?? '')===$r)?'selected':'' ?>><?= e($r) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">
      <?php if ($isSuper): ?>
        Super-admin mag reseller, sub-reseller en klant aanmaken.
      <?php elseif ($isRes): ?>
        Reseller mag sub-reseller en klant aanmaken.
      <?php else: ?>
        Sub-reseller mag klanten aanmaken.
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasIsActive): ?>
  <div class="col-md-3 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
        <?= (($_POST['is_active'] ?? '1') === '1') ? 'checked' : '' ?>>
      <label class="form-check-label" for="is_active">Actief</label>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($hasParent): ?>
  <div class="col-12">
    <label class="form-label">Hoort onder (parent)</label>
    <select name="parent_user_id" class="form-select" <?= $isSubRes ? 'disabled' : '' ?>>
      <?php if (!$isSubRes): ?>
        <option value="">— kies ouder —</option>
      <?php endif; ?>
      <?php foreach ($parentOptions as $po): ?>
        <?php
          $pid = (int)($po['id'] ?? 0);
          $label = '#'.$pid.' — '.($po['name'] ?? '—').(!empty($po['role']) ? ' ('.$po['role'].')' : '');
          $selected = ((string)($pid) === (string)($_POST['parent_user_id'] ?? '')) ? 'selected' : '';
          // sub-reseller: forceer parent op zichzelf
          if ($isSubRes && $pid !== (int)$me['id']) continue;
        ?>
        <option value="<?= $pid ?>" <?= $selected ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isSubRes): ?>
      <!-- Als disabled, toch waarde meesturen -->
      <input type="hidden" name="parent_user_id" value="<?= (int)$me['id'] ?>">
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="col-md-6">
    <label class="form-label">Wachtwoord</label>
    <input type="password" name="password" class="form-control" required>
  </div>

  <div class="col-md-6">
    <label class="form-label">Bevestig wachtwoord</label>
    <input type="password" name="password_confirm" class="form-control" required>
  </div>

  <!-- Administratief adres -->
  <div class="col-12"><hr></div>
  <div class="col-12"><h5>Administratief adres</h5></div>

  <?php if ($hasAdminContact): ?>
  <div class="col-md-6">
    <label class="form-label">Contactpersoon</label>
    <input type="text" name="admin_contact" class="form-control" value="<?= e($_POST['admin_contact'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminAddress): ?>
  <div class="col-md-6">
    <label class="form-label">Adres</label>
    <input type="text" name="admin_address" class="form-control" value="<?= e($_POST['admin_address'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminPostcode): ?>
  <div class="col-md-4">
    <label class="form-label">Postcode</label>
    <input type="text" name="admin_postcode" class="form-control" value="<?= e($_POST['admin_postcode'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminCity): ?>
  <div class="col-md-8">
    <label class="form-label">Woonplaats</label>
    <input type="text" name="admin_city" class="form-control" value="<?= e($_POST['admin_city'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <!-- Aansluitadres -->
  <div class="col-12"><hr></div>
  <div class="col-12"><h5>Aansluitadres</h5></div>

  <?php if ($hasConnectContact): ?>
  <div class="col-md-6">
    <label class="form-label">Contactpersoon</label>
    <input type="text" name="connect_contact" class="form-control" value="<?= e($_POST['connect_contact'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasConnectAddress): ?>
  <div class="col-md-6">
    <label class="form-label">Adres</label>
    <input type="text" name="connect_address" class="form-control" value="<?= e($_POST['connect_address'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasConnectPostcode): ?>
  <div class="col-md-4">
    <label class="form-label">Postcode</label>
    <input type="text" name="connect_postcode" class="form-control" value="<?= e($_POST['connect_postcode'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasConnectCity): ?>
  <div class="col-md-8">
    <label class="form-label">Woonplaats</label>
    <input type="text" name="connect_city" class="form-control" value="<?= e($_POST['connect_city'] ?? '') ?>">
  </div>
  <?php endif; ?>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Aanmaken</button>
    <a href="index.php?route=users_list" class="btn btn-outline-secondary">Annuleren</a>
  </div>
</form>

<?php include __DIR__ . '/../views/footer.php'; ?>
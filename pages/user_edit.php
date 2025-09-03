<?php
// pages/user_edit.php — gebruiker bewerken (incl. admin- & aansluitadres met connect_* of service_*)
// Layout (header/footer) wordt door index.php gedaan.
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) { echo '<div class="alert alert-warning">Ongeldige gebruiker.</div>'; return; }

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

// detecteer kolommen
$hasParent           = column_exists($pdo,'users','parent_user_id');
$hasIsActive         = column_exists($pdo,'users','is_active');
$hasPhone            = column_exists($pdo,'users','phone');

$hasAdminContact     = column_exists($pdo,'users','admin_contact');
$hasAdminAddress     = column_exists($pdo,'users','admin_address');
$hasAdminPostcode    = column_exists($pdo,'users','admin_postcode');
$hasAdminCity        = column_exists($pdo,'users','admin_city');

// Aansluitadres: prefer connect_*, fallback service_* (zoals in jouw SQL dump)
$connectCols = [
  'contact'  => column_exists($pdo,'users','connect_contact')  ? 'connect_contact'  : (column_exists($pdo,'users','service_contact')  ? 'service_contact'  : null),
  'address'  => column_exists($pdo,'users','connect_address')  ? 'connect_address'  : (column_exists($pdo,'users','service_address')  ? 'service_address'  : null),
  'postcode' => column_exists($pdo,'users','connect_postcode') ? 'connect_postcode' : (column_exists($pdo,'users','service_postcode') ? 'service_postcode' : null),
  'city'     => column_exists($pdo,'users','connect_city')     ? 'connect_city'     : (column_exists($pdo,'users','service_city')     ? 'service_city'     : null),
];

// ------- gebruiker laden -------
try {
  $cols = ['id','name','email','role'];
  if ($hasPhone)    $cols[]='phone';
  if ($hasIsActive) $cols[]='is_active';
  if ($hasParent)   $cols[]='parent_user_id';

  if ($hasAdminContact)  $cols[]='admin_contact';
  if ($hasAdminAddress)  $cols[]='admin_address';
  if ($hasAdminPostcode) $cols[]='admin_postcode';
  if ($hasAdminCity)     $cols[]='admin_city';

  foreach ($connectCols as $c) if ($c) $cols[] = $c;

  $sql = "SELECT ".implode(',', array_map(fn($c)=>"`$c`",$cols))." FROM users WHERE id = ? LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([$userId]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) { echo '<div class="alert alert-warning">Gebruiker niet gevonden.</div>'; return; }
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// ------- toegang/scope -------
if (!$isSuper) {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if (!in_array((int)$user['id'], array_map('intval',$tree), true)) {
    echo '<div class="alert alert-danger">Geen toegang om deze gebruiker te bewerken.</div>'; return;
  }
}

// Toegestane rollen per aanmaker
if ($isSuper) {
  $allowedEditRoles = ['reseller','sub_reseller','customer'];
} elseif ($isRes) {
  $allowedEditRoles = ['sub_reseller','customer'];
} else { // sub_reseller
  $allowedEditRoles = ['customer'];
}

// Parent-opties
function fetch_parent_options(PDO $pdo, array $me, bool $isSuper, bool $isRes, bool $isSubRes): array {
  if (!column_exists($pdo,'users','parent_user_id')) {
    return [ ['id'=>(int)$me['id'], 'name'=>$me['name'] ?? '—', 'role'=>$me['role'] ?? null] ];
  }

  if ($isSuper) {
    $st = $pdo->query("SELECT id,name,role FROM users ORDER BY name");
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  $tree = build_tree_ids($pdo, (int)$me['id']);
  if (!$tree) $tree = [(int)$me['id']];
  $ph = implode(',', array_fill(0, count($tree), '?'));
  $st = $pdo->prepare("SELECT id,name,role FROM users WHERE id IN ($ph) ORDER BY name");
  $st->execute($tree);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $in = array_map('intval', array_column($rows,'id'));
  if (!in_array((int)$me['id'], $in, true)) {
    array_unshift($rows, ['id'=>(int)$me['id'],'name'=>$me['name'] ?? '—','role'=>$me['role'] ?? null]);
  }
  return $rows;
}
$parentOptions = $hasParent ? fetch_parent_options($pdo, $me, $isSuper, $isRes, $isSubRes) : [];

// ------- POST: opslaan -------
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { $errors[] = 'Sessie verlopen. Probeer opnieuw.'; }

  $name     = trim((string)($_POST['name'] ?? $user['name'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? $user['email'] ?? ''));
  $phone    = trim((string)($_POST['phone'] ?? ($user['phone'] ?? '')));
  $roleNew  = trim((string)($_POST['role'] ?? $user['role'] ?? ''));
  $isActive = (int)($_POST['is_active'] ?? ($user['is_active'] ?? 1));

  $password = (string)($_POST['password'] ?? '');
  $confirm  = (string)($_POST['password_confirm'] ?? '');

  // parent
  $parentId = $hasParent ? (($_POST['parent_user_id'] ?? '') !== '' ? (int)$_POST['parent_user_id'] : null) : null;
  if ($isSubRes) {
    $parentId = (int)$me['id']; // sub-reseller: parent geforceerd op zichzelf
  } elseif ($isRes) {
    if ($parentId === null) $parentId = (int)$me['id'];
    else {
      $tree = build_tree_ids($pdo, (int)$me['id']);
      if (!in_array((int)$parentId, array_map('intval',$tree), true)) {
        $errors[] = 'Ongeldige ouder (valt niet binnen jouw beheer).';
      }
    }
  }

  // validatie
  if ($name === '')   $errors[] = 'Naam is verplicht.';
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';

  // rol beperken tot wat jij mag (super/res/sub)
  if (!in_array($roleNew, $allowedEditRoles, true)) {
    $errors[] = 'Rol niet toegestaan voor jouw account.';
  }

  // unieke email (behalve eigen record)
  if (!$errors && $email !== ($user['email'] ?? '')) {
    $st = $pdo->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ? LIMIT 1");
    $st->execute([$email, $userId]);
    if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';
  }

  // wachtwoord wijzigen (optioneel)
  $passwordHash = null;
  if ($password !== '' || $confirm !== '') {
    if (strlen($password) < 8) $errors[] = 'Wachtwoord moet minimaal 8 tekens zijn.';
    if ($password !== $confirm) $errors[] = 'Wachtwoord en bevestiging komen niet overeen.';
    if (!$errors) $passwordHash = password_hash($password, PASSWORD_DEFAULT);
  }

  // admin adres
  $admin_contact  = trim((string)($_POST['admin_contact'] ?? ($user['admin_contact'] ?? '')));
  $admin_address  = trim((string)($_POST['admin_address'] ?? ($user['admin_address'] ?? '')));
  $admin_postcode = trim((string)($_POST['admin_postcode'] ?? ($user['admin_postcode'] ?? '')));
  $admin_city     = trim((string)($_POST['admin_city'] ?? ($user['admin_city'] ?? '')));

  // aansluit adres (inputs heten connect_*, wegschrijven naar connect_* of service_*)
  $connect_contact  = trim((string)($_POST['connect_contact'] ?? ($user[$connectCols['contact']]  ?? '')));
  $connect_address  = trim((string)($_POST['connect_address'] ?? ($user[$connectCols['address']]  ?? '')));
  $connect_postcode = trim((string)($_POST['connect_postcode'] ?? ($user[$connectCols['postcode']] ?? '')));
  $connect_city     = trim((string)($_POST['connect_city'] ?? ($user[$connectCols['city']]     ?? '')));

  if (!$errors) {
    try {
      $sets = [];
      $vals = [];

      $sets[] = "name = ?";   $vals[] = $name;
      $sets[] = "email = ?";  $vals[] = $email;

      if ($hasPhone)    { $sets[]="phone = ?";         $vals[]=$phone; }
      if ($hasIsActive) { $sets[]="is_active = ?";     $vals[]=(int)$isActive; }
      if ($hasParent)   { $sets[]="parent_user_id = ?";$vals[]=$parentId; }

      // rol mag aangepast worden binnen beleid
      $sets[] = "role = ?"; $vals[] = $roleNew;

      if ($passwordHash) { $sets[] = "password_hash = ?"; $vals[] = $passwordHash; }

      // admin adres
      if ($hasAdminContact)  { $sets[]="admin_contact = ?";  $vals[]=$admin_contact; }
      if ($hasAdminAddress)  { $sets[]="admin_address = ?";  $vals[]=$admin_address; }
      if ($hasAdminPostcode) { $sets[]="admin_postcode = ?"; $vals[]=$admin_postcode; }
      if ($hasAdminCity)     { $sets[]="admin_city = ?";     $vals[]=$admin_city; }

      // aansluitadres (naar connect_* of service_*)
      if ($connectCols['contact'])  { $sets[]="`{$connectCols['contact']}` = ?";   $vals[]=$connect_contact; }
      if ($connectCols['address'])  { $sets[]="`{$connectCols['address']}` = ?";   $vals[]=$connect_address; }
      if ($connectCols['postcode']) { $sets[]="`{$connectCols['postcode']}` = ?";  $vals[]=$connect_postcode; }
      if ($connectCols['city'])     { $sets[]="`{$connectCols['city']}` = ?";      $vals[]=$connect_city; }

      $vals[] = $userId;
      $sql = "UPDATE users SET ".implode(', ', $sets)." WHERE id = ? LIMIT 1";
      $st  = $pdo->prepare($sql);
      $st->execute($vals);

      flash_set('success','Gebruiker bijgewerkt.');
      redirect('index.php?route=users_list');
    } catch (Throwable $e) {
      $errors[] = 'Opslaan mislukt: '.$e->getMessage();
    }
  }
}

// ------- waarden voor formulier -------
$fv = [
  'name'  => $_POST['name']  ?? $user['name']  ?? '',
  'email' => $_POST['email'] ?? $user['email'] ?? '',
  'phone' => $_POST['phone'] ?? ($user['phone'] ?? ''),
  'role'  => $_POST['role']  ?? $user['role']  ?? '',
  'is_active' => (string)($_POST['is_active'] ?? ($user['is_active'] ?? '1')),
  'parent_user_id' => $_POST['parent_user_id'] ?? ($user['parent_user_id'] ?? ''),
  // admin adres
  'admin_contact'  => $_POST['admin_contact']  ?? ($user['admin_contact']  ?? ''),
  'admin_address'  => $_POST['admin_address']  ?? ($user['admin_address']  ?? ''),
  'admin_postcode' => $_POST['admin_postcode'] ?? ($user['admin_postcode'] ?? ''),
  'admin_city'     => $_POST['admin_city']     ?? ($user['admin_city']     ?? ''),
  // aansluit (connect_* inputs; mapping naar connect_/service_ bij save)
  'connect_contact'  => $_POST['connect_contact']  ?? ($connectCols['contact']  ? ($user[$connectCols['contact']]  ?? '') : ''),
  'connect_address'  => $_POST['connect_address']  ?? ($connectCols['address']  ? ($user[$connectCols['address']]  ?? '') : ''),
  'connect_postcode' => $_POST['connect_postcode'] ?? ($connectCols['postcode'] ? ($user[$connectCols['postcode']] ?? '') : ''),
  'connect_city'     => $_POST['connect_city']     ?? ($connectCols['city']     ? ($user[$connectCols['city']]     ?? '') : ''),
];

// ------- weergave -------
?>
<h4>Gebruiker bewerken — #<?= (int)$user['id'] ?></h4>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $er): ?>
        <li><?= e($er) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?route=user_edit&id=<?= (int)$user['id'] ?>" class="row g-3">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="col-md-6">
    <label class="form-label">Naam</label>
    <input type="text" name="name" class="form-control" required value="<?= e($fv['name']) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">E-mail</label>
    <input type="email" name="email" class="form-control" required value="<?= e($fv['email']) ?>">
  </div>

  <?php if ($hasPhone): ?>
  <div class="col-md-6">
    <label class="form-label">Telefoon</label>
    <input type="text" name="phone" class="form-control" value="<?= e($fv['phone']) ?>">
  </div>
  <?php endif; ?>

  <div class="col-md-3">
    <label class="form-label">Rol</label>
    <select name="role" class="form-select" required>
      <?php foreach ($allowedEditRoles as $r): ?>
        <option value="<?= e($r) ?>" <?= ($fv['role']===$r)?'selected':'' ?>><?= e($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($hasIsActive): ?>
  <div class="col-md-3 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
        <?= ($fv['is_active'] === '1' || $fv['is_active'] === 1) ? 'checked' : '' ?>>
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
          $selected = ((string)$pid === (string)$fv['parent_user_id']) ? 'selected' : '';
          if ($isSubRes && $pid !== (int)$me['id']) continue;
        ?>
        <option value="<?= $pid ?>" <?= $selected ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isSubRes): ?>
      <input type="hidden" name="parent_user_id" value="<?= (int)$me['id'] ?>">
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="col-md-6">
    <label class="form-label">Nieuw wachtwoord (optioneel)</label>
    <input type="password" name="password" class="form-control" placeholder="Laat leeg om niet te wijzigen">
  </div>

  <div class="col-md-6">
    <label class="form-label">Bevestig nieuw wachtwoord</label>
    <input type="password" name="password_confirm" class="form-control" placeholder="Nogmaals">
  </div>

  <!-- Administratief adres -->
  <div class="col-12"><hr></div>
  <div class="col-12"><h5>Administratief adres</h5></div>

  <?php if ($hasAdminContact): ?>
  <div class="col-md-6">
    <label class="form-label">Contactpersoon</label>
    <input type="text" name="admin_contact" class="form-control" value="<?= e($fv['admin_contact']) ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminAddress): ?>
  <div class="col-md-6">
    <label class="form-label">Adres</label>
    <input type="text" name="admin_address" class="form-control" value="<?= e($fv['admin_address']) ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminPostcode): ?>
  <div class="col-md-4">
    <label class="form-label">Postcode</label>
    <input type="text" name="admin_postcode" class="form-control" value="<?= e($fv['admin_postcode']) ?>">
  </div>
  <?php endif; ?>

  <?php if ($hasAdminCity): ?>
  <div class="col-md-8">
    <label class="form-label">Woonplaats</label>
    <input type="text" name="admin_city" class="form-control" value="<?= e($fv['admin_city']) ?>">
  </div>
  <?php endif; ?>

  <!-- Aansluitadres (inputs heten connect_*, mapping naar connect_/service_) -->
  <div class="col-12"><hr></div>
  <div class="col-12"><h5>Aansluitadres</h5></div>

  <div class="col-md-6">
    <label class="form-label">Contactpersoon</label>
    <input type="text" name="connect_contact" class="form-control" value="<?= e($fv['connect_contact']) ?>">
  </div>

  <div class="col-md-6">
    <label class="form-label">Adres</label>
    <input type="text" name="connect_address" class="form-control" value="<?= e($fv['connect_address']) ?>">
  </div>

  <div class="col-md-4">
    <label class="form-label">Postcode</label>
    <input type="text" name="connect_postcode" class="form-control" value="<?= e($fv['connect_postcode']) ?>">
  </div>

  <div class="col-md-8">
    <label class="form-label">Woonplaats</label>
    <input type="text" name="connect_city" class="form-control" value="<?= e($fv['connect_city']) ?>">
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary">Opslaan</button>
    <a href="index.php?route=users_list" class="btn btn-outline-secondary">Annuleren</a>
  </div>
</form>
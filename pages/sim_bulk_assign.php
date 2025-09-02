<?php
// pages/sim_bulk_assign.php — bulk toewijzen van simkaarten aan een gebruiker in scope
require_once __DIR__ . '/../helpers.php';

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);
if (!$isMgr) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Geen toestemming.'));
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Ongeldige aanroep.'));
  exit;
}
try { if (function_exists('verify_csrf')) verify_csrf(); } catch(Throwable $e){
  header('Location: index.php?route=sims_list&error='.rawurlencode('Ongeldige sessie (CSRF).')); exit;
}

// PDO
function get_pdo(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
  foreach ($candidates as $f) if (is_file($f)) { require_once $f; if ($GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; }
  $cfg = app_config(); $db=$cfg['db']??[]; $dsn=$db['dsn']??null;
  if ($dsn) $pdo = new PDO($dsn, $db['user']??null, $db['pass']??null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
  ]);
  else {
    $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
    if ($name==='') throw new RuntimeException('DB-naam ontbreekt in config');
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=$charset",$user,$pass,[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
  }
  return $GLOBALS['pdo']=$pdo;
}
$pdo = get_pdo();

function column_exists(PDO $pdo, string $table, string $column): bool {
  $q = $pdo->quote($column);
  $res = $pdo->query("SHOW COLUMNS FROM `$table` LIKE $q");
  return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
function users_under(PDO $pdo, int $rootId): array {
  $ids = [$rootId];
  $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
  if (!$st || !$st->fetch()) return $ids;
  $queue = [$rootId]; $seen = [$rootId=>true];
  while ($queue) {
    $chunk = array_splice($queue,0,100);
    $ph = implode(',', array_fill(0,count($chunk),'?'));
    $st2=$pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
    $st2->execute(array_map('intval',$chunk));
    foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $cid) { $cid=(int)$cid; if(!isset($seen[$cid])){$seen[$cid]=true;$ids[]=$cid;$queue[]=$cid;} }
  }
  return $ids;
}

// Input
$ids = array_map('intval', $_POST['ids'] ?? []);
if (!$ids) {
  $csv = trim((string)($_POST['ids_csv'] ?? ''));
  if ($csv!=='') $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
}
$ids = array_values(array_unique(array_filter($ids)));
$targetUserId = (int)($_POST['target_user_id'] ?? 0);

if (!$ids) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Geen simkaarten geselecteerd.'));
  exit;
}
if ($targetUserId <= 0) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Geen doelgebruiker gekozen.'));
  exit;
}

// Check doelgebruiker: rol toegestaan & in scope
$st = $pdo->prepare("SELECT id, role, name FROM users WHERE id = ? LIMIT 1");
$st->execute([$targetUserId]);
$target = $st->fetch(PDO::FETCH_ASSOC);
if (!$target) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Doelgebruiker niet gevonden.'));
  exit;
}
$allowedRoles = ['reseller','sub_reseller','customer'];
if (defined('ROLE_RESELLER'))    $allowedRoles[] = ROLE_RESELLER;
if (defined('ROLE_SUBRESELLER')) $allowedRoles[] = ROLE_SUBRESELLER;
if (defined('ROLE_CUSTOMER'))    $allowedRoles[] = ROLE_CUSTOMER;

if (!in_array($target['role'], $allowedRoles, true)) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Doelgebruiker heeft geen toewijsbare rol.'));
  exit;
}

// Scope op doelgebruiker (niet-super mag alleen binnen eigen boom)
if (!$isSuper) {
  $myTree = users_under($pdo,(int)$me['id']);
  if (!in_array((int)$targetUserId, $myTree, true)) {
    header('Location: index.php?route=sims_list&error='.rawurlencode('Doelgebruiker valt buiten jouw scope.'));
    exit;
  }
}

// Kolommen sims aanwezig?
$hasAssigned = column_exists($pdo,'sims','assigned_to_user_id');
$hasRetired  = column_exists($pdo,'sims','retired');

if (!$hasAssigned) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Kolom assigned_to_user_id ontbreekt in sims.'));
  exit;
}

// Filter SIMs die we mogen aanpassen:
// - niet-super: alleen SIMs die je bezit of die aan je boom hangen
$params=[]; $where=[];
$in = implode(',', array_fill(0,count($ids),'?'));
$where[] = "s.id IN ($in)";
$params = array_merge($params,$ids);

if (!$isSuper) {
  $tree = users_under($pdo,(int)$me['id']);
  if (!$tree) { header('Location: index.php?route=sims_list&error='.rawurlencode('Geen scope.')); exit; }
  $in2 = implode(',', array_fill(0,count($tree),'?'));
  $scopes = [];
  if (column_exists($pdo,'sims','owner_user_id'))        $scopes[]="s.owner_user_id IN ($in2)";
  if (column_exists($pdo,'sims','assigned_to_user_id'))  $scopes[]="s.assigned_to_user_id IN ($in2) OR s.assigned_to_user_id IS NULL";
  if ($scopes) { $where[] = '('.implode(' OR ',$scopes).')'; $params = array_merge($params,$tree,$tree); }
}

// Retired SIMs niet toewijzen
if ($hasRetired) { $where[] = "COALESCE(s.retired,0)=0"; }

$sqlSel = "SELECT s.id FROM sims s WHERE ".implode(' AND ',$where);
$st = $pdo->prepare($sqlSel);
$st->execute($params);
$candidates = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
if (!$candidates) {
  header('Location: index.php?route=sims_list&error='.rawurlencode('Geen selecties binnen je scope om toe te wijzen.'));
  exit;
}

// Uitvoeren
try {
  $pdo->beginTransaction();
  $inD = implode(',', array_fill(0,count($candidates),'?'));
  $bind = array_merge([(int)$targetUserId], $candidates);
  $pdo->prepare("UPDATE sims SET assigned_to_user_id = ? WHERE id IN ($inD)")->execute($bind);
  $pdo->commit();
  header('Location: index.php?route=sims_list&msg='.rawurlencode('Toegewezen: '.count($candidates).' simkaart(en) aan '.$target['name'].'.'));
  exit;
} catch(Throwable $e){
  if ($pdo->inTransaction()) try{$pdo->rollBack();}catch(Throwable $e2){}
  header('Location: index.php?route=sims_list&error='.rawurlencode('Toewijzen mislukt: '.$e->getMessage()));
  exit;
}
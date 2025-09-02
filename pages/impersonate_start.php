<?php
// pages/impersonate_start.php — start impersonatie via POST
require_once __DIR__ . '/../helpers.php';

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  header('Location: index.php?route=users_list&error='.rawurlencode('Ongeldige aanroep.')); exit;
}

try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) { header('Location: index.php?route=users_list&error='.rawurlencode('Ongeldige sessie (CSRF).')); exit; }

// --- DB ---
function get_pdo(): PDO {
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
  $cfg = app_config(); $db=$cfg['db']??[];
  if (!empty($db['dsn'])) {
    $pdo = new PDO($db['dsn'], $db['user']??null, $db['pass']??null, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
  } else {
    $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=$charset",$user,$pass,[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
  }
  return $GLOBALS['pdo']=$pdo;
}
$pdo = get_pdo();

// --- helpers ---
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
    foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $cid) {
      $cid=(int)$cid; if(!isset($seen[$cid])){$seen[$cid]=true;$ids[]=$cid;$queue[]=$cid;}
    }
  }
  return $ids;
}

// --- input ---
$targetId = (int)($_POST['impersonate_user_id'] ?? $_POST['target_user_id'] ?? $_POST['user_id'] ?? 0);
if ($targetId <= 0) {
  header('Location: index.php?route=users_list&error='.rawurlencode('Geen doelgebruiker opgegeven.')); exit;
}

// --- doelgebruiker ---
$st = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE id = ? LIMIT 1");
$st->execute([$targetId]);
$target = $st->fetch(PDO::FETCH_ASSOC);
if (!$target) { header('Location: index.php?route=users_list&error='.rawurlencode('Gebruiker niet gevonden.')); exit; }

// --- rechten ---
if ($isSuper) {
  // super mag iedereen
} elseif ($isRes || $isSubRes) {
  $tree = users_under($pdo,(int)$me['id']);
  if (!in_array((int)$target['id'], $tree, true)) {
    header('Location: index.php?route=users_list&error='.rawurlencode('Doelgebruiker valt buiten jouw scope.')); exit;
  }
} else {
  header('Location: index.php?route=users_list&error='.rawurlencode('Geen toestemming.')); exit;
}

// --- wissel ---
if (empty($_SESSION['impersonator_id'])) {
  $_SESSION['impersonator_id'] = (int)$me['id'];  // onthoud wie begon
}
$_SESSION['user_id'] = (int)$target['id'];        // <<< HIER GEBEURT DE SWITCH

// eventuele caches opruimen
unset($_SESSION['auth_user'], $_SESSION['cached_user']);

if (function_exists('session_regenerate_id')) {
  @session_regenerate_id(true);
}

// klaar
header('Location: index.php?route=dashboard&msg='.rawurlencode('Ingelogd als '.$target['name'].'.'));
exit;
<?php
// pages/user_delete.php — single + bulk delete met rol- & scope-checks
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

// Alleen POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  flash_set('danger','Ongeldige aanroep (POST vereist).');
  redirect('index.php?route=users_list');
}

// CSRF
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
  flash_set('danger','Sessie verlopen. Probeer opnieuw.');
  redirect('index.php?route=users_list');
}

// DB
try { $pdo = db(); }
catch (Throwable $e) {
  flash_set('danger','DB niet beschikbaar: '.$e->getMessage());
  redirect('index.php?route=users_list');
}

// Helpers
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

// IDs uit POST (id of ids[])
$ids = [];
if (isset($_POST['id']) && is_numeric($_POST['id'])) {
  $ids[] = (int)$_POST['id'];
}
if (!empty($_POST['ids']) && is_array($_POST['ids'])) {
  foreach ($_POST['ids'] as $v) if (is_numeric($v)) $ids[] = (int)$v;
}
$ids = array_values(array_unique(array_filter($ids, fn($v)=>$v>0)));

if (!$ids) {
  flash_set('warning','Geen gebruikers geselecteerd om te verwijderen.');
  redirect('index.php?route=users_list');
}

// Super/sub/res mogen verwijderen? Policy:
// - Super-admin: mag alles, maar niet zichzelf.
// - Reseller/Sub-reseller: alleen gebruikers binnen eigen boom; niet zichzelf; niet super_admins.
$tree = $isSuper ? null : build_tree_ids($pdo, (int)$me['id']);

$ph = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT id, role FROM users WHERE id IN ($ph)";
$st  = $pdo->prepare($sql);
$st->execute($ids);
$targets = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$targets) {
  flash_set('warning','Geen geldige gebruikers gevonden.');
  redirect('index.php?route=users_list');
}

$allowedIds = [];
foreach ($targets as $t) {
  $uid  = (int)$t['id'];
  $urole= (string)$t['role'];

  // niet jezelf
  if ($uid === (int)$me['id']) continue;

  if ($isSuper) {
    // super: mag alles
    $allowedIds[] = $uid;
  } else {
    // reseller/sub: in eigen boom, geen super_admin target
    if ($urole === 'super_admin') continue;
    if (in_array($uid, array_map('intval',$tree), true)) {
      $allowedIds[] = $uid;
    }
  }
}

if (!$allowedIds) {
  flash_set('warning','Geen van de geselecteerde gebruikers valt binnen jouw rechten om te verwijderen.');
  redirect('index.php?route=users_list');
}

// Probeer te verwijderen
try {
  $pdo->beginTransaction();

  // Eventuele cascades/constraints kunnen delete blokkeren; probeer per stuk voor betere foutmelding
  $deleted = 0;
  $blocked = 0;

  $del = $pdo->prepare("DELETE FROM users WHERE id = ? LIMIT 1");

  foreach ($allowedIds as $uid) {
    try {
      $del->execute([$uid]);
      if ($del->rowCount() > 0) {
        $deleted++;
      } else {
        $blocked++; // niets verwijderd (mogelijk constraints)
      }
    } catch (Throwable $e) {
      // constraint/foreign key — tel als geblokkeerd
      $blocked++;
    }
  }

  $pdo->commit();

  if ($deleted > 0) {
    $msg = "Verwijderd: {$deleted} gebruiker(s).";
    if ($blocked > 0) $msg .= " Overgeslagen: {$blocked} (geblokkeerd door afhankelijkheden).";
    flash_set('success', $msg);
  } else {
    flash_set('warning', 'Geen gebruikers verwijderd (mogelijk geblokkeerd door afhankelijkheden).');
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_set('danger','Verwijderen mislukt: '.$e->getMessage());
}

redirect('index.php?route=users_list');
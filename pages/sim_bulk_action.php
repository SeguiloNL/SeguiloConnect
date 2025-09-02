<?php
// pages/sim_bulk_action.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

// Alleen beheerders (super/res/sub) mogen bulkacties
if (!$isMgr) {
  flash_set('danger', 'Geen rechten voor bulkacties op simkaarten.');
  redirect('index.php?route=sims_list');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  flash_set('danger', 'Ongeldige aanroep (POST vereist).');
  redirect('index.php?route=sims_list');
}

// CSRF
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
  flash_set('danger', 'Sessie verlopen, probeer opnieuw.');
  redirect('index.php?route=sims_list');
}

$action = trim((string)($_POST['action'] ?? ''));
$ids    = $_POST['ids'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
  flash_set('warning', 'Geen simkaarten geselecteerd.');
  redirect('index.php?route=sims_list');
}

// Filter IDs
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, fn($v) => $v > 0);
if (!$ids) {
  flash_set('warning', 'Geen geldige simkaart-ID’s geselecteerd.');
  redirect('index.php?route=sims_list');
}

try { $pdo = db(); }
catch (Throwable $e) {
  flash_set('danger', 'DB niet beschikbaar: ' . $e->getMessage());
  redirect('index.php?route=sims_list');
}

// --- helpers ---
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `$table` LIKE $q");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE $q")->fetchColumn();
}
// boom van users
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

// --- schema detectie ---
$hasAssignedCol = column_exists($pdo,'sims','assigned_to_user_id');
$hasStatusCol   = column_exists($pdo,'sims','status');
$ordersHasSim   = table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id');

// --- common: scopefilter voor res/sub ---
// Res/Sub mogen alleen simkaarten beheren die bij henzelf of hun boom horen.
// Dat betekent: assigned_to_user_id IS NULL of assigned_to_user_id IN (tree)
$scopeWhere = '';
$scopeArgs  = [];
if (!$isSuper && $hasAssignedCol) {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  $ph   = implode(',', array_fill(0, count($tree), '?'));
  $scopeWhere = " AND (s.assigned_to_user_id IS NULL OR s.assigned_to_user_id IN ($ph))";
  $scopeArgs  = $tree;
}

// --- acties ---
switch ($action) {
  case 'assign': {
    if (!$hasAssignedCol) {
      flash_set('danger','Toewijzen niet mogelijk: kolom sims.assigned_to_user_id ontbreekt.');
      redirect('index.php?route=sims_list');
    }
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    if ($targetUserId <= 0) {
      flash_set('warning','Kies een doelgebruiker voor toewijzen (target_user_id ontbreekt).');
      redirect('index.php?route=sims_list');
    }

    // scope: res/sub mogen alleen binnen eigen boom targetten
    if (!$isSuper) {
      $tree = $tree ?? build_tree_ids($pdo, (int)$me['id']);
      if (!in_array($targetUserId, array_map('intval', $tree), true)) {
        flash_set('danger','Doelgebruiker valt niet binnen jouw beheer.');
        redirect('index.php?route=sims_list');
      }
    }

    // update
    $phIds = implode(',', array_fill(0, count($ids), '?'));
    $args  = $ids;

    $sql = "UPDATE sims s
            SET assigned_to_user_id = ?"
          . ($hasStatusCol ? ", status = 'assigned'" : "")
          . " WHERE s.id IN ($phIds)";

    // scope extra voor res/sub
    if ($scopeWhere) {
      $sql .= $scopeWhere;
      $args = array_merge([ $targetUserId ], $ids, $scopeArgs);
    } else {
      array_unshift($args, $targetUserId);
    }

    try {
      $st = $pdo->prepare($sql);
      $st->execute($args);
      $affected = $st->rowCount();
      if ($affected === 0) {
        flash_set('warning','Geen simkaarten toegewezen (mogelijk buiten jouw beheer of al toegewezen).');
      } else {
        flash_set('success', "Toegewezen: {$affected} simkaart(en) aan gebruiker #{$targetUserId}.");
      }
    } catch (Throwable $e) {
      flash_set('danger','Bulk toewijzen mislukt: ' . $e->getMessage());
    }
    redirect('index.php?route=sims_list');
    break;
  }

  case 'unassign': {
    // optioneel: terug naar voorraad
    if (!$hasAssignedCol) {
      flash_set('danger','Voorraad-actie niet mogelijk: kolom sims.assigned_to_user_id ontbreekt.');
      redirect('index.php?route=sims_list');
    }
    $phIds = implode(',', array_fill(0, count($ids), '?'));
    $args  = $ids;

    $sql = "UPDATE sims s
            SET assigned_to_user_id = NULL"
          . ($hasStatusCol ? ", status = 'stock'" : "")
          . " WHERE s.id IN ($phIds)";

    if ($scopeWhere) {
      $sql .= $scopeWhere;
      $args = array_merge($ids, $scopeArgs);
    }

    try {
      $st = $pdo->prepare($sql);
      $st->execute($args);
      $affected = $st->rowCount();
      if ($affected === 0) {
        flash_set('warning','Geen simkaarten bijgewerkt (mogelijk buiten jouw beheer).');
      } else {
        flash_set('success', "Naar voorraad gezet: {$affected} simkaart(en).");
      }
    } catch (Throwable $e) {
      flash_set('danger','Bulk voorraad-actie mislukt: ' . $e->getMessage());
    }
    redirect('index.php?route=sims_list&status=stock');
    break;
  }

  case 'delete': {
    // Alleen super-admin
    if (!$isSuper) {
      flash_set('danger','Alleen Super-admin mag simkaarten verwijderen.');
      redirect('index.php?route=sims_list');
    }

    // Verwijder alleen simkaarten zonder gekoppelde orders (voorkomt FK-fouten)
    if ($ordersHasSim) {
      $phIds = implode(',', array_fill(0, count($ids), '?'));
      // tel eerst welke veilig zijn
      $sqlCount = "SELECT COUNT(*) FROM sims s
                   LEFT JOIN orders o ON o.sim_id = s.id
                   WHERE s.id IN ($phIds) AND o.sim_id IS NULL";
      $stc = $pdo->prepare($sqlCount);
      $stc->execute($ids);
      $canDelete = (int)$stc->fetchColumn();

      $sqlDel = "DELETE s FROM sims s
                 LEFT JOIN orders o ON o.sim_id = s.id
                 WHERE s.id IN ($phIds) AND o.sim_id IS NULL";
      $std = $pdo->prepare($sqlDel);
      $std->execute($ids);

      $deleted = $std->rowCount();
      $skipped = count($ids) - $deleted;
      if ($deleted > 0) {
        $msg = "Verwijderd: {$deleted} simkaart(en)";
        if ($skipped > 0) $msg .= " — Overgeslagen (gekoppeld aan orders): {$skipped}";
        flash_set('success', $msg . '.');
      } else {
        flash_set('warning', 'Geen simkaarten verwijderd (mogelijk gekoppeld aan orders).');
      }
    } else {
      // Geen zicht op orders → harde delete van de selectie
      $phIds = implode(',', array_fill(0, count($ids), '?'));
      $std = $pdo->prepare("DELETE FROM sims WHERE id IN ($phIds)");
      try {
        $std->execute($ids);
        $deleted = $std->rowCount();
        flash_set('success', "Verwijderd: {$deleted} simkaart(en).");
      } catch (Throwable $e) {
        flash_set('danger', 'Verwijderen mislukt: ' . $e->getMessage());
      }
    }

    redirect('index.php?route=sims_list');
    break;
  }

  default: {
    flash_set('warning','Onbekende bulkactie.');
    redirect('index.php?route=sims_list');
  }
}
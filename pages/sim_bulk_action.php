<?php
// pages/sim_bulk_action.php — bulk & single-row acties voor sims
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

// --- DB ---
try { $pdo = db(); }
catch (Throwable $e) {
  flash_set('danger', 'DB niet beschikbaar: ' . $e->getMessage());
  redirect('index.php?route=sims_list');
}

// --- helpers ---
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
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
function user_exists(PDO $pdo, int $userId): bool {
  $st = $pdo->prepare("SELECT 1 FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  return (bool)$st->fetchColumn();
}

// --- schema detectie ---
$hasAssignedCol = column_exists($pdo,'sims','assigned_to_user_id');
$hasStatusCol   = column_exists($pdo,'sims','status');
$ordersHasSim   = table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id');

// --- input ---
$action = trim((string)($_POST['action'] ?? ''));
$ids    = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [];
$ids = array_values(array_unique(array_map('intval', $ids)));
$ids = array_filter($ids, fn($v) => $v > 0);

if (!$ids) {
  flash_set('warning', 'Geen simkaarten geselecteerd.');
  redirect('index.php?route=sims_list');
}

// --- scopefilter voor reseller/sub-reseller ---
$scopeSql = '';
$scopeParams = [];
if (!$isSuper && $hasAssignedCol) {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if ($tree) {
    $ph = implode(',', array_fill(0, count($tree), '?'));
    // Res/Sub: alleen eigen boom of voorraad (NULL)
    $scopeSql = " AND (s.assigned_to_user_id IS NULL OR s.assigned_to_user_id IN ($ph))";
    $scopeParams = $tree;
  }
}

// --- eligible ids binnen scope (en evt. zonder orders voor delete) bepalen ---
$idsPh = implode(',', array_fill(0, count($ids), '?'));
$sqlEligible = "SELECT s.id FROM sims s WHERE s.id IN ($idsPh)" . $scopeSql;
$paramsEligible = array_merge($ids, $scopeParams);

if ($action === 'delete' && $ordersHasSim) {
  $sqlEligible .= " AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.sim_id = s.id)";
}

try {
  $st = $pdo->prepare($sqlEligible);
  $st->execute($paramsEligible);
  $eligibleIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
  flash_set('danger', 'Selectie bepalen mislukt: ' . $e->getMessage());
  redirect('index.php?route=sims_list');
}

if (!$eligibleIds) {
  $msg = 'Geen simkaarten binnen jouw beheer';
  if ($action === 'delete') $msg .= ' (of gekoppeld aan orders)';
  flash_set('warning', $msg . '.');
  redirect('index.php?route=sims_list');
}

// --- acties uitvoeren ---
try {
  switch ($action) {
    case 'assign': {
      if (!$hasAssignedCol) {
        flash_set('danger','Toewijzen niet mogelijk: kolom sims.assigned_to_user_id ontbreekt.');
        redirect('index.php?route=sims_list');
      }
      $targetUserId = (int)($_POST['target_user_id'] ?? 0);
      if ($targetUserId <= 0) {
        flash_set('warning','Kies een doelgebruiker in de dropdown.');
        redirect('index.php?route=sims_list');
      }
      if (!user_exists($pdo, $targetUserId)) {
        flash_set('danger','Doelgebruiker bestaat niet (meer).');
        redirect('index.php?route=sims_list');
      }
      // Res/Sub: doelgebruiker moet in eigen boom liggen
      if (!$isSuper) {
        $tree = $tree ?? build_tree_ids($pdo, (int)$me['id']);
        if (!in_array($targetUserId, array_map('intval',$tree), true)) {
          flash_set('danger','Doelgebruiker valt niet binnen jouw beheer.');
          redirect('index.php?route=sims_list');
        }
      }

      $idsPh2 = implode(',', array_fill(0, count($eligibleIds), '?'));
      $sql = "UPDATE sims
              SET assigned_to_user_id = ?"
            . ($hasStatusCol ? ", status = 'assigned'" : "")
            . " WHERE id IN ($idsPh2)";
      $params = array_merge([$targetUserId], $eligibleIds);

      $st = $pdo->prepare($sql);
      $st->execute($params);
      $affected = $st->rowCount();

      if ($affected > 0) {
        flash_set('success', "Toegewezen: {$affected} simkaart(en) aan gebruiker #{$targetUserId}.");
      } else {
        flash_set('warning', 'Geen simkaarten toegewezen (mogelijk reeds toegewezen of buiten scope).');
      }
      break;
    }

    case 'unassign': {
      if (!$hasAssignedCol) {
        flash_set('danger','Voorraad-actie niet mogelijk: kolom sims.assigned_to_user_id ontbreekt.');
        redirect('index.php?route=sims_list');
      }
      $idsPh2 = implode(',', array_fill(0, count($eligibleIds), '?'));
      $sql = "UPDATE sims
              SET assigned_to_user_id = NULL"
            . ($hasStatusCol ? ", status = 'stock'" : "")
            . " WHERE id IN ($idsPh2)";
      $st = $pdo->prepare($sql);
      $st->execute($eligibleIds);
      $affected = $st->rowCount();

      if ($affected > 0) {
        flash_set('success', "Naar voorraad gezet: {$affected} simkaart(en).");
      } else {
        flash_set('warning', 'Geen simkaarten bijgewerkt (mogelijk buiten scope).');
      }
      break;
    }

    case 'delete': {
      if (!$isSuper) {
        flash_set('danger','Alleen Super-admin mag simkaarten verwijderen.');
        redirect('index.php?route=sims_list');
      }
      $idsPh2 = implode(',', array_fill(0, count($eligibleIds), '?'));
      $sql = "DELETE FROM sims WHERE id IN ($idsPh2)";
      $st = $pdo->prepare($sql);
      $st->execute($eligibleIds);
      $deleted = $st->rowCount();

      if ($deleted > 0) {
        $skipped = count($ids) - $deleted;
        $msg = "Verwijderd: {$deleted} simkaart(en).";
        if ($ordersHasSim && $skipped > 0) {
          $msg .= " Overgeslagen i.v.m. gekoppelde orders: {$skipped}.";
        }
        flash_set('success', $msg);
      } else {
        $extra = $ordersHasSim ? ' (mogelijk gekoppeld aan orders)' : '';
        flash_set('warning', 'Geen simkaarten verwijderd' . $extra . '.');
      }
      break;
    }

    default:
      flash_set('warning','Onbekende bulkactie.');
  }
} catch (Throwable $e) {
  flash_set('danger','Actie mislukt: ' . $e->getMessage());
}

// Terug naar lijst (behoud query string indien gewenst)
$redirect = 'index.php?route=sims_list';
$qs = $_SERVER['HTTP_REFERER'] ?? '';
if ($qs && str_contains($qs, 'route=sims_list')) {
  // ga terug naar de pagina waar je was (met page/status)
  $redirect = $qs;
}
redirect($redirect);
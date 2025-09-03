<?php
// pages/order_cancel.php — Annuleren van een bestelling
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
  flash_set('danger', 'Je hebt geen rechten om bestellingen te annuleren.');
  redirect('index.php?route=orders_list');
  exit;
}

try { $pdo = db(); }
catch (Throwable $e) {
  flash_set('danger', 'DB niet beschikbaar: ' . $e->getMessage());
  redirect('index.php?route=orders_list');
  exit;
}

// Helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

/** Geef alle user-ids in de “boom” van $rootId (incl. root), via users.parent_user_id */
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId];
  $queue = [$rootId];
  $seen = [$rootId => true];
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

// Alleen POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  flash_set('warning', 'Ongeldige aanroep.');
  redirect('index.php?route=orders_list');
  exit;
}

// CSRF
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
  flash_set('danger', 'Sessie verlopen. Probeer opnieuw.');
  redirect('index.php?route=orders_list');
  exit;
}

// Input
$orderId = (int)($_POST['id'] ?? 0);
if ($orderId <= 0) {
  flash_set('danger', 'Ongeldige bestelling.');
  redirect('index.php?route=orders_list');
  exit;
}

// Order ophalen
try {
  $st = $pdo->prepare("
    SELECT o.id, o.customer_id, o.status
    FROM orders o
    WHERE o.id = ?
    LIMIT 1
  ");
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    flash_set('danger', 'Bestelling niet gevonden.');
    redirect('index.php?route=orders_list');
    exit;
  }
} catch (Throwable $e) {
  flash_set('danger', 'Laden mislukt: ' . $e->getMessage());
  redirect('index.php?route=orders_list');
  exit;
}

// Scope-check (super mag altijd; reseller/subreseller alleen als klant in boom zit)
if (!$isSuper) {
  $scopeIds = build_tree_ids($pdo, $myId);
  if (!in_array((int)$order['customer_id'], $scopeIds, true)) {
    flash_set('danger', 'Geen toegang om deze bestelling te annuleren.');
    redirect('index.php?route=orders_list');
    exit;
  }
}

// Status-check: alleen concept of awaiting_activation mag geannuleerd
$current = (string)$order['status'];
if ($current === 'cancelled') {
  flash_set('info', 'Deze bestelling is al geannuleerd.');
  redirect('index.php?route=orders_list');
  exit;
}
if ($current === 'completed') {
  flash_set('warning', 'Voltooide bestellingen kunnen niet worden geannuleerd.');
  redirect('index.php?route=orders_list');
  exit;
}
if (!in_array($current, ['concept','awaiting_activation'], true)) {
  // Onbekende status: fail safe
  flash_set('warning', 'Deze bestelling kan niet worden geannuleerd (status: ' . e($current) . ').');
  redirect('index.php?route=orders_list');
  exit;
}

// Uitvoeren: status -> cancelled
try {
  if (column_exists($pdo, 'orders', 'updated_at')) {
    $st = $pdo->prepare("UPDATE orders SET status='cancelled', updated_at = NOW() WHERE id = ? LIMIT 1");
  } else {
    $st = $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id = ? LIMIT 1");
  }
  $st->execute([$orderId]);

  flash_set('success', 'Bestelling geannuleerd.');
  redirect('index.php?route=orders_list');
  exit;
} catch (Throwable $e) {
  flash_set('danger', 'Annuleren mislukt: ' . $e->getMessage());
  redirect('index.php?route=orders_list');
  exit;
}
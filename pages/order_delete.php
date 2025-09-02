<?php
// pages/order_delete.php — veilige delete met rol- & scope-checks
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
  redirect('index.php?route=orders_list');
}

// CSRF
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
  flash_set('danger','Sessie verlopen. Probeer opnieuw.');
  redirect('index.php?route=orders_list');
}

$orderId = (int)($_POST['id'] ?? 0);
if ($orderId <= 0) {
  flash_set('warning','Geen geldige order opgegeven.');
  redirect('index.php?route=orders_list');
}

// DB
try { $pdo = db(); }
catch (Throwable $e) {
  flash_set('danger','DB niet beschikbaar: '.$e->getMessage());
  redirect('index.php?route=orders_list');
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

// Kolommen detecteren (schema-agnostisch)
$hasResellerCol   = column_exists($pdo,'orders','reseller_id') || column_exists($pdo,'orders','reseller_user_id');
$resellerCol      = column_exists($pdo,'orders','reseller_id') ? 'reseller_id' :
                    (column_exists($pdo,'orders','reseller_user_id') ? 'reseller_user_id' : null);

$hasOrderedByCol  = column_exists($pdo,'orders','ordered_by_user_id') || column_exists($pdo,'orders','created_by_user_id');
$orderedByCol     = column_exists($pdo,'orders','ordered_by_user_id') ? 'ordered_by_user_id' :
                    (column_exists($pdo,'orders','created_by_user_id') ? 'created_by_user_id' : null);

$hasCustomerCol   = column_exists($pdo,'orders','customer_id') || column_exists($pdo,'orders','customer_user_id');
$customerCol      = column_exists($pdo,'orders','customer_id') ? 'customer_id' :
                    (column_exists($pdo,'orders','customer_user_id') ? 'customer_user_id' : null);

// Haal de order op (met reseller + ordered_by + customer indien beschikbaar)
$selectCols = ['o.id'];
if ($resellerCol)  $selectCols[] = "o.`{$resellerCol}` AS reseller_id";
if ($orderedByCol) $selectCols[] = "o.`{$orderedByCol}` AS ordered_by_user_id";
if ($customerCol)  $selectCols[] = "o.`{$customerCol}` AS customer_id";

$sql = "SELECT ".implode(',', $selectCols)." FROM orders o WHERE o.id = ? LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);

if (!$order) {
  flash_set('warning','Order niet gevonden.');
  redirect('index.php?route=orders_list');
}

// Toegangscontrole
$allowed = false;

if ($isSuper) {
  $allowed = true;
} else {
  // Reseller/Sub-reseller: controleer beheer-scope
  $tree = build_tree_ids($pdo, (int)$me['id']);
  $treeInts = array_map('intval', $tree);

  // 1) Als reseller-kolom bestaat: order moet van jou of binnen jouw tree zijn
  if ($resellerCol && isset($order['reseller_id'])) {
    $resId = (int)$order['reseller_id'];
    if (in_array($resId, $treeInts, true)) $allowed = true;
  }

  // 2) Als ordered_by bestaat: je mag je eigen aangemaakte orders verwijderen
  if (!$allowed && $orderedByCol && isset($order['ordered_by_user_id'])) {
    if ((int)$order['ordered_by_user_id'] === (int)$me['id']) $allowed = true;
  }

  // 3) Als customer-kolom bestaat: klant ligt binnen jouw tree
  if (!$allowed && $customerCol && isset($order['customer_id'])) {
    if (in_array((int)$order['customer_id'], $treeInts, true)) $allowed = true;
  }
}

if (!$allowed) {
  flash_set('danger','Je hebt geen rechten om deze order te verwijderen.');
  redirect('index.php?route=orders_list');
}

// Verwijderen uitvoeren
try {
  $del = $pdo->prepare("DELETE FROM orders WHERE id = ? LIMIT 1");
  $del->execute([$orderId]);

  if ($del->rowCount() > 0) {
    flash_set('success','Order #'.$orderId.' is verwijderd.');
  } else {
    // Bij FK-fouten kan rowCount 0 blijven; geef generieke melding
    flash_set('warning','Order kon niet worden verwijderd (mogelijk al verwijderd of geblokkeerd door afhankelijkheden).');
  }
} catch (Throwable $e) {
  // FK-constraint of andere DB-fout
  flash_set('danger','Verwijderen mislukt: '.$e->getMessage());
}

// Terug naar lijst
redirect('index.php?route=orders_list');
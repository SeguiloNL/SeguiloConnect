<?php
// pages/plan_duplicate.php — Dupliceer een abonnement (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';
app_session_start();

// --- Auth check
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }
$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
  flash_set('danger', 'Alleen Super-admin mag abonnementen dupliceren.');
  redirect('index.php?route=plans_list');
}

// --- DB
try {
  $pdo = db();
} catch (Throwable $e) {
  flash_set('danger', 'DB niet beschikbaar: ' . $e->getMessage());
  redirect('index.php?route=plans_list');
}

// --- Helpers
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

// --- Input
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  flash_set('danger', 'Ongeldig abonnement.');
  redirect('index.php?route=plans_list');
}

// --- Bron plan ophalen
try {
  $st = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
  $st->execute([$id]);
  $plan = $st->fetch(PDO::FETCH_ASSOC);
  if (!$plan) {
    flash_set('danger', 'Abonnement niet gevonden.');
    redirect('index.php?route=plans_list');
  }
} catch (Throwable $e) {
  flash_set('danger', 'Laden mislukt: ' . $e->getMessage());
  redirect('index.php?route=plans_list');
}

// --- Voorstel naam
$baseName = trim((string)($plan['name'] ?? 'Abonnement'));
$newName  = $baseName . ' (kopie)';
// (optioneel) inkorten
if (mb_strlen($newName) > 190) $newName = mb_substr($newName, 0, 190);

// --- Kolommen die we willen kopiëren (check bestaan om errors te vermijden)
$allWanted = [
  'name',
  'buy_price_monthly_ex_vat',
  'sell_price_monthly_ex_vat',
  'buy_price_overage_per_mb_ex_vat',
  'sell_price_overage_per_mb_ex_vat',
  'setup_fee_ex_vat',
  'bundle_gb',
  'network_operator',
  'is_active',
];

$cols   = [];
$values = [];

foreach ($allWanted as $c) {
  if (!column_exists($pdo, 'plans', $c)) continue;
  $cols[] = $c;
  if ($c === 'name') {
    $values[] = $newName;
  } else {
    $values[] = $plan[$c] ?? null;
  }
}

// Als er geen kolommen zijn om te kopiëren, stop.
if (!$cols) {
  flash_set('danger', 'Kan niet dupliceren: geen bekende kolommen.');
  redirect('index.php?route=plans_list');
}

// --- Insert
try {
  $placeholders = implode(',', array_fill(0, count($cols), '?'));
  $colList      = implode(',', array_map(fn($c) => "`$c`", $cols));
  $sql = "INSERT INTO plans ($colList) VALUES ($placeholders)";
  $ins = $pdo->prepare($sql);
  $ins->execute($values);

  $newId = (int)$pdo->lastInsertId();
  flash_set('success', 'Abonnement gedupliceerd als “' . e($newName) . '”.');
  redirect('index.php?route=plans_list');
} catch (Throwable $e) {
  flash_set('danger', 'Dupliceren mislukt: ' . $e->getMessage());
  redirect('index.php?route=plans_list');
}
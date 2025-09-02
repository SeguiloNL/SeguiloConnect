<?php
// pages/order_add.php — nieuwe bestelling
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

// Alleen super/res/sub mogen orders aanmaken
if (!($isSuper || $isRes || $isSubRes)) {
  flash_set('danger','Je hebt geen rechten om bestellingen aan te maken.');
  redirect('index.php?route=orders_list');
}

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>'; return; }

// ------- helpers -------
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
  $ids = [$rootId];
  $queue = [$rootId];
  $seen = [$rootId=>true];
  while ($queue) {
    $chunk = array_splice($queue, 0, 100);
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

// ------- kolommen detecteren (orders) -------
$hasCreatedBy = column_exists($pdo,'orders','created_by_user_id');
$hasCustomer  = column_exists($pdo,'orders','customer_user_id');
$hasReseller  = column_exists($pdo,'orders','reseller_user_id');
$hasSimId     = column_exists($pdo,'orders','sim_id');
$hasPlanId    = column_exists($pdo,'orders','plan_id');
$hasStatus    = column_exists($pdo,'orders','status');
$hasCreatedAt = column_exists($pdo,'orders','created_at');

// ------- dropdown data laden -------

// 1) Klanten
if ($isSuper) {
  // Super-admin: ALLE eindklanten (of iedereen als 'role' ontbreekt)
  if (column_exists($pdo,'users','role')) {
    $customers = $pdo->query("SELECT id,name FROM users WHERE role='customer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $customers = $pdo->query("SELECT id,name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
  // Tree voor validatie hoeft niet; super mag altijd
  $tree = null;
} else {
  // Reseller/Sub-reseller: alleen klanten in eigen boom
  $tree = build_tree_ids($pdo, (int)$me['id']);
  $ph = implode(',', array_fill(0, count($tree), '?'));
  if (column_exists($pdo,'users','role')) {
    $st = $pdo->prepare("SELECT id,name FROM users WHERE role='customer' AND id IN ($ph) ORDER BY name");
  } else {
    $st = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($ph) ORDER BY name");
  }
  $st->execute($tree);
  $customers = $st->fetchAll(PDO::FETCH_ASSOC);
}

// 2) Beschikbare SIMs: 
//    - Super-admin: alle vrije SIMs
//    - Reseller/Sub-reseller: vrije SIMs binnen hun boom (assigned_to_user_id in boom of NULL)
$sims = [];
if ($hasSimId && table_exists($pdo,'sims')) {
  $scopeSql = '';
  $params = [];

  if (!$isSuper && column_exists($pdo,'sims','assigned_to_user_id')) {
    $ph = implode(',', array_fill(0, count($tree), '?'));
    $scopeSql = " AND (s.assigned_to_user_id IN ($ph) OR s.assigned_to_user_id IS NULL)";
    $params = $tree;
  }

  $sqlSims = "
    SELECT s.id, s.iccid, s.imsi
    FROM sims s
    LEFT JOIN (
      SELECT sim_id
      FROM orders
      WHERE sim_id IS NOT NULL
        AND (status IS NULL OR status <> 'geannuleerd')
      GROUP BY sim_id
    ) o_used ON o_used.sim_id = s.id
    WHERE o_used.sim_id IS NULL
    $scopeSql
    ORDER BY s.id DESC
  ";
  $st = $pdo->prepare($sqlSims);
  $st->execute($params);
  $sims = $st->fetchAll(PDO::FETCH_ASSOC);
}

// 3) Plannen (alleen actieve plannen als kolom is_active bestaat)
$plans = [];
if ($hasPlanId && table_exists($pdo,'plans')) {
  $where = [];
  if (column_exists($pdo,'plans','is_active')) $where[] = 'is_active = 1';
  $sqlPlans = "SELECT id, name,
                      " . (column_exists($pdo,'plans','buy_price_monthly_ex_vat') ? 'buy_price_monthly_ex_vat,' : '0 AS buy_price_monthly_ex_vat,') . "
                      " . (column_exists($pdo,'plans','sell_price_monthly_ex_vat') ? 'sell_price_monthly_ex_vat,' : '0 AS sell_price_monthly_ex_vat,') . "
                      " . (column_exists($pdo,'plans','buy_price_overage_per_mb_ex_vat') ? 'buy_price_overage_per_mb_ex_vat,' : '0 AS buy_price_overage_per_mb_ex_vat,') . "
                      " . (column_exists($pdo,'plans','sell_price_overage_per_mb_ex_vat') ? 'sell_price_overage_per_mb_ex_vat,' : '0 AS sell_price_overage_per_mb_ex_vat,') . "
                      " . (column_exists($pdo,'plans','setup_fee_ex_vat') ? 'setup_fee_ex_vat,' : '0 AS setup_fee_ex_vat,') . "
                      " . (column_exists($pdo,'plans','bundle_gb') ? 'bundle_gb,' : 'NULL AS bundle_gb,') . "
                      " . (column_exists($pdo,'plans','network_operator') ? 'network_operator' : "'' AS network_operator") . "
               FROM plans"
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY name';
  $plans = $pdo->query($sqlPlans)->fetchAll(PDO::FETCH_ASSOC);
}

// ------- POST: opslaan -------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) {
    flash_set('danger','Sessie verlopen. Probeer opnieuw.');
    redirect('index.php?route=order_add');
  }

  $customerId = (int)($_POST['customer_user_id'] ?? 0);
  $simId      = (int)($_POST['sim_id'] ?? 0);
  $planId     = (int)($_POST['plan_id'] ?? 0);

  if ($customerId <= 0 || $simId <= 0 || $planId <= 0) {
    flash_set('danger','Selecteer een eindklant, SIM en abonnement.');
    redirect('index.php?route=order_add');
  }

  // Extra validaties:
  // - klant binnen boom (skip voor super)
  if (!$isSuper) {
    if (!in_array($customerId, array_map('intval', $tree), true)) {
      flash_set('danger','De gekozen klant valt niet binnen jouw beheer.');
      redirect('index.php?route=order_add');
    }
  }

  // - sim is nog vrij (geen niet-geannuleerde order)
  $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE sim_id = ? AND (status IS NULL OR status <> 'geannuleerd')");
  $st->execute([$simId]);
  if ((int)$st->fetchColumn() > 0) {
    flash_set('danger','De gekozen SIM is al in gebruik in een andere bestelling.');
    redirect('index.php?route=order_add');
  }

  // - plan bestaat en (indien kolom) is actief
  $st = $pdo->prepare("SELECT COUNT(*) FROM plans WHERE id = ?" . (column_exists($pdo,'plans','is_active') ? " AND is_active = 1" : ""));
  $st->execute([$planId]);
  if ((int)$st->fetchColumn() === 0) {
    flash_set('danger','Ongeldig of inactief abonnement.');
    redirect('index.php?route=order_add');
  }

  // INSERT dynamisch opbouwen
  $fields = [];
  if ($hasCustomer) { $fields['customer_user_id']   = $customerId; }
  if ($hasSimId)    { $fields['sim_id']             = $simId; }
  if ($hasPlanId)   { $fields['plan_id']            = $planId; }
  if ($hasReseller) { $fields['reseller_user_id']   = (int)$me['id']; }
  if ($hasCreatedBy){ $fields['created_by_user_id'] = (int)$me['id']; }
  if ($hasStatus)   { $fields['status']             = 'Concept'; }
  if ($hasCreatedAt){ $fields['created_at']         = date('Y-m-d H:i:s'); }

  if (!$fields) {
    flash_set('danger','Orders-tabel mist vereiste kolommen (minstens sim_id/plan_id).');
    redirect('index.php?route=orders_list');
  }

  $cols = array_keys($fields);
  $ph   = array_fill(0, count($cols), '?');
  $vals = array_values($fields);

  try {
    $sql = "INSERT INTO orders (`" . implode('`,`',$cols) . "`) VALUES (" . implode(',',$ph) . ")";
    $st  = $pdo->prepare($sql);
    $st->execute($vals);
    $newId = (int)$pdo->lastInsertId();

    flash_set('success', 'Bestelling aangemaakt als Concept (#' . $newId . ').');
    redirect('index.php?route=orders_list');
  } catch (Throwable $e) {
    flash_set('danger','Opslaan mislukt: ' . $e->getMessage());
    redirect('index.php?route=order_add');
  }
}

// ------- Form tonen -------
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Nieuwe bestelling</h4>
  <a class="btn btn-secondary" href="index.php?route=orders_list">Terug</a>
</div>

<form method="post" action="index.php?route=order_add" id="orderForm">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="mb-4">
    <label class="form-label">Eindklant</label>
    <select class="form-select" name="customer_user_id" id="customerSelect" required>
      <option value="">— kies een eindklant —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> — <?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isSuper): ?>
      <div class="form-text">Je ziet hier **alle** eindklanten (reseller/sub-reseller inbegrepen).</div>
    <?php endif; ?>
  </div>

  <div class="mb-4">
    <label class="form-label">SIM kaart</label>
    <select class="form-select" name="sim_id" id="simSelect" required>
      <option value="">— kies een vrije SIM —</option>
      <?php foreach ($sims as $s): ?>
        <option value="<?= (int)$s['id'] ?>">
          #<?= (int)$s['id'] ?> — <?= e($s['iccid'] ?? '') ?><?= !empty($s['imsi']) ? ' (IMSI: '.e($s['imsi']).')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">
      <?= $isSuper ? 'Alle vrije SIMs worden getoond.' : 'Alleen vrije SIMs binnen jouw beheer worden getoond.' ?>
    </div>
  </div>

  <div class="mb-4">
    <label class="form-label d-block">Abonnement</label>
    <?php if (!$plans): ?>
      <div class="alert alert-warning">Er zijn (nog) geen actieve abonnementen beschikbaar.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($plans as $p): ?>
          <div class="col-md-6">
            <label class="w-100">
              <input class="form-check-input me-2" type="radio" name="plan_id" value="<?= (int)$p['id'] ?>">
              <div class="card">
                <div class="card-body py-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?= e($p['name']) ?></h6>
                    <small class="text-muted">ID: <?= (int)$p['id'] ?></small>
                  </div>
                  <div class="mt-2 small">
                    <div><strong>Inkoop p/m (ex):</strong> € <?= number_format((float)$p['buy_price_monthly_ex_vat'], 2, ',', '.') ?></div>
                    <div><strong>Verkoop p/m (ex):</strong> € <?= number_format((float)$p['sell_price_monthly_ex_vat'], 2, ',', '.') ?></div>
                    <div><strong>Overage inkoop (ex)/MB:</strong> € <?= number_format((float)$p['buy_price_overage_per_mb_ex_vat'], 4, ',', '.') ?></div>
                    <div><strong>Overage advies (ex)/MB:</strong> € <?= number_format((float)$p['sell_price_overage_per_mb_ex_vat'], 4, ',', '.') ?></div>
                    <div><strong>Setup (ex):</strong> € <?= number_format((float)$p['setup_fee_ex_vat'], 2, ',', '.') ?></div>
                    <div><strong>Bundel (GB):</strong> <?= e($p['bundle_gb'] ?? '-') ?></div>
                    <div><strong>Operator:</strong> <?= e($p['network_operator'] ?? '-') ?></div>
                  </div>
                </div>
              </div>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-4">
    <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Aanmaken (Concept)</button>
  </div>
</form>

<script>
// Knop pas activeren als klant, sim en plan gekozen zijn
(function(){
  const customer = document.getElementById('customerSelect');
  const sim      = document.getElementById('simSelect');
  const plans    = document.querySelectorAll('input[name="plan_id"]');
  const btn      = document.getElementById('submitBtn');

  function check() {
    const hasCustomer = customer && customer.value !== '';
    const hasSim      = sim && sim.value !== '';
    let hasPlan = false;
    plans.forEach(r => { if (r.checked) hasPlan = true; });
    btn.disabled = !(hasCustomer && hasSim && hasPlan);
  }
  if (customer) customer.addEventListener('change', check);
  if (sim) sim.addEventListener('change', check);
  plans.forEach(r => r.addEventListener('change', check));
})();
</script>
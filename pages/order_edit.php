<?php
// pages/order_edit.php — bestelling bekijken/bewerken
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>'; return; }

// ---- helpers ----
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
  if (!column_exists($pdo, 'users', 'parent_user_id')) return [$rootId];
  $ids = [$rootId]; $queue = [$rootId]; $seen = [$rootId=>true];
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

// ---- kolommen ----
$hasCreatedBy = column_exists($pdo,'orders','created_by_user_id');
$hasCustomer  = column_exists($pdo,'orders','customer_user_id');
$hasReseller  = column_exists($pdo,'orders','reseller_user_id');
$hasSimId     = column_exists($pdo,'orders','sim_id');
$hasPlanId    = column_exists($pdo,'orders','plan_id');
$hasStatus    = column_exists($pdo,'orders','status');
$hasCreatedAt = column_exists($pdo,'orders','created_at');
$hasUpdatedAt = column_exists($pdo,'orders','updated_at');

$tblSims  = table_exists($pdo,'sims');
$tblPlans = table_exists($pdo,'plans');

// ---- order laden ----
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { flash_set('danger','Geen geldige bestelling.'); redirect('index.php?route=orders_list'); }

$sel = "SELECT o.*";
$join = "";
if ($hasCustomer) $join .= " LEFT JOIN users u_customer ON u_customer.id = o.customer_user_id ";
if ($hasSimId && $tblSims && column_exists($pdo,'sims','iccid')) $sel .= ", (SELECT iccid FROM sims WHERE id=o.sim_id) AS sim_iccid";
if ($hasPlanId && $tblPlans && column_exists($pdo,'plans','name')) $sel .= ", (SELECT name FROM plans WHERE id=o.plan_id) AS plan_name";

$st = $pdo->prepare("SELECT $sel FROM orders o $join WHERE o.id=? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { flash_set('danger','Bestelling niet gevonden.'); redirect('index.php?route=orders_list'); }

// ---- scope: wie mag dit zien/bewerken ----
$inScope = false;
if ($isSuper) {
  $inScope = true; // Super-admin mag alles
} else {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  if ($hasCreatedBy && in_array((int)($order['created_by_user_id'] ?? 0), $tree, true)) $inScope = true;
  if ($hasCustomer  && in_array((int)($order['customer_user_id'] ?? 0),   $tree, true)) $inScope = true;
}
if (!$inScope) {
  flash_set('danger','Geen toegang tot deze bestelling.');
  redirect('index.php?route=orders_list');
}

// ---- mag bewerken? ----
$editable = true;
if ($hasStatus) {
  $editable = (($order['status'] ?? '') === 'Concept');
}

// ---- dropdown data laden ----
// 1) klanten
$customers = [];
if ($isSuper) {
  // super: alle eindklanten (of iedereen als kolom role ontbreekt)
  if (column_exists($pdo,'users','role')) {
    $customers = $pdo->query("SELECT id,name FROM users WHERE role='customer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $customers = $pdo->query("SELECT id,name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
} else {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  $ph = implode(',', array_fill(0, count($tree), '?'));
  if (column_exists($pdo,'users','role')) {
    $st = $pdo->prepare("SELECT id,name FROM users WHERE role='customer' AND id IN ($ph) ORDER BY name");
  } else {
    $st = $pdo->prepare("SELECT id,name FROM users WHERE id IN ($ph) ORDER BY name");
  }
  $st->execute($tree);
  $customers = $st->fetchAll(PDO::FETCH_ASSOC);
  // zorg dat de huidige klant er altijd in staat
  if ($hasCustomer && ($order['customer_user_id'] ?? null) && !in_array((int)$order['customer_user_id'], array_map('intval', array_column($customers,'id')), true)) {
    $st = $pdo->prepare("SELECT id,name FROM users WHERE id=?");
    $st->execute([(int)$order['customer_user_id']]);
    if ($u = $st->fetch(PDO::FETCH_ASSOC)) array_unshift($customers, $u);
  }
}

// 2) SIMs: vrij + huidige
$sims = [];
if ($tblSims && $hasSimId) {
  $params = [];
  $scopeJoin = '';
  if (column_exists($pdo,'sims','assigned_to_user_id') && !$isSuper) {
    $tree = build_tree_ids($pdo, (int)$me['id']);
    $ph = implode(',', array_fill(0, count($tree), '?'));
    $scopeJoin = " AND (s.assigned_to_user_id IN ($ph) OR s.assigned_to_user_id IS NULL)";
    $params = $tree;
  }

  // SIMs die NIET in andere (niet-geannuleerde) orders zitten, plus de huidige sim
  $sql = "
    SELECT s.id, s.iccid, s.imsi
    FROM sims s
    LEFT JOIN (
      SELECT sim_id FROM orders
      WHERE sim_id IS NOT NULL
        AND id <> ?
        AND (status IS NULL OR status <> 'geannuleerd')
      GROUP BY sim_id
    ) o_used ON o_used.sim_id = s.id
    WHERE (o_used.sim_id IS NULL OR s.id = ?)
    $scopeJoin
    ORDER BY s.id DESC
  ";
  array_unshift($params, $orderId, $orderId);
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $sims = $st->fetchAll(PDO::FETCH_ASSOC);
}

// 3) Plannen: actieve plannen + het huidige plan (ook als dat inactief is)
$plans = [];
if ($tblPlans && $hasPlanId) {
  $where = [];
  $params = [];
  if (column_exists($pdo,'plans','is_active')) {
    $where[] = 'is_active = 1';
  }
  $sql = "SELECT id, name,
                " . (column_exists($pdo,'plans','buy_price_monthly_ex_vat') ? 'buy_price_monthly_ex_vat,' : '0 AS buy_price_monthly_ex_vat,') . "
                " . (column_exists($pdo,'plans','sell_price_monthly_ex_vat') ? 'sell_price_monthly_ex_vat,' : '0 AS sell_price_monthly_ex_vat,') . "
                " . (column_exists($pdo,'plans','buy_price_overage_per_mb_ex_vat') ? 'buy_price_overage_per_mb_ex_vat,' : '0 AS buy_price_overage_per_mb_ex_vat,') . "
                " . (column_exists($pdo,'plans','sell_price_overage_per_mb_ex_vat') ? 'sell_price_overage_per_mb_ex_vat,' : '0 AS sell_price_overage_per_mb_ex_vat,') . "
                " . (column_exists($pdo,'plans','setup_fee_ex_vat') ? 'setup_fee_ex_vat,' : '0 AS setup_fee_ex_vat,') . "
                " . (column_exists($pdo,'plans','bundle_gb') ? 'bundle_gb,' : 'NULL AS bundle_gb,') . "
                " . (column_exists($pdo,'plans','network_operator') ? 'network_operator' : "'' AS network_operator") . "
         FROM plans " . ($where ? ('WHERE '.implode(' AND ', $where)) : '') . " ORDER BY name";
  $plans = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  // Zorg dat het huidige plan altijd zichtbaar/te selecteren is
  if (($order['plan_id'] ?? null) && !in_array((int)$order['plan_id'], array_map('intval', array_column($plans,'id')), true)) {
    $st = $pdo->prepare("SELECT id, name,
                " . (column_exists($pdo,'plans','buy_price_monthly_ex_vat') ? 'buy_price_monthly_ex_vat,' : '0 AS buy_price_monthly_ex_vat,') . "
                " . (column_exists($pdo,'plans','sell_price_monthly_ex_vat') ? 'sell_price_monthly_ex_vat,' : '0 AS sell_price_monthly_ex_vat,') . "
                " . (column_exists($pdo,'plans','buy_price_overage_per_mb_ex_vat') ? 'buy_price_overage_per_mb_ex_vat,' : '0 AS buy_price_overage_per_mb_ex_vat,') . "
                " . (column_exists($pdo,'plans','sell_price_overage_per_mb_ex_vat') ? 'sell_price_overage_per_mb_ex_vat,' : '0 AS sell_price_overage_per_mb_ex_vat,') . "
                " . (column_exists($pdo,'plans','setup_fee_ex_vat') ? 'setup_fee_ex_vat,' : '0 AS setup_fee_ex_vat,') . "
                " . (column_exists($pdo,'plans','bundle_gb') ? 'bundle_gb,' : 'NULL AS bundle_gb,') . "
                " . (column_exists($pdo,'plans','network_operator') ? 'network_operator' : "'' AS network_operator") . "
         FROM plans WHERE id=? LIMIT 1");
    $st->execute([(int)$order['plan_id']]);
    if ($p = $st->fetch(PDO::FETCH_ASSOC)) array_unshift($plans, $p);
  }
}

// ---- POST: opslaan ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $editable) {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { flash_set('danger','Sessie verlopen. Probeer opnieuw.'); redirect('index.php?route=order_edit&id='.$orderId); }

  $customerId = (int)($_POST['customer_user_id'] ?? ($order['customer_user_id'] ?? 0));
  $simId      = (int)($_POST['sim_id'] ?? ($order['sim_id'] ?? 0));
  $planId     = (int)($_POST['plan_id'] ?? ($order['plan_id'] ?? 0));

  if ($customerId <= 0 || $simId <= 0 || $planId <= 0) {
    flash_set('danger','Selecteer een eindklant, SIM en abonnement.');
    redirect('index.php?route=order_edit&id='.$orderId);
  }

  // scope check (niet nodig voor super, wel voor res/sub)
  if (!$isSuper) {
    $tree = build_tree_ids($pdo, (int)$me['id']);
    if (!in_array($customerId, $tree, true)) {
      flash_set('danger','De gekozen klant valt niet binnen jouw beheer.');
      redirect('index.php?route=order_edit&id='.$orderId);
    }
  }

  // sim vrij (behalve deze order)
  $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE sim_id = ? AND id <> ? AND (status IS NULL OR status <> 'geannuleerd')");
  $st->execute([$simId, $orderId]);
  if ((int)$st->fetchColumn() > 0) {
    flash_set('danger','De gekozen SIM is al in gebruik in een andere bestelling.');
    redirect('index.php?route=order_edit&id='.$orderId);
  }

  // plan bestaat (en indien kolom is_active = 1), behalve als het hetzelfde plan is
  if ($planId !== (int)($order['plan_id'] ?? 0)) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM plans WHERE id = ?" . (column_exists($pdo,'plans','is_active') ? " AND is_active = 1" : ""));
    $st->execute([$planId]);
    if ((int)$st->fetchColumn() === 0) {
      flash_set('danger','Ongeldig of inactief abonnement.');
      redirect('index.php?route=order_edit&id='.$orderId);
    }
  }

  // update
  $fields = [];
  $vals   = [];

  if ($hasCustomer) { $fields[] = 'customer_user_id = ?'; $vals[] = $customerId; }
  if ($hasSimId)    { $fields[] = 'sim_id = ?';           $vals[] = $simId; }
  if ($hasPlanId)   { $fields[] = 'plan_id = ?';          $vals[] = $planId; }
  if ($hasUpdatedAt){ $fields[] = 'updated_at = ?';       $vals[] = date('Y-m-d H:i:s'); }

  if ($fields) {
    $vals[] = $orderId;
    try {
      $sql = "UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?";
      $st  = $pdo->prepare($sql);
      $st->execute($vals);
      flash_set('success','Bestelling opgeslagen.');
      redirect('index.php?route=orders_list');
    } catch (Throwable $e) {
      flash_set('danger','Opslaan mislukt: ' . $e->getMessage());
      redirect('index.php?route=order_edit&id='.$orderId);
    }
  } else {
    flash_set('warning','Niets te wijzigen.');
    redirect('index.php?route=order_edit&id='.$orderId);
  }
}

// ---- UI ----
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Bestelling #<?= (int)$orderId ?> <?= $editable ? '' : '<span class="badge bg-secondary ms-2">niet-bewerkbaar</span>' ?></h4>
  <a class="btn btn-secondary" href="index.php?route=orders_list">Terug</a>
</div>

<?php if (!$editable): ?>
  <div class="alert alert-info">Deze bestelling heeft status <strong><?= e($order['status'] ?? '-') ?></strong> en kan niet meer worden bewerkt.</div>
<?php endif; ?>

<form method="post" action="index.php?route=order_edit&id=<?= (int)$orderId ?>">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="mb-4">
    <label class="form-label">Eindklant</label>
    <select class="form-select" name="customer_user_id" <?= $editable ? '' : 'disabled' ?>>
      <?php foreach ($customers as $c): $sel = ((int)$c['id'] === (int)($order['customer_user_id'] ?? 0)) ? 'selected' : ''; ?>
        <option value="<?= (int)$c['id'] ?>" <?= $sel ?>>#<?= (int)$c['id'] ?> — <?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="mb-4">
    <label class="form-label">SIM kaart</label>
    <select class="form-select" name="sim_id" <?= $editable ? '' : 'disabled' ?>>
      <?php if ($sims): foreach ($sims as $s): ?>
        <?php $sel = ((int)$s['id'] === (int)($order['sim_id'] ?? 0)) ? 'selected' : ''; ?>
        <option value="<?= (int)$s['id'] ?>" <?= $sel ?>>
          #<?= (int)$s['id'] ?> — <?= e($s['iccid'] ?? '') ?><?= !empty($s['imsi']) ? ' (IMSI: '.e($s['imsi']).')' : '' ?>
        </option>
      <?php endforeach; else: ?>
        <option value="">— geen beschikbare SIMs —</option>
      <?php endif; ?>
    </select>
  </div>

  <div class="mb-4">
    <label class="form-label d-block">Abonnement</label>
    <?php if (!$plans): ?>
      <div class="alert alert-warning">Er zijn (nog) geen plannen beschikbaar.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($plans as $p): ?>
          <?php $checked = ((int)$p['id'] === (int)($order['plan_id'] ?? 0)) ? 'checked' : ''; ?>
          <div class="col-md-6">
            <label class="w-100">
              <input class="form-check-input me-2" type="radio" name="plan_id" value="<?= (int)$p['id'] ?>" <?= $checked ?> <?= $editable ? '' : 'disabled' ?>>
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

  <div class="mt-4 d-flex gap-2">
    <?php if ($editable): ?>
      <button type="submit" class="btn btn-primary">Opslaan</button>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="index.php?route=orders_list">Annuleren</a>
  </div>
</form>
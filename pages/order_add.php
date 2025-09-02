<?php
// pages/order_add.php — nieuwe bestelling (schema-agnostisch)
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

if (!($isSuper || $isRes || $isSubRes)) {
  flash_set('danger','Je hebt geen rechten om bestellingen aan te maken.');
  redirect('index.php?route=orders_list');
}

// --- DB ---
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">PDO connectie niet beschikbaar.</div>'; return; }

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
function first_existing_col(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) if (column_exists($pdo,$table,$c)) return $c;
  return null;
}
function build_tree_ids(PDO $pdo, int $rootId): array {
  if (!column_exists($pdo,'users','parent_user_id')) return [$rootId];
  $ids = [$rootId];
  $queue = [$rootId];
  $seen = [$rootId=>true];
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
/** Vind dichtstbijzijnde reseller/sub-reseller boven een user via parent_user_id-keten */
function find_reseller_for_customer(PDO $pdo, int $userId): ?int {
  if (!column_exists($pdo,'users','parent_user_id')) return null;
  $id = $userId;
  while ($id) {
    $st = $pdo->prepare("SELECT id, role, parent_user_id FROM users WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) break;
    $role = $row['role'] ?? '';
    if (in_array($role, ['reseller','sub_reseller'], true)) return (int)$row['id'];
    $id = (int)($row['parent_user_id'] ?? 0);
  }
  return null;
}

// --- kolommen van ORDERS (schema-agnostisch) ---
$colCustomer  = first_existing_col($pdo,'orders',['customer_id','customer_user_id']);
$colReseller  = first_existing_col($pdo,'orders',['reseller_id','reseller_user_id']);
$colSim       = first_existing_col($pdo,'orders',['sim_id']);
$colPlan      = first_existing_col($pdo,'orders',['plan_id']);
$colStatus    = first_existing_col($pdo,'orders',['status']);
$colCreated   = first_existing_col($pdo,'orders',['created_at']);
$colOrderedBy = first_existing_col($pdo,'orders',['ordered_by_user_id','created_by_user_id']);

// --- dropdown data ---
// 1) Eindklanten
if ($isSuper) {
  if (column_exists($pdo,'users','role')) {
    $customers = $pdo->query("SELECT id,name FROM users WHERE role='customer' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $customers = $pdo->query("SELECT id,name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
  }
  $tree = null;
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
}

// 2) GEEN vooraf laden van SIMs (we zoeken live via ajax)
// $sims = [];

// 3) Plannen
$plans = [];
if ($colPlan && table_exists($pdo,'plans')) {
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

// --- POST: opslaan ---
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

  // Bestaat klant (FK-safe)?
  $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
  $st->execute([$customerId]);
  if ((int)$st->fetchColumn() === 0) {
    flash_set('danger','De gekozen klant bestaat niet (meer).');
    redirect('index.php?route=order_add');
  }

  // Scope check (geen beperking voor super)
  if (!$isSuper) {
    $tree = $tree ?? build_tree_ids($pdo, (int)$me['id']);
    if (!in_array($customerId, array_map('intval',$tree), true)) {
      flash_set('danger','De gekozen klant valt niet binnen jouw beheer.');
      redirect('index.php?route=order_add');
    }
  }

  // SIM vrij?
  if ($colSim) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE $colSim = ? AND (status IS NULL OR status <> 'geannuleerd')");
    $st->execute([$simId]);
    if ((int)$st->fetchColumn() > 0) {
      flash_set('danger','De gekozen SIM is al in gebruik in een andere bestelling.');
      redirect('index.php?route=order_add');
    }
  }

  // Plan geldig/actief?
  if ($colPlan) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM plans WHERE id = ?" . (column_exists($pdo,'plans','is_active') ? " AND is_active = 1" : ""));
    $st->execute([$planId]);
    if ((int)$st->fetchColumn() === 0) {
      flash_set('danger','Ongeldig of inactief abonnement.');
      redirect('index.php?route=order_add');
    }
  }

  // Reseller bepalen (indien kolom bestaat)
  $resellerId = null;
  if ($colReseller) {
    $resellerId = find_reseller_for_customer($pdo, $customerId);
    if ($resellerId === null && ($isRes || $isSubRes)) $resellerId = (int)$me['id'];
  }

  // Insert samenstellen
  $fields = []; $vals = [];
  if ($colCustomer)  { $fields[] = "`$colCustomer`";  $vals[] = $customerId; }
  if ($colSim)       { $fields[] = "`$colSim`";       $vals[] = $simId; }
  if ($colPlan)      { $fields[] = "`$colPlan`";      $vals[] = $planId; }
  if ($colReseller && $resellerId !== null) { $fields[] = "`$colReseller`"; $vals[] = $resellerId; }
  if ($colOrderedBy) { $fields[] = "`$colOrderedBy`"; $vals[] = (int)$me['id']; }
  if ($colStatus)    { $fields[] = "`$colStatus`";    $vals[] = 'Concept'; }
  if ($colCreated)   { $fields[] = "`$colCreated`";   $vals[] = date('Y-m-d H:i:s'); }

  if (!$fields) {
    flash_set('danger','Orders-tabel mist vereiste kolommen (minstens klant/sim/plan).');
    redirect('index.php?route=orders_list');
  }

  $place = implode(',', array_fill(0, count($fields), '?'));
  $sql   = "INSERT INTO orders (" . implode(',', $fields) . ") VALUES ($place)";

  try {
    $st = $pdo->prepare($sql);
    $st->execute($vals);
    $newId = (int)$pdo->lastInsertId();
    flash_set('success', 'Bestelling aangemaakt als Concept (#' . $newId . ').');
    redirect('index.php?route=orders_list');
  } catch (Throwable $e) {
    flash_set('danger', 'Opslaan mislukt: ' . $e->getMessage());
    redirect('index.php?route=order_add');
  }
}

// --- Form tonen ---
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0">Nieuwe bestelling</h4>
  <a class="btn btn-secondary" href="index.php?route=orders_list">Terug</a>
</div>

<form method="post" action="index.php?route=order_add" id="orderForm" class="needs-validation">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <!-- Eindklant (consistent stacked) -->
  <div class="mb-3">
    <label for="customerSelect" class="form-label d-block">Eindklant</label>
    <select class="form-select" name="customer_user_id" id="customerSelect" required>
      <option value="">— kies een eindklant —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> — <?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($isSuper): ?>
      <div class="form-text">Super-admin ziet hier alle eindklanten.</div>
    <?php endif; ?>
  </div>

  <!-- SIM zoeken/selecteren: strak uitgelijnd -->
  <div class="mb-3">
    <label class="form-label d-block">SIM kaart</label>
    <div class="d-block">
      <div class="row row-cols-1 row-cols-md-2 g-3 align-items-start">
        <div class="col">
          <div class="d-block">
            <label for="simSearch" class="form-label small mb-2">Zoek op cijfers (ICCID/IMSI)</label>
            <input
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              class="form-control"
              id="simSearch"
              placeholder="typ minimaal 3 cijfers…"
              aria-describedby="simHelp">
            <div id="simHelp" class="form-text">
              Typ 3+ cijfers. We tonen vrije SIM’s die overeenkomen (ICCID of IMSI).
            </div>
          </div>
        </div>

        <div class="col">
          <div class="d-block">
            <label for="simSelect" class="form-label small mb-2">Kies een vrije SIM</label>
            <select class="form-select w-100" name="sim_id" id="simSelect" required disabled>
              <option value="">— eerst zoeken —</option>
            </select>
          </div>
        </div>
      </div>

      <div id="simHint" class="small text-muted mt-2"></div>
    </div>
  </div>

  <!-- Abonnement (gelijke kaart-hoogtes) -->
  <div class="mb-3">
    <label class="form-label d-block">Abonnement</label>
    <?php if (!$plans): ?>
      <div class="alert alert-warning mb-0">Er zijn (nog) geen actieve abonnementen beschikbaar.</div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($plans as $p): ?>
          <div class="col-md-6">
            <label class="w-100">
              <input class="form-check-input me-2" type="radio" name="plan_id" value="<?= (int)$p['id'] ?>">
              <div class="card h-100">
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
// Form validatie + SIM live search met nette UX
(function(){
  const customer = document.getElementById('customerSelect');
  const sim      = document.getElementById('simSelect');
  const plans    = document.querySelectorAll('input[name="plan_id"]');
  const btn      = document.getElementById('submitBtn');
  const simSearch= document.getElementById('simSearch');
  const simHint  = document.getElementById('simHint');

  const MIN_DIGITS = 3;
  let ctrl = null;

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

  function sanitizeDigits(v){ return (v || '').replace(/\D+/g,''); }

  async function searchSims() {
    const raw = sanitizeDigits(simSearch.value);
    sim.innerHTML = '<option value="">— eerst zoeken —</option>';
    sim.disabled = true;
    simHint.textContent = '';

    if (raw.length < MIN_DIGITS) { check(); return; }

    if (ctrl) ctrl.abort();
    ctrl = new AbortController();

    simHint.textContent = 'Zoeken…';
    try {
      const url = 'index.php?route=ajax_sims_search&q=' + encodeURIComponent(raw);
      const resp = await fetch(url, { signal: ctrl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();

      sim.innerHTML = '';
      if (!Array.isArray(data) || data.length === 0) {
        sim.innerHTML = '<option value="">— geen vrije SIM’s gevonden —</option>';
        sim.disabled = true;
        simHint.textContent = 'Geen resultaten voor ' + raw + '.';
      } else {
        const frag = document.createDocumentFragment();
        const first = document.createElement('option');
        first.value = '';
        first.textContent = '— kies een vrije SIM —';
        frag.appendChild(first);
        data.forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.id;
          opt.textContent = '#' + s.id + ' — ' + (s.iccid || '') + (s.imsi ? ' (IMSI: ' + s.imsi + ')' : '');
          frag.appendChild(opt);
        });
        sim.appendChild(frag);
        sim.disabled = false;
        simHint.textContent = data.length + ' resultaat' + (data.length === 1 ? '' : 'ten') + ' gevonden.';
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      sim.innerHTML = '<option value="">— fout bij laden —</option>';
      sim.disabled = true;
      simHint.textContent = 'Kon niet laden. Probeer opnieuw.';
    } finally {
      check();
    }
  }

  simSearch.addEventListener('input', () => {
    simSearch.value = sanitizeDigits(simSearch.value);
    clearTimeout(simSearch._t);
    simSearch._t = setTimeout(searchSims, 200);
  });
})();
</script>
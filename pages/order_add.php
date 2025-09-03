<?php
// pages/order_add.php — Nieuwe order aanmaken als CONCEPT
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

if (!$isMgr) {
  flash_set('danger','Je hebt geen rechten om een bestelling aan te maken.');
  redirect('index.php?route=orders_list'); // exit
}

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ---------- helpers ----------
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}
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
function user_role_of(PDO $pdo, int $userId): ?string {
  $st = $pdo->prepare("SELECT role FROM users WHERE id=?");
  $st->execute([$userId]);
  $r = $st->fetchColumn();
  return $r !== false ? (string)$r : null;
}
function parent_of(PDO $pdo, int $userId): ?int {
  if (!column_exists($pdo,'users','parent_user_id')) return null;
  $st = $pdo->prepare("SELECT parent_user_id FROM users WHERE id=?");
  $st->execute([$userId]);
  $v = $st->fetchColumn();
  return $v !== false ? (int)$v : null;
}
/** Vind de dichtstbijzijnde (sub-)reseller boven deze klant. */
function nearest_reseller_owner(PDO $pdo, int $customerId): ?int {
  $cur = $customerId;
  for ($i=0; $i<25; $i++) {
    $p = parent_of($pdo,$cur);
    if (!$p) return null;
    $r = user_role_of($pdo,$p);
    if ($r === 'reseller' || $r === 'sub_reseller') return (int)$p;
    $cur = $p;
  }
  return null;
}

// ---------- schema detectie ----------
$ordersHasSim        = table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id');
$ordersHasPlan       = table_exists($pdo,'orders') && column_exists($pdo,'orders','plan_id');
$ordersHasStatus     = table_exists($pdo,'orders') && column_exists($pdo,'orders','status');
$ordersHasCreatedAt  = table_exists($pdo,'orders') && column_exists($pdo,'orders','created_at');

$ordersHasCustomer   = table_exists($pdo,'orders') && (column_exists($pdo,'orders','customer_id') || column_exists($pdo,'orders','customer_user_id'));
$ordersCustomerCol   = column_exists($pdo,'orders','customer_id') ? 'customer_id' : (column_exists($pdo,'orders','customer_user_id') ? 'customer_user_id' : null);

$ordersHasReseller   = table_exists($pdo,'orders') && (column_exists($pdo,'orders','reseller_id') || column_exists($pdo,'orders','reseller_user_id'));
$ordersResellerCol   = column_exists($pdo,'orders','reseller_id') ? 'reseller_id' : (column_exists($pdo,'orders','reseller_user_id') ? 'reseller_user_id' : null);

$ordersHasOrderedBy  = table_exists($pdo,'orders') && column_exists($pdo,'orders','ordered_by_user_id');
$ordersHasCreatedBy  = table_exists($pdo,'orders') && column_exists($pdo,'orders','created_by_user_id');

$simsHasAssigned     = table_exists($pdo,'sims')   && column_exists($pdo,'sims','assigned_to_user_id');

$plansHasActive      = table_exists($pdo,'plans')  && column_exists($pdo,'plans','is_active');

// ---------- klanten binnen scope ----------
try {
  if ($isSuper) {
    $st = $pdo->prepare("SELECT id,name,email FROM users WHERE role='customer' ORDER BY name");
    $st->execute();
  } else {
    $tree = build_tree_ids($pdo, (int)$me['id']);
    if (!$tree) $tree = [(int)$me['id']];
    $ph = implode(',', array_fill(0, count($tree), '?'));
    $st = $pdo->prepare("SELECT id,name,email FROM users WHERE id IN ($ph) AND role='customer' ORDER BY name");
    $st->execute($tree);
  }
  $customers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Eindklanten laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// ---------- keuze klant + owner bepalen ----------
$chosenCustomerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$ownerUserId = $chosenCustomerId ? nearest_reseller_owner($pdo, $chosenCustomerId) : null;

// ---------- beschikbare SIMs uit voorraad owner (niet in order) ----------
$availableSims = [];
if ($ownerUserId !== null && $simsHasAssigned) {
  try {
    $joinOrders  = $ordersHasSim ? "LEFT JOIN orders o ON o.sim_id = s.id" : "";
    $andNoOrders = $ordersHasSim ? "AND o.id IS NULL" : "";

    $sql = "SELECT s.id, s.iccid, s.imsi, s.status
            FROM sims s
            $joinOrders
            WHERE s.assigned_to_user_id = ?
              $andNoOrders
            ORDER BY s.id DESC
            LIMIT 1000";
    $st = $pdo->prepare($sql);
    $st->execute([$ownerUserId]);
    $availableSims = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    echo '<div class="alert alert-danger">Beschikbare SIMs laden mislukt: '.e($e->getMessage()).'</div>'; return;
  }
}

// ---------- plannen ----------
$plans = [];
try {
  if ($plansHasActive) {
    $st = $pdo->prepare("SELECT id,name,description,buy_price_monthly_ex_vat,sell_price_monthly_ex_vat,bundle_gb,network_operator,is_active FROM plans WHERE is_active=1 ORDER BY name");
  } else {
    $st = $pdo->prepare("SELECT id,name,description,buy_price_monthly_ex_vat,sell_price_monthly_ex_vat,bundle_gb,network_operator FROM plans ORDER BY name");
  }
  $st->execute();
  $plans = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Abonnementen laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// ---------- POST: opslaan ----------
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['do_create'])) {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { $errors[] = 'Sessie verlopen. Probeer opnieuw.'; }

  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $sim_id      = (int)($_POST['sim_id'] ?? 0);
  $plan_id     = (int)($_POST['plan_id'] ?? 0);

  if ($customer_id <= 0) $errors[] = 'Kies een eindklant.';
  if ($sim_id <= 0)      $errors[] = 'Kies een SIM-kaart.';
  if ($plan_id <= 0)     $errors[] = 'Kies een abonnement.';

  // scope klant
  if (!$isSuper && $customer_id > 0) {
    $tree = build_tree_ids($pdo, (int)$me['id']);
    if (!in_array($customer_id, array_map('intval',$tree), true)) {
      $errors[] = 'Gekozen eindklant valt niet binnen jouw beheer.';
    }
  }

  // owner opnieuw bepalen + SIM validatie
  $ownerUserId = $customer_id ? nearest_reseller_owner($pdo, $customer_id) : null;
  if ($simsHasAssigned && $ownerUserId !== null && $sim_id > 0) {
    try {
      $sql = "SELECT s.id
              FROM sims s
              ".($ordersHasSim ? "LEFT JOIN orders o ON o.sim_id = s.id" : "")."
              WHERE s.id = ?
                AND s.assigned_to_user_id = ?
                ".($ordersHasSim ? "AND o.id IS NULL" : "");
      $st = $pdo->prepare($sql);
      $st->execute([$sim_id, $ownerUserId]);
      if (!$st->fetchColumn()) $errors[] = 'Gekozen SIM staat niet in de voorraad van de bovenliggende (sub-)reseller of is al in gebruik.';
    } catch (Throwable $e) {
      $errors[] = 'SIM-validatie mislukt: '.$e->getMessage();
    }
  }

  // plan actief?
  if ($plansHasActive && $plan_id > 0) {
    $st = $pdo->prepare("SELECT 1 FROM plans WHERE id=? AND is_active=1");
    $st->execute([$plan_id]);
    if (!$st->fetchColumn()) $errors[] = 'Gekozen abonnement is niet (meer) actief.';
  }

  // INSERT concept
  if (!$errors) {
    try {
      $cols = [];
      $vals = [];

      if ($ordersHasCustomer && $ordersCustomerCol) { $cols[]=$ordersCustomerCol; $vals[]=$customer_id; }
      if ($ordersHasSim)    { $cols[]='sim_id';      $vals[]=$sim_id; }
      if ($ordersHasPlan)   { $cols[]='plan_id';     $vals[]=$plan_id; }
      if ($ordersHasStatus) { $cols[]='status';      $vals[]='concept'; }

      if ($ordersHasReseller && $ordersResellerCol) {
        // sla eigenaar (bovenliggende reseller/sub) op
        $cols[] = $ordersResellerCol; 
        $vals[] = $ownerUserId;
      }
      if ($ordersHasOrderedBy) { $cols[]='ordered_by_user_id'; $vals[]=(int)$me['id']; }
      if ($ordersHasCreatedBy) { $cols[]='created_by_user_id'; $vals[]=(int)$me['id']; }
      if ($ordersHasCreatedAt) { $cols[]='created_at';         $vals[]=date('Y-m-d H:i:s'); }

      if (!$cols) throw new RuntimeException('Orders-tabel mist vereiste kolommen (customer_id/sim_id/plan_id/status).');

      $ph  = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO orders (".implode(',',$cols).") VALUES ({$ph})";
      $st  = $pdo->prepare($sql);
      $st->execute($vals);

      $newId = (int)$pdo->lastInsertId();
      flash_set('success','Bestelling aangemaakt als concept.');
      redirect('index.php?route=order_edit&id='.$newId);
    } catch (Throwable $e) {
      $errors[] = 'Opslaan mislukt: '.$e->getMessage();
    }
  }
}

// ---------- weergave helpers ----------
function selected($a,$b){ return ((string)$a === (string)$b) ? 'selected' : ''; }

?>
<h4>Nieuwe bestelling (Concept)</h4>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?route=order_add" class="row g-3" id="orderAddForm">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <!-- Eindklant -->
  <div class="col-12 col-md-6">
    <label class="form-label">Eindklant</label>
    <select name="customer_id" id="customer_id" class="form-select" required>
      <option value="">— kies eindklant —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= selected($c['id'],$chosenCustomerId) ?>>
          #<?= (int)$c['id'] ?> — <?= e($c['name']) ?><?php if (!empty($c['email'])): ?> (<?= e($c['email']) ?>)<?php endif; ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Kies eerst de eindklant; de SIM-voorraad wordt daarop gefilterd.</div>
  </div>

  <!-- SIM zoeken + selecteren -->
  <div class="col-12 col-md-6">
    <label class="form-label d-flex justify-content-between align-items-center">
      <span>SIM-kaart (voorraad van bovenliggende (sub-)reseller)</span>
      <small class="text-muted" id="simCountLabel">
        <?= $ownerUserId !== null ? count($availableSims).' beschikbaar' : 'Kies eerst een eindklant' ?>
      </small>
    </label>

    <?php if ($ownerUserId === null): ?>
      <select class="form-select" disabled>
        <option>— kies eerst een eindklant —</option>
      </select>
    <?php else: ?>
      <input type="text" class="form-control mb-2" id="simSearch" placeholder="Zoek op ICCID of IMSI…">
      <select name="sim_id" id="sim_id" class="form-select" required size="6">
        <?php foreach ($availableSims as $s): ?>
          <?php
            $label = 'ID '.$s['id'].' — ICCID '.($s['iccid'] ?? '—');
            if (!empty($s['imsi'])) $label .= ' — IMSI '.$s['imsi'];
          ?>
          <option value="<?= (int)$s['id'] ?>" data-search="<?= e(($s['iccid'] ?? '').' '.($s['imsi'] ?? '')) ?>">
            <?= e($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Alleen SIMs die niet in een order zitten.</div>
    <?php endif; ?>
  </div>

  <!-- Abonnement -->
  <div class="col-12">
    <label class="form-label">Abonnement</label>
    <div class="row gy-3">
      <?php if (!$plans): ?>
        <div class="col-12"><div class="alert alert-warning mb-0">Geen (actieve) abonnementen gevonden.</div></div>
      <?php else: ?>
        <?php foreach ($plans as $p): ?>
          <div class="col-12 col-md-6 col-lg-4">
            <div class="card h-100">
              <div class="card-body">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="plan_id" id="plan_<?= (int)$p['id'] ?>" value="<?= (int)$p['id'] ?>" required>
                  <label class="form-check-label fw-semibold" for="plan_<?= (int)$p['id'] ?>">
                    <?= e($p['name'] ?? 'Abonnement #'.(int)$p['id']) ?>
                  </label>
                </div>
                <?php if (!empty($p['description'])): ?>
                  <div class="text-muted small mb-2"><?= nl2br(e($p['description'])) ?></div>
                <?php endif; ?>
                <ul class="list-unstyled small mb-0">
                  <?php if (isset($p['sell_price_monthly_ex_vat'])): ?>
                    <li>Verkoop (p/m ex): € <?= number_format((float)$p['sell_price_monthly_ex_vat'], 2, ',', '.') ?></li>
                  <?php endif; ?>
                  <?php if (isset($p['buy_price_monthly_ex_vat'])): ?>
                    <li>Inkoop (p/m ex): € <?= number_format((float)$p['buy_price_monthly_ex_vat'], 2, ',', '.') ?></li>
                  <?php endif; ?>
                  <?php if (isset($p['bundle_gb'])): ?>
                    <li>Bundel: <?= e((string)$p['bundle_gb']) ?> GB</li>
                  <?php endif; ?>
                  <?php if (!empty($p['network_operator'])): ?>
                    <li>Netwerk: <?= e($p['network_operator']) ?></li>
                  <?php endif; ?>
                </ul>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary" name="do_create" value="1" <?= ($ownerUserId===null?'disabled':'') ?>>
      Aanmaken (Concept)
    </button>
    <a href="index.php?route=orders_list" class="btn btn-outline-secondary">Annuleren</a>
  </div>

  <?php if ($ownerUserId === null): ?>
    <div class="col-12">
      <div class="alert alert-info mt-2">Selecteer eerst een eindklant om de beschikbare SIMs te laden.</div>
    </div>
  <?php endif; ?>
</form>

<script>
  // Bij verandering klant: herlaad zodat SIM-voorraad wordt gefilterd
  (function(){
    const select = document.getElementById('customer_id');
    if (!select) return;
    select.addEventListener('change', function(){
      const url = new URL(window.location.href);
      url.searchParams.set('route','order_add');
      if (this.value) url.searchParams.set('customer_id', this.value);
      else url.searchParams.delete('customer_id');
      window.location.href = url.toString();
    });
  })();

  // Client-side filter op SIM-select
  (function(){
    const q = document.getElementById('simSearch');
    const list = document.getElementById('sim_id');
    if (!q || !list) return;
    q.addEventListener('input', function(){
      const needle = this.value.toLowerCase();
      let visible = 0;
      for (const opt of list.options) {
        const hay = (opt.textContent + ' ' + (opt.getAttribute('data-search')||'')).toLowerCase();
        const show = hay.includes(needle);
        opt.hidden = !show;
        if (show) visible++;
      }
      const lbl = document.getElementById('simCountLabel');
      if (lbl) lbl.textContent = visible + ' beschikbaar';
    });
  })();
</script>
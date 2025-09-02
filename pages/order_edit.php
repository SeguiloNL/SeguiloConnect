<?php
// pages/order_edit.php — veilige order weergave/bewerken zonder SQL-fouten
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);

$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) {
  echo '<div class="alert alert-warning">Geen geldige order opgegeven.</div>';
  return;
}

// ---------- DB ----------
try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ---------- helpers ----------
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

$usersHasAddresses   = column_exists($pdo,'users','admin_contact') || column_exists($pdo,'users','connect_contact');

$simsTable           = table_exists($pdo,'sims');
$plansTable          = table_exists($pdo,'plans');

// ---------- order ophalen (met LEFT JOINs) ----------
$select = ["o.id"];
if ($ordersHasStatus)    $select[] = "o.status";
if ($ordersHasCreatedAt) $select[] = "o.created_at";
if ($ordersHasSim)       $select[] = "o.sim_id";
if ($ordersHasPlan)      $select[] = "o.plan_id";
if ($ordersCustomerCol)  $select[] = "o.`{$ordersCustomerCol}` AS customer_id";
if ($ordersResellerCol)  $select[] = "o.`{$ordersResellerCol}` AS reseller_id";
if ($ordersHasOrderedBy) $select[] = "o.ordered_by_user_id";
if ($ordersHasCreatedBy) $select[] = "o.created_by_user_id";

// join aliases
$joins = [];
if ($ordersHasSim && $simsTable) {
  $select[] = "s.iccid  AS sim_iccid";
  $select[] = "s.imsi   AS sim_imsi";
  $select[] = "s.status AS sim_status";
  $joins[]  = "LEFT JOIN sims s ON s.id = o.sim_id";
}
if ($ordersHasPlan && $plansTable) {
  $select[] = "p.name               AS plan_name";
  $select[] = "p.description        AS plan_description";
  if (column_exists($pdo,'plans','sell_price_monthly_ex_vat')) $select[] = "p.sell_price_monthly_ex_vat AS plan_sell_pm";
  if (column_exists($pdo,'plans','buy_price_monthly_ex_vat'))  $select[] = "p.buy_price_monthly_ex_vat  AS plan_buy_pm";
  if (column_exists($pdo,'plans','bundle_gb'))                 $select[] = "p.bundle_gb                 AS plan_bundle_gb";
  if (column_exists($pdo,'plans','network_operator'))          $select[] = "p.network_operator          AS plan_operator";
  $joins[]  = "LEFT JOIN plans p ON p.id = o.plan_id";
}
if ($ordersCustomerCol) {
  $select[] = "cu.name  AS customer_name";
  $select[] = "cu.email AS customer_email";
  // adresvelden (optioneel)
  foreach (['admin_contact','admin_address','admin_postcode','admin_city','connect_contact','connect_address','connect_postcode','connect_city'] as $col) {
    if (column_exists($pdo,'users',$col)) $select[] = "cu.`$col` AS cu_$col";
  }
  $joins[]  = "LEFT JOIN users cu ON cu.id = o.`{$ordersCustomerCol}`";
}
if ($ordersResellerCol) {
  $select[] = "re.name  AS reseller_name";
  $joins[]  = "LEFT JOIN users re ON re.id = o.`{$ordersResellerCol}`";
}
if ($ordersHasOrderedBy) {
  $select[] = "ob.name  AS ordered_by_name";
  $joins[]  = "LEFT JOIN users ob ON ob.id = o.ordered_by_user_id";
}
if ($ordersHasCreatedBy) {
  $select[] = "cb.name  AS created_by_name";
  $joins[]  = "LEFT JOIN users cb ON cb.id = o.created_by_user_id";
}

$sql = "SELECT ".implode(",\n       ", $select)."
        FROM orders o
        ".implode("\n        ", $joins)."
        WHERE o.id = ?
        LIMIT 1";

try {
  $st = $pdo->prepare($sql);
  $st->execute([$orderId]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
  // debug hint: echo '<pre>'.e($sql).'</pre>';
  return;
}

if (!$order) {
  echo '<div class="alert alert-warning">Order niet gevonden.</div>';
  return;
}

// ---------- toegang controleren ----------
$allowed = false;
if ($isSuper) {
  $allowed = true;
} else {
  $tree = build_tree_ids($pdo, (int)$me['id']);
  $treeInts = array_map('intval',$tree);
  // binnen reseller-scope?
  if (!$allowed && $ordersResellerCol && isset($order['reseller_id'])) {
    if (in_array((int)$order['reseller_id'], $treeInts, true)) $allowed = true;
  }
  // eigen klant?
  if (!$allowed && $ordersCustomerCol && isset($order['customer_id'])) {
    if (in_array((int)$order['customer_id'], $treeInts, true)) $allowed = true;
  }
  // zelf aangemaakt?
  if (!$allowed && $ordersHasOrderedBy && isset($order['ordered_by_user_id'])) {
    if ((int)$order['ordered_by_user_id'] === (int)$me['id']) $allowed = true;
  }
  if (!$allowed && $ordersHasCreatedBy && isset($order['created_by_user_id'])) {
    if ((int)$order['created_by_user_id'] === (int)$me['id']) $allowed = true;
  }
}
if (!$allowed) {
  echo '<div class="alert alert-danger">Geen toegang tot deze bestelling.</div>';
  return;
}

// ---------- weergave ----------
$status = $order['status'] ?? '';
$isConcept = (strtolower((string)$status) === 'concept');

// Flash
echo function_exists('flash_output') ? flash_output() : '';

?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Bestelling #<?= (int)$order['id'] ?></h4>
  <div class="d-flex gap-2">
    <?php if ($isConcept && $isMgr): ?>
      <!-- hier zou je een echte bewerk-pagina/flow kunnen maken; voor nu alleen status-knoppen als je die al hebt -->
      <a href="index.php?route=orders_list" class="btn btn-outline-secondary btn-sm">Terug</a>
    <?php else: ?>
      <a href="index.php?route=orders_list" class="btn btn-outline-secondary btn-sm">Terug</a>
    <?php endif; ?>
  </div>
</div>

<div class="row gy-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Ordergegevens</h5>
        <dl class="row mb-0">
          <dt class="col-5">Order ID</dt><dd class="col-7">#<?= (int)$order['id'] ?></dd>
          <?php if ($ordersHasStatus): ?>
            <dt class="col-5">Status</dt><dd class="col-7"><?= e($order['status'] ?? '') ?></dd>
          <?php endif; ?>
          <?php if ($ordersHasCreatedAt): ?>
            <dt class="col-5">Aangemaakt op</dt><dd class="col-7"><?= e($order['created_at'] ?? '') ?></dd>
          <?php endif; ?>
          <?php if ($ordersHasReseller && isset($order['reseller_id'])): ?>
            <dt class="col-5">Reseller</dt><dd class="col-7">
              #<?= (int)$order['reseller_id'] ?> — <?= e($order['reseller_name'] ?? '') ?>
            </dd>
          <?php endif; ?>
          <?php if ($ordersHasOrderedBy): ?>
            <dt class="col-5">Besteld door</dt><dd class="col-7"><?= e($order['ordered_by_name'] ?? '') ?></dd>
          <?php endif; ?>
          <?php if ($ordersHasCreatedBy): ?>
            <dt class="col-5">Aangemaakt door</dt><dd class="col-7"><?= e($order['created_by_name'] ?? '') ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Eindklant</h5>
        <dl class="row mb-0">
          <?php if ($ordersCustomerCol && isset($order['customer_id'])): ?>
            <dt class="col-5">Klant</dt>
            <dd class="col-7">#<?= (int)$order['customer_id'] ?> — <?= e($order['customer_name'] ?? '') ?><?php if (!empty($order['customer_email'])): ?> (<?= e($order['customer_email']) ?>)<?php endif; ?></dd>
          <?php endif; ?>

          <?php if ($usersHasAddresses): ?>
            <?php
              $fields = [
                'admin_contact'  => 'Adm. contact',
                'admin_address'  => 'Adm. adres',
                'admin_postcode' => 'Adm. postcode',
                'admin_city'     => 'Adm. woonplaats',
                'connect_contact'  => 'Aansl. contact',
                'connect_address'  => 'Aansl. adres',
                'connect_postcode' => 'Aansl. postcode',
                'connect_city'     => 'Aansl. woonplaats',
              ];
              foreach ($fields as $f => $label):
                $key = 'cu_'.$f;
                if (array_key_exists($key, $order) && $order[$key] !== null && $order[$key] !== ''):
            ?>
              <dt class="col-5"><?= e($label) ?></dt><dd class="col-7"><?= nl2br(e($order[$key])) ?></dd>
            <?php endif; endforeach; ?>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">SIM-kaart</h5>
        <?php if ($ordersHasSim && !empty($order['sim_id'])): ?>
          <dl class="row mb-0">
            <dt class="col-5">SIM ID</dt><dd class="col-7">#<?= (int)$order['sim_id'] ?></dd>
            <dt class="col-5">ICCID</dt><dd class="col-7"><?= e($order['sim_iccid'] ?? '') ?></dd>
            <dt class="col-5">IMSI</dt><dd class="col-7"><?= e($order['sim_imsi'] ?? '') ?></dd>
            <dt class="col-5">Status</dt><dd class="col-7"><?= e($order['sim_status'] ?? '') ?></dd>
          </dl>
        <?php else: ?>
          <div class="text-muted">Geen SIM gekoppeld.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Abonnement</h5>
        <?php if ($ordersHasPlan && !empty($order['plan_id'])): ?>
          <dl class="row mb-0">
            <dt class="col-5">Plan</dt><dd class="col-7">#<?= (int)$order['plan_id'] ?> — <?= e($order['plan_name'] ?? '') ?></dd>
            <?php if (!empty($order['plan_description'])): ?>
              <dt class="col-5">Omschrijving</dt><dd class="col-7"><?= nl2br(e($order['plan_description'])) ?></dd>
            <?php endif; ?>
            <?php if (isset($order['plan_sell_pm'])): ?>
              <dt class="col-5">Verkoop p/m (ex)</dt><dd class="col-7">€ <?= number_format((float)$order['plan_sell_pm'], 2, ',', '.') ?></dd>
            <?php endif; ?>
            <?php if (isset($order['plan_buy_pm'])): ?>
              <dt class="col-5">Inkoop p/m (ex)</dt><dd class="col-7">€ <?= number_format((float)$order['plan_buy_pm'], 2, ',', '.') ?></dd>
            <?php endif; ?>
            <?php if (isset($order['plan_bundle_gb'])): ?>
              <dt class="col-5">Bundel</dt><dd class="col-7"><?= e((string)$order['plan_bundle_gb']) ?> GB</dd>
            <?php endif; ?>
            <?php if (!empty($order['plan_operator'])): ?>
              <dt class="col-5">Netwerk</dt><dd class="col-7"><?= e($order['plan_operator']) ?></dd>
            <?php endif; ?>
          </dl>
        <?php else: ?>
          <div class="text-muted">Geen abonnement gekoppeld.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
// Eventueel: eenvoudige edit-acties bij concept (alleen voorbeeldknoppen)
// Formulieren hier kunnen posten naar aparte routes zoals order_update_status.php,
// order_update_items.php etc. Zolang je maar CSRF + scope checkt.
?>
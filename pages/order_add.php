<?php
// pages/order_add.php — maakt CONCEPT-bestelling aan met created_by_user_id
// - Toont plannen als kaarten met tarieven
// - SIM-selectie: alleen SIMs in scope (eigenaar in keten) én zonder gekoppeld abonnement
// - Knop "Aanmaken (Concept)" blijft disabled tot klant, sim en plan zijn gekozen

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$u = auth_user();
global $pdo;

// ====== Role flags ======
$role = $u['role'] ?? null;
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = $isSuper || $isRes || $isSubRes;
$isCustomer = !$isMgr;

// Alleen medewerkers (super/res/sub) mogen orders aanmaken
if ($isCustomer) {
    http_response_code(403);
    echo "<h3>Nieuwe bestelling</h3><div class='alert alert-danger'>Geen toegang.</div>";
    return;
}

// ===== Helpers (zonder placeholders in SHOW COLUMNS) =====
function column_exists(PDO $pdo, string $table, string $column): bool {
    $qcol = $pdo->quote($column);
    $sql  = "SHOW COLUMNS FROM `{$table}` LIKE {$qcol}";
    $res  = $pdo->query($sql);
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
function detect_customer_col(PDO $pdo): string {
    $candidates = ['customer_user_id','customer_id','end_customer_id','user_id'];
    foreach ($candidates as $col) {
        if (column_exists($pdo, 'orders', $col)) return $col;
    }
    // fallback: gebruik customer_id (past in UPDATE/INSERT), toon anders duidelijke fout elders
    return 'customer_id';
}
$customerCol = detect_customer_col($pdo);
$hasParent   = column_exists($pdo,'users','parent_user_id');

// ===== Scope helpers =====
function users_under(PDO $pdo, int $userId): array {
    // Geef id + eventuele afstammelingen (op basis van parent_user_id), anders alleen zichzelf
    $ids = [$userId];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
        if (!$st || !$st->fetch()) return $ids;

        $queue = [$userId];
        $seen  = [$userId=>true];
        while ($queue) {
            $chunk = array_splice($queue, 0, 100);
            $bind = [];
            foreach ($chunk as $i=>$v) $bind['p'.$i] = (int)$v;
            $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($bind)));
            $sql = "SELECT id FROM users WHERE parent_user_id IN ($ph)";
            $st2 = $pdo->prepare($sql);
            foreach ($bind as $k=>$v) $st2->bindValue(':'.$k, $v, PDO::PARAM_INT);
            $st2->execute();
            $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $cid) {
                $cid = (int)$cid;
                if (!isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $ids[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
    } catch (Throwable $e) {
        // bij fout: laat alleen zichzelf
        return [$userId];
    }
    return $ids;
}
function in_named(array $ints, string $prefix='i'): array {
    $ints = array_values(array_unique(array_map('intval',$ints)));
    if (!$ints) return ['ph'=>'0','params'=>[]];
    $params=[]; foreach ($ints as $i=>$v) $params[$prefix.$i]=$v;
    $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($params)));
    return ['ph'=>$ph,'params'=>$params];
}

// ===== Keuzelijsten laden =====
$customers = [];
$sims      = [];
$plans     = [];
$err       = '';

try {
    // Eindklanten in scope
    if ($isSuper) {
        $customers = $pdo->query("SELECT id, name FROM users WHERE role='customer' ORDER BY name")->fetchAll();
    } else {
        $scope = users_under($pdo, (int)$u['id']);
        $in    = in_named($scope,'u');
        $st = $pdo->prepare("SELECT id, name FROM users WHERE role='customer' AND id IN (".$in['ph'].") ORDER BY name");
        foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st->execute();
        $customers = $st->fetchAll();
    }

    // SIMs in scope en zonder gekoppeld abonnement:
    // Def.: geen order met deze sim_id en status in ('concept','awaiting_activation','completed')
    // (geannuleerde orders tellen niet)
    $statusBlock = "'concept','awaiting_activation','completed'";
    if ($isSuper) {
        $sqlS = "
            SELECT s.id, s.iccid
            FROM sims s
            WHERE NOT EXISTS (
               SELECT 1 FROM orders o
               WHERE o.sim_id = s.id AND o.status IN ($statusBlock)
            )
            ORDER BY s.id DESC
            LIMIT 2000
        ";
        $sims = $pdo->query($sqlS)->fetchAll();
    } else {
        $scope = users_under($pdo, (int)$u['id']);
        $in    = in_named($scope,'o');
        $sqlS = "
            SELECT s.id, s.iccid
            FROM sims s
            WHERE s.owner_user_id IN (".$in['ph'].")
              AND NOT EXISTS (
                SELECT 1 FROM orders o
                WHERE o.sim_id = s.id AND o.status IN ($statusBlock)
              )
            ORDER BY s.id DESC
            LIMIT 2000
        ";
        $st = $pdo->prepare($sqlS);
        foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st->execute();
        $sims = $st->fetchAll();
    }

    // Plannen (toon ook inactieve, maar zet actieve bovenaan)
    $plans = $pdo->query("
        SELECT id, name, description,
               buy_price_monthly_ex_vat,
               sell_price_monthly_ex_vat,
               buy_price_overage_per_mb_ex_vat,
               sell_price_overage_per_mb_ex_vat,
               setup_fee_ex_vat,
               bundle_gb,
               network_operator,
               is_active
        FROM plans
        ORDER BY is_active DESC, name
    ")->fetchAll();
} catch (Throwable $e) {
    $err = "Laden mislukt: ".$e->getMessage();
}

// ===== POST: aanmaken =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (function_exists('verify_csrf')) {
        try { verify_csrf(); } catch (Throwable $e) { $errors[] = "CSRF ongeldig, probeer opnieuw."; }
    }

    $customer_id = (int)($_POST['customer_user_id'] ?? 0);
    $sim_id      = (int)($_POST['sim_id'] ?? 0);
    $plan_id     = (int)($_POST['plan_id'] ?? 0);

    if ($customer_id <= 0) $errors[] = "Selecteer een eindklant.";
    if ($sim_id <= 0)      $errors[] = "Selecteer een SIM-kaart.";
    if ($plan_id <= 0)     $errors[] = "Selecteer een abonnement.";

    // simpele scope-checks (niet voor super)
    if (!$isSuper) {
        // klant in scope?
        $scope = users_under($pdo, (int)$u['id']);
        if (!in_array($customer_id, $scope, true)) {
            $errors[] = "Eindklant valt niet binnen jouw scope.";
        }
        // sim in scope?
        try {
            $in = in_named($scope,'s');
            $st = $pdo->prepare("SELECT id FROM sims WHERE id = :sid AND owner_user_id IN (".$in['ph'].")");
            $st->bindValue(':sid',$sim_id,PDO::PARAM_INT);
            foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
            $st->execute();
            if (!$st->fetchColumn()) $errors[] = "SIM valt niet binnen jouw scope.";
        } catch (Throwable $e) {
            $errors[] = "Validatie SIM faalde: ".$e->getMessage();
        }
    }

    // sim moet vrij zijn (geen actief/lopende order)
    try {
        $st = $pdo->prepare("SELECT 1 FROM orders WHERE sim_id = :sid AND status IN ('concept','awaiting_activation','completed') LIMIT 1");
        $st->execute([':sid'=>$sim_id]);
        if ($st->fetchColumn()) $errors[] = "Deze SIM heeft al een (lopende/actieve) bestelling.";
    } catch (Throwable $e) {
        $errors[] = "SIM-controle faalde: ".$e->getMessage();
    }

    if (empty($errors)) {
        try {
            $createdBy = (int)$u['id'];
            // INSERT met created_by_user_id en dynamische klantkolom
            $sql = "INSERT INTO orders 
                    (created_by_user_id, `$customerCol`, sim_id, plan_id, status, created_at, updated_at)
                    VALUES
                    (:created_by, :customer_id, :sim_id, :plan_id, 'concept', NOW(), NOW())";
            $st = $pdo->prepare($sql);
            $st->bindValue(':created_by',  $createdBy,   PDO::PARAM_INT);
            $st->bindValue(':customer_id', $customer_id, PDO::PARAM_INT);
            $st->bindValue(':sim_id',      $sim_id,      PDO::PARAM_INT);
            $st->bindValue(':plan_id',     $plan_id,     PDO::PARAM_INT);
            $st->execute();

            $newId = (int)$pdo->lastInsertId();
            header('Location: index.php?route=order_edit&id='.$newId.'&msg=Aangemaakt');
            exit;
        } catch (Throwable $e) {
            $err = "Aanmaken mislukt: ".$e->getMessage();
        }
    } else {
        $err = implode('<br>', array_map('e', $errors));
    }
}
?>

<h3>Nieuwe bestelling</h3>

<?php if ($err): ?>
  <div class="alert alert-danger"><?= $err ?></div>
<?php endif; ?>

<form method="post" action="index.php?route=order_add" id="orderAddForm">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Eindklant *</label>
      <select name="customer_user_id" id="customer" class="form-select" required>
        <option value="">— kies eindklant —</option>
        <?php foreach ($customers as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (ID <?= (int)$c['id'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">SIM-kaart *</label>
      <select name="sim_id" id="sim" class="form-select" required>
        <option value="">— kies SIM —</option>
        <?php foreach ($sims as $s): ?>
          <option value="<?= (int)$s['id'] ?>"><?= e($s['iccid']) ?> (ID <?= (int)$s['id'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Alleen SIMs zonder bestaand (lopend/actief) abonnement.</div>
    </div>
  </div>

  <hr>

  <h5 class="mb-3">Kies een abonnement *</h5>

  <div class="row g-3">
    <?php foreach ($plans as $p): ?>
      <div class="col-md-6 col-lg-4">
        <label class="w-100">
          <input type="radio" name="plan_id" value="<?= (int)$p['id'] ?>" class="form-check-input me-2 plan-radio">
          <div class="card h-100 <?= !empty($p['is_active']) ? 'border-success' : 'border-secondary' ?>">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="h6 mb-1"><?= e($p['name']) ?></div>
                  <?php if (!empty($p['description'])): ?>
                    <div class="small text-muted mb-2"><?= e($p['description']) ?></div>
                  <?php endif; ?>
                </div>
                <?php if (!empty($p['is_active'])): ?>
                  <span class="badge bg-success">Actief</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactief</span>
                <?php endif; ?>
              </div>

              <ul class="list-unstyled mb-2 small">
                <li><strong>Inkoop p/m (ex):</strong> € <?= number_format((float)$p['buy_price_monthly_ex_vat'],2,',','.') ?></li>
                <li><strong>Verkoop p/m (ex):</strong> € <?= number_format((float)$p['sell_price_monthly_ex_vat'],2,',','.') ?></li>
                <li><strong>Inkoop buiten bundel /MB (ex):</strong> € <?= number_format((float)$p['buy_price_overage_per_mb_ex_vat'],4,',','.') ?></li>
                <li><strong>Advies buiten bundel /MB (ex):</strong> € <?= number_format((float)$p['sell_price_overage_per_mb_ex_vat'],4,',','.') ?></li>
                <li><strong>Setup (ex):</strong> € <?= number_format((float)$p['setup_fee_ex_vat'],2,',','.') ?></li>
                <li><strong>Bundel (GB):</strong> <?= e((string)$p['bundle_gb']) ?></li>
                <li><strong>Netwerk operator:</strong> <?= e((string)$p['network_operator']) ?></li>
              </ul>
            </div>
          </div>
        </label>
      </div>
    <?php endforeach; ?>
    <?php if (!$plans): ?>
      <div class="col-12 text-muted">Geen abonnementen beschikbaar.</div>
    <?php endif; ?>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary" id="createBtn" disabled>Aanmaken (Concept)</button>
    <a class="btn btn-outline-secondary" href="index.php?route=orders_list">Annuleren</a>
  </div>
</form>

<script>
  (function(){
    const form   = document.getElementById('orderAddForm');
    const cust   = document.getElementById('customer');
    const sim    = document.getElementById('sim');
    const planRs = document.querySelectorAll('.plan-radio');
    const btn    = document.getElementById('createBtn');

    function updateBtn(){
      const hasCust = cust && cust.value !== '';
      const hasSim  = sim && sim.value !== '';
      let hasPlan   = false;
      planRs.forEach(r => { if (r.checked) hasPlan = true; });
      btn.disabled = !(hasCust && hasSim && hasPlan);
    }
    if (cust) cust.addEventListener('change', updateBtn);
    if (sim)  sim.addEventListener('change', updateBtn);
    planRs.forEach(r => r.addEventListener('change', updateBtn));
    updateBtn();
  })();
</script>
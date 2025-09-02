<?php
// pages/order_edit.php — eenvoudigere scope met created_by_user_id
// - Super: alles
// - Reseller/Sub: als created_by_user_id == mijn id
// - Eindklant: als klantkolom == mijn id
// - Bewerken alleen bij status 'concept'

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$u = auth_user();
global $pdo;

$role    = $u['role'] ?? null;
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes   = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSub   = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr   = $isSuper || $isRes || $isSub;

$id   = (int)($_GET['id'] ?? 0);
$mode = (($_GET['mode'] ?? '') === 'edit') ? 'edit' : 'view';
$errors = [];
$msg    = $_GET['msg'] ?? '';

echo '<h3>Bestelling</h3>';
if ($id <= 0) {
  echo "<div class='alert alert-danger'>Ongeldig ID.</div>";
  return;
}

// === Helpers (SHOW COLUMNS zonder placeholders) ===
function column_exists(PDO $pdo, string $table, string $column): bool {
  $q = $pdo->quote($column);
  $sql = "SHOW COLUMNS FROM `{$table}` LIKE {$q}";
  $res = $pdo->query($sql);
  return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
function detect_customer_col(PDO $pdo): string {
  $cands = ['customer_user_id','customer_id','end_customer_id','user_id'];
  foreach ($cands as $c) if (column_exists($pdo,'orders',$c)) return $c;
  return 'customer_id';
}
$customerCol = detect_customer_col($pdo);
$hasCreatedBy = column_exists($pdo,'orders','created_by_user_id');

// === Order laden ===
try {
  $extra = $hasCreatedBy ? ", o.created_by_user_id AS created_by_user_id" : "";
  $sql = "
    SELECT o.*,
           u.name AS customer_name,
           u.id   AS customer_id,
           p.name AS plan_name,
           s.iccid AS sim_iccid
           {$extra}
    FROM orders o
    LEFT JOIN users u ON u.id = o.`$customerCol`
    LEFT JOIN plans p ON p.id = o.plan_id
    LEFT JOIN sims  s ON s.id = o.sim_id
    WHERE o.id = :id
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':id'=>$id]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    echo "<div class='alert alert-warning'>Bestelling niet gevonden.</div>";
    return;
  }
} catch (Throwable $e) {
  echo "<div class='alert alert-danger'>Laden mislukt: ".e($e->getMessage())."</div>";
  return;
}

$status         = strtolower((string)($order['status'] ?? ''));
$customerId     = (int)($order[$customerCol] ?? 0);
$createdById    = (int)($order['created_by_user_id'] ?? 0);

// === Eenvoudige scope ===
$inScope = true;
if (!$isSuper) {
  if ($isRes || $isSub) {
    $inScope = ($hasCreatedBy && $createdById === (int)$u['id']);
  } else {
    // eindklant
    $inScope = ((int)$u['id'] === $customerId);
  }
}
if (!$inScope) {
  http_response_code(403);
  echo "<div class='alert alert-danger'>Geen toegang tot deze bestelling.</div>";
  echo '<a class="btn btn-outline-secondary" href="index.php?route=orders_list">Terug</a>';
  return;
}

$canEditConcept = ($status === 'concept') && ($isSuper || $isMgr);

// === POST: opslaan bij bewerken (alleen concept) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'edit') {
  if (!$canEditConcept) {
    $errors[] = "Je mag deze bestelling niet (meer) bewerken.";
  } else {
    if (function_exists('verify_csrf')) {
      try { verify_csrf(); } catch (Throwable $e) { $errors[] = "CSRF ongeldig. Probeer opnieuw."; }
    }

    $newCustomer = (int)($_POST['customer_user_id'] ?? 0);
    $newSim      = (int)($_POST['sim_id'] ?? 0);
    $newPlan     = (int)($_POST['plan_id'] ?? 0);

    // status alleen voor super aanpasbaar
    $newStatus = $status;
    if ($isSuper) {
      $allowed = ['concept','awaiting_activation','cancelled','completed'];
      $cand = strtolower(trim((string)($_POST['status'] ?? $status)));
      if (in_array($cand, $allowed, true)) $newStatus = $cand;
    }

    if ($newCustomer<=0) $errors[] = "Eindklant is verplicht.";
    if ($newSim<=0)      $errors[] = "SIM is verplicht.";
    if ($newPlan<=0)     $errors[] = "Abonnement is verplicht.";

    if (empty($errors)) {
      try {
        $sql = "
          UPDATE orders SET
            `$customerCol` = :cid,
            sim_id         = :sid,
            plan_id        = :pid
            ".($isSuper ? ", status = :st " : "")."
          WHERE id = :id
        ";
        $st = $pdo->prepare($sql);
        $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->bindValue(':cid', $newCustomer, PDO::PARAM_INT);
        $st->bindValue(':sid', $newSim, PDO::PARAM_INT);
        $st->bindValue(':pid', $newPlan, PDO::PARAM_INT);
        if ($isSuper) $st->bindValue(':st', $newStatus);
        $st->execute();

        header('Location: index.php?route=order_edit&id='.$id.'&msg=Opgeslagen');
        exit;
      } catch (Throwable $e) {
        $errors[] = "Opslaan mislukt: ".e($e->getMessage());
      }
    }

    // bij fouten: lokale waarden updaten
    if ($newCustomer) $order[$customerCol] = $newCustomer;
    if ($newSim)      $order['sim_id']     = $newSim;
    if ($newPlan)     $order['plan_id']    = $newPlan;
    if ($isSuper)     $order['status']     = $newStatus;
  }
}

// === Keuzelijsten (alleen nodig in bewerkmodus) ===
$customers = $sims = $plans = [];
if ($mode === 'edit' && $canEditConcept) {
  try {
    $customers = $pdo->query("SELECT id, name FROM users WHERE role='customer' ORDER BY name")->fetchAll();
    $sims      = $pdo->query("SELECT id, iccid FROM sims ORDER BY id DESC LIMIT 1000")->fetchAll();
    $plans     = $pdo->query("SELECT id, name FROM plans ORDER BY is_active DESC, name")->fetchAll();
  } catch (Throwable $e) {
    $errors[] = "Keuzelijsten laden mislukt: ".e($e->getMessage());
  }
}

// === View ===
if ($msg): ?>
  <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($mode === 'view' || !$canEditConcept): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4"><strong>Status</strong><br><?= e($order['status'] ?? '') ?></div>
        <div class="col-md-4"><strong>Eindklant</strong><br><?= e($order['customer_name'] ?? '—') ?></div>
        <div class="col-md-4"><strong>SIM</strong><br><?= e($order['sim_iccid'] ?? '—') ?></div>
        <div class="col-md-4"><strong>Abonnement</strong><br><?= e($order['plan_name'] ?? '—') ?></div>
        <div class="col-md-4"><strong>Aangemaakt op</strong><br><?= e($order['created_at'] ?? '') ?></div>
        <div class="col-md-4"><strong>Laatst bijgewerkt</strong><br><?= e($order['updated_at'] ?? '') ?></div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <?php if ($canEditConcept): ?>
      <a class="btn btn-primary" href="index.php?route=order_edit&id=<?= (int)$id ?>&mode=edit">Bewerken</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="index.php?route=orders_list">Terug</a>
  </div>

<?php else: ?>
  <form method="post" action="index.php?route=order_edit&id=<?= (int)$id ?>&mode=edit" class="card">
    <div class="card-body">
      <?php if (function_exists('csrf_field')) csrf_field(); ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Eindklant *</label>
          <select name="customer_user_id" class="form-select" required>
            <option value="">— kies eindklant —</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)($order[$customerCol] ?? 0)===(int)$c['id'])?'selected':'' ?>>
                <?= e($c['name']) ?> (ID <?= (int)$c['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">SIM *</label>
          <select name="sim_id" class="form-select" required>
            <option value="">— kies SIM —</option>
            <?php foreach ($sims as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$order['sim_id']===(int)$s['id'])?'selected':'' ?>>
                <?= e($s['iccid']) ?> (ID <?= (int)$s['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Abonnement *</label>
          <select name="plan_id" class="form-select" required>
            <option value="">— kies abonnement —</option>
            <?php foreach ($plans as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= ((int)$order['plan_id']===(int)$p['id'])?'selected':'' ?>>
                <?= e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($isSuper): ?>
          <div class="col-md-6">
            <label class="form-label">Status (alleen Super-admin)</label>
            <select name="status" class="form-select">
              <?php $opts=['concept'=>'Concept','awaiting_activation'=>'Wachten op activatie','cancelled'=>'Geannuleerd','completed'=>'Voltooid']; ?>
              <?php foreach ($opts as $val=>$label): ?>
                <option value="<?= e($val) ?>" <?= (($order['status'] ?? '')===$val)?'selected':'' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <input type="text" class="form-control" value="<?= e($order['status'] ?? '') ?>" readonly>
            <div class="form-text">Alleen Super-admin kan de status wijzigen.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-primary">Opslaan</button>
      <a class="btn btn-outline-secondary" href="index.php?route=order_edit&id=<?= (int)$id ?>">Annuleren</a>
    </div>
  </form>
<?php endif; ?>
<?php
// pages/plan_add.php — Nieuw abonnement (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }
$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
  flash_set('danger', 'Alleen Super-admin mag abonnementen beheren.');
  redirect('index.php?route=plans_list');
  exit;
}

try { $pdo = db(); }
catch (Throwable $e) {
  echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>';
  return;
}

function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

// POST-afhandeling BOVENAAN en zonder echo/HTML
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) {
    flash_set('danger', 'Sessie verlopen. Probeer opnieuw.');
    redirect('index.php?route=plan_add'); // geen header-warnings meer door redirect()
    exit;
  }

  $name  = trim((string)($_POST['name'] ?? ''));
  $buy_m = (string)($_POST['buy_price_monthly_ex_vat'] ?? '');
  $sell_m= (string)($_POST['sell_price_monthly_ex_vat'] ?? '');
  $buy_mb= (string)($_POST['buy_price_overage_per_mb_ex_vat'] ?? '');
  $sell_mb=(string)($_POST['sell_price_overage_per_mb_ex_vat'] ?? '');
  $setup = (string)($_POST['setup_fee_ex_vat'] ?? '');
  $bundle= (string)($_POST['bundle_gb'] ?? '');
  $netop = trim((string)($_POST['network_operator'] ?? ''));
  $active= isset($_POST['is_active']) ? 1 : 0;

  $errors = [];
  if ($name === '') $errors[] = 'Naam is verplicht.';

  // optioneel: normaliseer lege geldvelden naar NULL
  $toNull = function($v) { $v = trim((string)$v); return ($v === '') ? null : $v; };
  $buy_m   = $toNull($buy_m);
  $sell_m  = $toNull($sell_m);
  $buy_mb  = $toNull($buy_mb);
  $sell_mb = $toNull($sell_mb);
  $setup   = $toNull($setup);
  $bundle  = $toNull($bundle);
  $netop   = $toNull($netop);

  if (!$errors) {
    // kolom-veilig INSERT bouwen (alleen kolommen die bestaan)
    $wanted = [
      'name'                              => $name,
      'buy_price_monthly_ex_vat'          => $buy_m,
      'sell_price_monthly_ex_vat'         => $sell_m,
      'buy_price_overage_per_mb_ex_vat'   => $buy_mb,
      'sell_price_overage_per_mb_ex_vat'  => $sell_mb,
      'setup_fee_ex_vat'                  => $setup,
      'bundle_gb'                         => $bundle,
      'network_operator'                  => $netop,
      'is_active'                         => $active,
    ];

    $cols = [];
    $vals = [];
    foreach ($wanted as $c => $v) {
      if (column_exists($pdo, 'plans', $c)) {
        $cols[] = "`$c`";
        $vals[] = $v;
      }
    }

    if (!$cols) {
      $errors[] = 'Geen bekende kolommen gevonden in tabel plans.';
    } else {
      try {
        $place = implode(',', array_fill(0, count($cols), '?'));
        $sql   = "INSERT INTO plans (".implode(',', $cols).") VALUES ($place)";
        $st    = $pdo->prepare($sql);
        $st->execute($vals);
        flash_set('success', 'Abonnement aangemaakt.');
        redirect('index.php?route=plans_list'); // gebruikt helper met JS fallback
        exit;
      } catch (Throwable $e) {
        $errors[] = 'Opslaan mislukt: '.$e->getMessage();
      }
    }
  }

  if ($errors) {
    flash_set('danger', '<ul class="mb-0"><li>'.implode('</li><li>', array_map('e', $errors)).'</li></ul>');
    redirect('index.php?route=plan_add');
    exit;
  }
}

// === GET weergave (hierna mag er output zijn) ===
echo function_exists('flash_output') ? flash_output() : '';
?>

<h4>Nieuw abonnement</h4>

<form method="post" class="row g-3">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>

  <div class="col-12">
    <label class="form-label">Naam</label>
    <input type="text" name="name" class="form-control" required>
  </div>

  <div class="col-12"><h6 class="mt-3">Commercieel</h6></div>

  <div class="col-md-4">
    <label class="form-label">Inkoopprijs per maand (excl. BTW)</label>
    <input type="number" step="0.01" name="buy_price_monthly_ex_vat" class="form-control">
  </div>
  <div class="col-md-4">
    <label class="form-label">Adviesverkoopprijs per maand (excl. BTW)</label>
    <input type="number" step="0.01" name="sell_price_monthly_ex_vat" class="form-control">
  </div>
  <div class="col-md-4">
    <label class="form-label">Eenmalige setupkosten (excl. BTW)</label>
    <input type="number" step="0.01" name="setup_fee_ex_vat" class="form-control">
  </div>

  <div class="col-md-6">
    <label class="form-label">Inkoop buiten-bundel €/MB (excl. BTW)</label>
    <input type="number" step="0.0001" name="buy_price_overage_per_mb_ex_vat" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Advies buiten-bundel €/MB (excl. BTW)</label>
    <input type="number" step="0.0001" name="sell_price_overage_per_mb_ex_vat" class="form-control">
  </div>

  <div class="col-12"><h6 class="mt-3">Technisch</h6></div>

  <div class="col-md-4">
    <label class="form-label">Bundel (GB)</label>
    <input type="number" step="0.01" name="bundle_gb" class="form-control">
  </div>
  <div class="col-md-4">
    <label class="form-label">Netwerk operator</label>
    <input type="text" name="network_operator" class="form-control" placeholder="bv. KPN / Vodafone / T-Mobile">
  </div>
  <div class="col-md-4 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
      <label class="form-check-label" for="is_active">Status: actief</label>
    </div>
  </div>

  <div class="col-12 d-flex gap-2">
    <button class="btn btn-success">
      <i class="bi bi-check2"></i> Opslaan
    </button>
    <a href="index.php?route=plans_list" class="btn btn-outline-secondary">Annuleren</a>
  </div>
</form>
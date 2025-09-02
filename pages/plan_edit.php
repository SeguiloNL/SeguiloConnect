<?php
// pages/plan_edit.php — Super-admin only, eigen PDO, dynamische kolomdetectie, status bewerken
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<h3>Abonnement bewerken</h3><div class="alert alert-danger">Geen toegang.</div>';
    return;
}

/* ===== PDO bootstrap ===== */
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    $candidates = [
        __DIR__ . '/../db.php',
        __DIR__ . '/../includes/db.php',
        __DIR__ . '/../config/db.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            require_once $f;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
        }
    }
    $cfg = app_config();
    $db  = $cfg['db'] ?? [];
    $dsn = $db['dsn'] ?? null;

    if ($dsn) {
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        $host    = $db['host'] ?? 'localhost';
        $name    = $db['name'] ?? $db['database'] ?? '';
        $user    = $db['user'] ?? $db['username'] ?? '';
        $pass    = $db['pass'] ?? $db['password'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';
        if ($name === '') throw new RuntimeException('DB-naam ontbreekt in config');
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $GLOBALS['pdo'] = $pdo;
}
$pdo = get_pdo();

/* ===== helpers ===== */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}

/* ===== kolommen bepalen ===== */
$has = [
  'description'                      => column_exists($pdo,'plans','description'),
  'buy_price_monthly_ex_vat'         => column_exists($pdo,'plans','buy_price_monthly_ex_vat'),
  'sell_price_monthly_ex_vat'        => column_exists($pdo,'plans','sell_price_monthly_ex_vat'),
  'buy_price_overage_per_mb_ex_vat'  => column_exists($pdo,'plans','buy_price_overage_per_mb_ex_vat'),
  'sell_price_overage_per_mb_ex_vat' => column_exists($pdo,'plans','sell_price_overage_per_mb_ex_vat'),
  'setup_fee_ex_vat'                 => column_exists($pdo,'plans','setup_fee_ex_vat'),
  'bundle_gb'                        => column_exists($pdo,'plans','bundle_gb'),
  'network_operator'                 => column_exists($pdo,'plans','network_operator'),
  'is_active'                        => column_exists($pdo,'plans','is_active'),
  'updated_at'                       => column_exists($pdo,'plans','updated_at'),
];

/* ===== plan laden ===== */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<h3>Abonnement bewerken</h3><div class="alert alert-danger">Ongeldig of ontbrekend ID.</div>';
    return;
}

$cols = ['id','name','created_at'];
foreach ($has as $col=>$exists) {
    if ($exists) $cols[] = $col;
}
$select = implode(', ', array_map(fn($c)=>"`$c`", $cols));

try {
    $st = $pdo->prepare("SELECT $select FROM `plans` WHERE `id` = :id LIMIT 1");
    $st->execute([':id'=>$id]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        echo '<h3>Abonnement bewerken</h3><div class="alert alert-warning">Abonnement niet gevonden.</div>';
        return;
    }
} catch (Throwable $e) {
    echo '<h3>Abonnement bewerken</h3><div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
    return;
}

/* ===== POST / opslaan ===== */
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) { $errors[] = 'Ongeldige sessie (CSRF).'; }

    $name = trim((string)($_POST['name'] ?? ($plan['name'] ?? '')));
    if ($name === '') $errors[] = 'Naam is verplicht.';

    // nieuw waardenpakket
    $new = $plan;
    $new['name'] = $name;

    if ($has['description'])                      $new['description'] = trim((string)($_POST['description'] ?? ($plan['description'] ?? '')));
    if ($has['buy_price_monthly_ex_vat'])         $new['buy_price_monthly_ex_vat'] = (float)str_replace(',', '.', (string)($_POST['buy_price_monthly_ex_vat'] ?? $plan['buy_price_monthly_ex_vat'] ?? 0));
    if ($has['sell_price_monthly_ex_vat'])        $new['sell_price_monthly_ex_vat'] = (float)str_replace(',', '.', (string)($_POST['sell_price_monthly_ex_vat'] ?? $plan['sell_price_monthly_ex_vat'] ?? 0));
    if ($has['buy_price_overage_per_mb_ex_vat'])  $new['buy_price_overage_per_mb_ex_vat'] = (float)str_replace(',', '.', (string)($_POST['buy_price_overage_per_mb_ex_vat'] ?? $plan['buy_price_overage_per_mb_ex_vat'] ?? 0));
    if ($has['sell_price_overage_per_mb_ex_vat']) $new['sell_price_overage_per_mb_ex_vat'] = (float)str_replace(',', '.', (string)($_POST['sell_price_overage_per_mb_ex_vat'] ?? $plan['sell_price_overage_per_mb_ex_vat'] ?? 0));
    if ($has['setup_fee_ex_vat'])                 $new['setup_fee_ex_vat'] = (float)str_replace(',', '.', (string)($_POST['setup_fee_ex_vat'] ?? $plan['setup_fee_ex_vat'] ?? 0));
    if ($has['bundle_gb'])                        $new['bundle_gb'] = (float)str_replace(',', '.', (string)($_POST['bundle_gb'] ?? $plan['bundle_gb'] ?? 0));
    if ($has['network_operator'])                 $new['network_operator'] = trim((string)($_POST['network_operator'] ?? ($plan['network_operator'] ?? '')));
    if ($has['is_active'])                        $new['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

    if (!$errors) {
        $sets = ['`name` = :name'];
        $bind = [':id'=>$id, ':name'=>$new['name']];

        if ($has['description'])                      { $sets[] = "`description` = :description"; $bind[':description'] = $new['description']; }
        if ($has['buy_price_monthly_ex_vat'])         { $sets[] = "`buy_price_monthly_ex_vat` = :bpmev"; $bind[':bpmev'] = $new['buy_price_monthly_ex_vat']; }
        if ($has['sell_price_monthly_ex_vat'])        { $sets[] = "`sell_price_monthly_ex_vat` = :spmev"; $bind[':spmev'] = $new['sell_price_monthly_ex_vat']; }
        if ($has['buy_price_overage_per_mb_ex_vat'])  { $sets[] = "`buy_price_overage_per_mb_ex_vat` = :bpo"; $bind[':bpo'] = $new['buy_price_overage_per_mb_ex_vat']; }
        if ($has['sell_price_overage_per_mb_ex_vat']) { $sets[] = "`sell_price_overage_per_mb_ex_vat` = :spo"; $bind[':spo'] = $new['sell_price_overage_per_mb_ex_vat']; }
        if ($has['setup_fee_ex_vat'])                 { $sets[] = "`setup_fee_ex_vat` = :setup"; $bind[':setup'] = $new['setup_fee_ex_vat']; }
        if ($has['bundle_gb'])                        { $sets[] = "`bundle_gb` = :bundle"; $bind[':bundle'] = $new['bundle_gb']; }
        if ($has['network_operator'])                 { $sets[] = "`network_operator` = :netop"; $bind[':netop'] = $new['network_operator']; }
        if ($has['is_active'])                        { $sets[] = "`is_active` = :active"; $bind[':active'] = (int)$new['is_active']; }
        if ($has['updated_at'])                       { $sets[] = "`updated_at` = NOW()"; }

        try {
            $sql = "UPDATE `plans` SET ".implode(', ', $sets)." WHERE `id` = :id";
            $up  = $pdo->prepare($sql);
            foreach ($bind as $k=>$v) {
                $up->bindValue($k, $v, is_int($v) || is_float($v) ? PDO::PARAM_STR : PDO::PARAM_STR);
            }
            // correct types voor int/float
            if (isset($bind[':id']))     $up->bindValue(':id', (int)$bind[':id'], PDO::PARAM_INT);
            if (isset($bind[':active'])) $up->bindValue(':active', (int)$bind[':active'], PDO::PARAM_INT);
            $up->execute();

            flash_set('success', 'Abonnement opgeslagen.');
            header('Location: index.php?route=plans_list');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Opslaan mislukt: '.$e->getMessage();
        }
        // Bij fout: toon formulier met geposte waarden
        $plan = $new;
    }
}

/* ===== UI ===== */
?>
<h3>Abonnement bewerken</h3>

<?php if ($msg = flash_get('success')): ?>
  <div class="alert alert-success"><?= e($msg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?route=plan_edit&id=<?= (int)$id ?>" class="card">
  <div class="card-body">
    <?php if (function_exists('csrf_field')) csrf_field(); ?>

    <div class="mb-3">
      <label class="form-label">Naam <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" value="<?= e($plan['name'] ?? '') ?>" required>
    </div>

    <?php if ($has['description']): ?>
      <div class="mb-3">
        <label class="form-label">Omschrijving</label>
        <textarea name="description" class="form-control" rows="3"><?= e($plan['description'] ?? '') ?></textarea>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if ($has['buy_price_monthly_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Inkoopprijs p/m (ex)</label>
          <input type="number" step="0.01" min="0" name="buy_price_monthly_ex_vat" class="form-control" value="<?= e((string)($plan['buy_price_monthly_ex_vat'] ?? '0')) ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['sell_price_monthly_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Adviesverkoop p/m (ex)</label>
          <input type="number" step="0.01" min="0" name="sell_price_monthly_ex_vat" class="form-control" value="<?= e((string)($plan['sell_price_monthly_ex_vat'] ?? '0')) ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['setup_fee_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Eenmalige setup (ex)</label>
          <input type="number" step="0.01" min="0" name="setup_fee_ex_vat" class="form-control" value="<?= e((string)($plan['setup_fee_ex_vat'] ?? '0')) ?>">
        </div>
      <?php endif; ?>

      <?php if ($has['buy_price_overage_per_mb_ex_vat']): ?>
        <div class="col-md-6">
          <label class="form-label">Inkoop buiten bundel /MB (ex)</label>
          <input type="number" step="0.0001" min="0" name="buy_price_overage_per_mb_ex_vat" class="form-control" value="<?= e((string)($plan['buy_price_overage_per_mb_ex_vat'] ?? '0')) ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['sell_price_overage_per_mb_ex_vat']): ?>
        <div class="col-md-6">
          <label class="form-label">Advies buiten bundel /MB (ex)</label>
          <input type="number" step="0.0001" min="0" name="sell_price_overage_per_mb_ex_vat" class="form-control" value="<?= e((string)($plan['sell_price_overage_per_mb_ex_vat'] ?? '0')) ?>">
        </div>
      <?php endif; ?>

      <?php if ($has['bundle_gb']): ?>
        <div class="col-md-6">
          <label class="form-label">Bundel (GB)</label>
          <input type="number" step="0.01" min="0" name="bundle_gb" class="form-control" value="<?= e((string)($plan['bundle_gb'] ?? '0')) ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['network_operator']): ?>
        <div class="col-md-6">
          <label class="form-label">Netwerk operator</label>
          <input type="text" name="network_operator" class="form-control" value="<?= e($plan['network_operator'] ?? '') ?>">
        </div>
      <?php endif; ?>
    </div>

    <?php if ($has['is_active']): ?>
      <div class="form-check form-switch mt-3">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= ((int)($plan['is_active'] ?? 0)===1)?'checked':'' ?>>
        <label class="form-check-label" for="is_active">Status: actief</label>
      </div>
    <?php endif; ?>

    <div class="row mt-3">
      <div class="col-md-6">
        <label class="form-label">Aangemaakt</label>
        <input type="text" class="form-control" value="<?= e($plan['created_at'] ?? '') ?>" disabled>
      </div>
      <?php if ($has['updated_at']): ?>
      <div class="col-md-6">
        <label class="form-label">Laatst bijgewerkt</label>
        <input type="text" class="form-control" value="<?= e($plan['updated_at'] ?? '') ?>" disabled>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary">Opslaan</button>
    <a class="btn btn-outline-secondary" href="index.php?route=plans_list">Annuleren</a>
  </div>
</form>
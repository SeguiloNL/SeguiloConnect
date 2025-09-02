<?php
// pages/plan_add.php — Super-admin only, eigen PDO, dynamische kolomdetectie, veilig opslaan
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<h3>Nieuw abonnement</h3><div class="alert alert-danger">Geen toegang.</div>';
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
function parse_num($v, int $decimals = 4) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace([' ', ','], ['', '.'], $v);
    if (!is_numeric($v)) return null;
    return round((float)$v, $decimals);
}

/* ===== kolommen detecteren ===== */
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

/* ===== POST: opslaan ===== */
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) { $errors[] = 'Ongeldige sessie (CSRF).'; }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') $errors[] = 'Naam is verplicht.';

    $data = ['name' => $name];

    if ($has['description'])                      $data['description'] = trim((string)($_POST['description'] ?? ''));
    if ($has['buy_price_monthly_ex_vat'])         $data['buy_price_monthly_ex_vat'] = parse_num($_POST['buy_price_monthly_ex_vat'] ?? '', 2) ?? 0.00;
    if ($has['sell_price_monthly_ex_vat'])        $data['sell_price_monthly_ex_vat'] = parse_num($_POST['sell_price_monthly_ex_vat'] ?? '', 2) ?? 0.00;
    if ($has['buy_price_overage_per_mb_ex_vat'])  $data['buy_price_overage_per_mb_ex_vat'] = parse_num($_POST['buy_price_overage_per_mb_ex_vat'] ?? '', 4) ?? 0.0000;
    if ($has['sell_price_overage_per_mb_ex_vat']) $data['sell_price_overage_per_mb_ex_vat'] = parse_num($_POST['sell_price_overage_per_mb_ex_vat'] ?? '', 4) ?? 0.0000;
    if ($has['setup_fee_ex_vat'])                 $data['setup_fee_ex_vat'] = parse_num($_POST['setup_fee_ex_vat'] ?? '', 2) ?? 0.00;
    if ($has['bundle_gb'])                        $data['bundle_gb'] = parse_num($_POST['bundle_gb'] ?? '', 2) ?? 0.00;
    if ($has['network_operator'])                 $data['network_operator'] = trim((string)($_POST['network_operator'] ?? ''));
    if ($has['is_active'])                        $data['is_active'] = !empty($_POST['is_active']) ? 1 : 0;

    if (!$errors) {
        // Bouw dynamische INSERT
        $cols = ['name', 'created_at'];
        $vals = [':name', 'NOW()'];
        $bind = [':name' => $data['name']];

        foreach ([
            'description',
            'buy_price_monthly_ex_vat',
            'sell_price_monthly_ex_vat',
            'buy_price_overage_per_mb_ex_vat',
            'sell_price_overage_per_mb_ex_vat',
            'setup_fee_ex_vat',
            'bundle_gb',
            'network_operator',
            'is_active'
        ] as $c) {
            if (!empty($has[$c])) {
                $cols[] = $c;
                $ph = ':'.$c;
                $vals[] = $ph;
                $bind[$ph] = $data[$c] ?? null;
            }
        }
        if (!empty($has['updated_at'])) {
            $cols[] = 'updated_at';
            $vals[] = 'NOW()';
        }

        try {
            $sql = "INSERT INTO `plans` (".implode(',', array_map(fn($c)=>"`$c`",$cols)).") VALUES (".implode(',', $vals).")";
            $st  = $pdo->prepare($sql);
            foreach ($bind as $k=>$v) {
                if (is_int($v)) {
                    $st->bindValue($k, $v, PDO::PARAM_INT);
                } else {
                    $st->bindValue($k, $v);
                }
            }
            $st->execute();

            flash_set('success','Abonnement aangemaakt.');
            header('Location: index.php?route=plans_list');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Kon plan niet opslaan: ' . $e->getMessage();
        }
    }
}

/* ===== UI ===== */
?>
<h3>Nieuw abonnement</h3>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" action="index.php?route=plan_add" class="card">
  <div class="card-body">
    <?php if (function_exists('csrf_field')) csrf_field(); ?>

    <div class="mb-3">
      <label class="form-label">Naam <span class="text-danger">*</span></label>
      <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
    </div>

    <?php if ($has['description']): ?>
      <div class="mb-3">
        <label class="form-label">Omschrijving</label>
        <textarea name="description" class="form-control" rows="3"><?= e($_POST['description'] ?? '') ?></textarea>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <?php if ($has['buy_price_monthly_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Inkoopprijs p/m (ex)</label>
          <input type="number" step="0.01" min="0" name="buy_price_monthly_ex_vat" class="form-control" value="<?= e($_POST['buy_price_monthly_ex_vat'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['sell_price_monthly_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Adviesverkoop p/m (ex)</label>
          <input type="number" step="0.01" min="0" name="sell_price_monthly_ex_vat" class="form-control" value="<?= e($_POST['sell_price_monthly_ex_vat'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['setup_fee_ex_vat']): ?>
        <div class="col-md-4">
          <label class="form-label">Eenmalige setup (ex)</label>
          <input type="number" step="0.01" min="0" name="setup_fee_ex_vat" class="form-control" value="<?= e($_POST['setup_fee_ex_vat'] ?? '') ?>">
        </div>
      <?php endif; ?>

      <?php if ($has['buy_price_overage_per_mb_ex_vat']): ?>
        <div class="col-md-6">
          <label class="form-label">Inkoop buiten bundel /MB (ex)</label>
          <input type="number" step="0.0001" min="0" name="buy_price_overage_per_mb_ex_vat" class="form-control" value="<?= e($_POST['buy_price_overage_per_mb_ex_vat'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['sell_price_overage_per_mb_ex_vat']): ?>
        <div class="col-md-6">
          <label class="form-label">Advies buiten bundel /MB (ex)</label>
          <input type="number" step="0.0001" min="0" name="sell_price_overage_per_mb_ex_vat" class="form-control" value="<?= e($_POST['sell_price_overage_per_mb_ex_vat'] ?? '') ?>">
        </div>
      <?php endif; ?>

      <?php if ($has['bundle_gb']): ?>
        <div class="col-md-6">
          <label class="form-label">Bundel (GB)</label>
          <input type="number" step="0.01" min="0" name="bundle_gb" class="form-control" value="<?= e($_POST['bundle_gb'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <?php if ($has['network_operator']): ?>
        <div class="col-md-6">
          <label class="form-label">Netwerk operator</label>
          <input type="text" name="network_operator" class="form-control" value="<?= e($_POST['network_operator'] ?? '') ?>">
        </div>
      <?php endif; ?>
    </div>

    <?php if ($has['is_active']): ?>
      <div class="form-check form-switch mt-3">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($_POST['is_active'])?'checked':'' ?>>
        <label class="form-check-label" for="is_active">Status: actief</label>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-footer d-flex gap-2">
    <button class="btn btn-primary">Opslaan</button>
    <a class="btn btn-outline-secondary" href="index.php?route=plans_list">Annuleren</a>
  </div>
</form>
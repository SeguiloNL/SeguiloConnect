<?php
// pages/system_admin.php — Systeembeheer (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<h3>Systeembeheer</h3><div class="alert alert-danger mt-3">Geen toegang.</div>';
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
    $cfg = app_config(); $db = $cfg['db'] ?? []; $dsn = $db['dsn'] ?? null;

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
function ensure_settings_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `k`  VARCHAR(64) NOT NULL,
            `v`  TEXT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_k` (`k`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
function settings_get(PDO $pdo, string $key, $default=null) {
    ensure_settings_table($pdo);
    $st = $pdo->prepare("SELECT v FROM settings WHERE k = :k LIMIT 1");
    $st->execute([':k'=>$key]);
    $val = $st->fetchColumn();
    return $val !== false ? $val : $default;
}
function settings_set(PDO $pdo, string $key, string $value): void {
    ensure_settings_table($pdo);
    $st = $pdo->prepare("INSERT INTO settings (k,v) VALUES (:k,:v)
                         ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $st->execute([':k'=>$key, ':v'=>$value]);
}

/* ===== huidige instellingen ===== */
$orderPrefix = (string)settings_get($pdo, 'order_prefix', '');

/* ===== acties afhandelen (POST + CSRF) ===== */
$alerts = []; // [['type'=>'success'|'danger'|'warning', 'msg'=>'...']]

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); }
    catch (Throwable $e) { $alerts[] = ['type'=>'danger','msg'=>'Ongeldige sessie (CSRF). Probeer opnieuw.']; }

    $action = $_POST['action'] ?? '';
    if (!$alerts) {
        try {
            switch ($action) {
                case 'save_settings': {
                    $prefix = (string)($_POST['order_prefix'] ?? '');
                    $prefix = mb_substr($prefix, 0, 5, 'UTF-8'); // max 5 tekens
                    settings_set($pdo, 'order_prefix', $prefix);
                    $orderPrefix = $prefix;
                    $alerts[] = ['type'=>'success','msg'=>'Instellingen opgeslagen.'];
                    break;
                }

                case 'reset_order_numbers': {
                    // Reset alle order_no naar prefix + oplopend nummer (000001)
                    if (!column_exists($pdo,'orders','order_no')) {
                        throw new RuntimeException('Kolom orders.order_no ontbreekt.');
                    }
                    $prefix = (string)settings_get($pdo, 'order_prefix', '');
                    $pdo->beginTransaction();

                    // Maak alle order_no leeg
                    $pdo->exec("UPDATE orders SET order_no = NULL");

                    // Haal alle order-ids op in chronologische volgorde
                    $st = $pdo->query("SELECT id FROM orders ORDER BY id ASC");
                    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

                    $seq = 1;
                    $up = $pdo->prepare("UPDATE orders SET order_no = :no WHERE id = :id");
                    foreach ($ids as $id) {
                        $no = $prefix . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
                        $up->execute([':no'=>$no, ':id'=>(int)$id]);
                        $seq++;
                    }
                    $pdo->commit();
                    $alerts[] = ['type'=>'success','msg'=>'Alle ordernummers zijn opnieuw genummerd.'];
                    break;
                }

                case 'reset_sim_ids': {
                    // Her-nummer SIM-id's vanaf 1 en werk orders.sim_id bij
                    if (!column_exists($pdo,'sims','id')) {
                        throw new RuntimeException('Tabel sims ontbreekt of heeft geen id.');
                    }
                    $pdo->beginTransaction();

                    // 1) Tijdelijke tabel maken met extra kolom old_id
                    $pdo->exec("DROP TABLE IF EXISTS sims_tmp");
                    $pdo->exec("CREATE TABLE sims_tmp LIKE sims");
                    // extra kolom voor mapping
                    $pdo->exec("ALTER TABLE sims_tmp ADD COLUMN old_id INT NULL");

                    // 2) Kolomnamen bepalen (behalve id)
                    $colsStmt = $pdo->query("SHOW COLUMNS FROM sims");
                    $allCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
                    $copyCols = array_values(array_filter($allCols, fn($c)=>$c !== 'id'));

                    // 3) Data kopiëren, inclusief old_id
                    //    Bouw SELECT lijst: alle kolommen behalve id, en voeg originele id als old_id toe
                    $selectCols = implode(', ', array_map(fn($c)=>"`$c`", $copyCols));
                    $pdo->exec("INSERT INTO sims_tmp ($selectCols, old_id) SELECT $selectCols, id FROM sims ORDER BY id ASC");

                    // 4) Nu heeft sims_tmp nieuwe auto-inc id's. Bouw mapping old->new.
                    $mapStmt = $pdo->query("SELECT id AS new_id, old_id FROM sims_tmp");
                    $map = [];
                    while ($r = $mapStmt->fetch(PDO::FETCH_ASSOC)) {
                        $map[(int)$r['old_id']] = (int)$r['new_id'];
                    }

                    // 5) Orders bijwerken (als kolommen bestaan)
                    if (column_exists($pdo,'orders','sim_id')) {
                        $rowsUpdated = 0;
                        $sel = $pdo->query("SELECT id, sim_id FROM orders WHERE sim_id IS NOT NULL");
                        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
                        $up = $pdo->prepare("UPDATE orders SET sim_id = :new WHERE id = :id");

                        foreach ($rows as $row) {
                            $old = (int)$row['sim_id'];
                            if ($old && isset($map[$old])) {
                                $up->execute([':new'=>$map[$old], ':id'=>(int)$row['id']]);
                                $rowsUpdated++;
                            }
                        }
                    }

                    // 6) Oude tabel droppen en tmp hernoemen
                    $pdo->exec("DROP TABLE sims");
                    // tmp opruimen: old_id kolom weg
                    $pdo->exec("ALTER TABLE sims_tmp DROP COLUMN old_id");
                    $pdo->exec("RENAME TABLE sims_tmp TO sims");

                    $pdo->commit();
                    $alerts[] = ['type'=>'success','msg'=>'SIM-ID’s opnieuw genummerd vanaf 1 (orders bijgewerkt).'];
                    break;
                }

                case 'delete_all_sims_without_orders': {
                    // Verwijder alle sims die in geen enkele order gebruikt worden
                    if (!column_exists($pdo,'sims','id')) {
                        throw new RuntimeException('Tabel sims ontbreekt.');
                    }
                    // als orders.sim_id bestaat, filter daarop
                    if (column_exists($pdo,'orders','sim_id')) {
                        $st = $pdo->query("SELECT COUNT(*) FROM sims s LEFT JOIN orders o ON o.sim_id = s.id WHERE o.sim_id IS NULL");
                        $cnt = (int)$st->fetchColumn();
                        $pdo->exec("DELETE s FROM sims s LEFT JOIN orders o ON o.sim_id = s.id WHERE o.sim_id IS NULL");
                        $alerts[] = ['type'=>'success','msg'=>"Verwijderd: {$cnt} simkaart(en) zonder orders."];
                    } else {
                        // geen order-koppeling bekend → ALLES verwijderen?
                        $pdo->exec("TRUNCATE TABLE sims");
                        $alerts[] = ['type'=>'warning','msg'=>'Alle simkaarten verwijderd (orders.sim_id bestaat niet; er is geen koppeling gecontroleerd).'];
                    }
                    break;
                }

                case 'delete_all_orders_hard': {
                    if (!column_exists($pdo,'orders','id')) {
                        throw new RuntimeException('Tabel orders ontbreekt.');
                    }
                    $pdo->exec("DELETE FROM orders");
                    $alerts[] = ['type'=>'success','msg'=>'Alle orders zijn hard verwijderd.'];
                    break;
                }

                default:
                    $alerts[] = ['type'=>'warning','msg'=>'Onbekende actie.'];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch(Throwable $e2) {} }
            $alerts[] = ['type'=>'danger','msg'=>'Actie mislukt: ' . e($e->getMessage())];
        }
    }
}

/* ===== UI ===== */
?>
<h3>Systeembeheer</h3>

<?php foreach ($alerts as $a): ?>
  <div class="alert alert-<?= e($a['type']) ?>"><?= e($a['msg']) ?></div>
<?php endforeach; ?>

<div class="row g-3">

  <!-- Reset tellers -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header fw-semibold">Reset tellers</div>
      <div class="card-body">
        <p class="text-muted mb-3">Gebruik deze acties voorzichtig. Niet omkeerbaar.</p>

        <form method="post" class="mb-2" onsubmit="return confirm('Alle ordernummers opnieuw nummeren. Doorgaan?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="reset_order_numbers">
          <button class="btn btn-outline-primary">Reset alle ordernummers</button>
        </form>

        <form method="post" class="mb-2" onsubmit="return confirm('SIM-ID’s hernummeren en orders bijwerken. Doorgaan?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="reset_sim_ids">
          <button class="btn btn-outline-primary">Reset SIM-ID’s (hernummer vanaf 1)</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Verwijderen -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header fw-semibold">Verwijderen</div>
      <div class="card-body">
        <p class="text-muted mb-3">Definitief verwijderen. Geen herstel mogelijk.</p>

        <form method="post" class="mb-2" onsubmit="return confirm('Alle simkaarten zonder orders verwijderen. Doorgaan?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="delete_all_sims_without_orders">
          <button class="btn btn-outline-danger">Verwijder alle simkaarten (zonder orders)</button>
        </form>

        <form method="post" class="mb-2" onsubmit="return confirm('ALLE orders definitief verwijderen. Weet je het zeker?')">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="delete_all_orders_hard">
          <button class="btn btn-outline-danger">Verwijder alle orders (hard)</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Systeeminstellingen -->
  <div class="col-lg-6">
    <div class="card h-100 mt-3">
      <div class="card-header fw-semibold">Systeeminstellingen</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <?php csrf_field(); ?>
          <input type="hidden" name="action" value="save_settings">
          <div class="col-md-6">
            <label class="form-label">Ordernummer prefix (max 5 tekens)</label>
            <input type="text" name="order_prefix" maxlength="5" class="form-control" value="<?= e($orderPrefix) ?>" placeholder="Bijv. ORD-">
            <div class="form-text">Prefix wordt gebruikt bij het opnieuw nummeren van orders.</div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary">Instellingen opslaan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

</div>
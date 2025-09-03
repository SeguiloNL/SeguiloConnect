<?php
// pages/system_admin.php — alleen Super-admin
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role    = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
  echo '<div class="alert alert-danger">Alleen Super-admin heeft toegang tot Systeembeheer.</div>';
  return;
}

// ---------- DB ----------
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
function get_setting(PDO $pdo, string $key): ?string {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) NULL)");
  $st = $pdo->prepare("SELECT v FROM system_settings WHERE k=?");
  $st->execute([$key]);
  $v = $st->fetchColumn();
  return $v === false ? null : (string)$v;
}
function set_setting(PDO $pdo, string $key, ?string $val): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) NULL)");
  $st = $pdo->prepare("INSERT INTO system_settings (k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $st->execute([$key, $val]);
}

// ---------- POST acties ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) {
    flash_set('danger','Sessie verlopen. Probeer opnieuw.');
    redirect('index.php?route=system_admin');
  }

  $action = (string)($_POST['action'] ?? '');

  try {
    switch ($action) {
      case 'reset_order_numbers': {
        if (!table_exists($pdo,'orders') || !column_exists($pdo,'orders','order_number')) {
          flash_set('warning','De kolom orders.order_number bestaat niet. Niets gewijzigd.');
          break;
        }
        // Zet alles op NULL en hernummer op basis van id
        $pdo->beginTransaction();
        $pdo->exec("UPDATE orders SET order_number = NULL");
        // MySQL user variable voor hernummeren
        $pdo->exec("SET @rn := 0");
        $pdo->exec("UPDATE orders SET order_number = (@rn := @rn + 1) ORDER BY id ASC");
        $pdo->commit();
        flash_set('success','Alle ordernummers zijn opnieuw ingesteld.');
        break;
      }

      case 'reset_sim_ids': {
        if (!table_exists($pdo,'sims')) {
          flash_set('warning','Tabel sims bestaat niet.');
          break;
        }
        // Veiligheid: alleen als er geen orders verwijzen naar sims
        $ordersRef = 0;
        if (table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id')) {
          $ordersRef = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE sim_id IS NOT NULL")->fetchColumn();
        }
        if ($ordersRef > 0) {
          flash_set('danger','Kan SIM-ID’s niet hernummeren: er zijn orders die naar sims verwijzen.');
          break;
        }

        // Hernummeren door kopiëren zonder id-kolom
        // 1) kolommen ophalen
        $cols = [];
        $stc = $pdo->query("SHOW COLUMNS FROM sims");
        while ($r = $stc->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Field']; }
        if (!in_array('id', $cols, true)) {
          flash_set('danger','Kolom sims.id niet gevonden.');
          break;
        }
        $otherCols = array_values(array_filter($cols, fn($c)=>$c !== 'id'));
        if (!$otherCols) {
          flash_set('danger','Geen kolommen om te kopiëren.');
          break;
        }
        $colList = implode(',', array_map(fn($c)=>"`$c`", $otherCols));

        $pdo->beginTransaction();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec("CREATE TEMPORARY TABLE tmp_sims AS SELECT $colList FROM sims ORDER BY id ASC");
        $pdo->exec("TRUNCATE TABLE sims");
        // Insert zonder id -> auto_increment start vanaf 1
        $pdo->exec("INSERT INTO sims ($colList) SELECT $colList FROM tmp_sims");
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_sims");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $pdo->commit();

        flash_set('success','SIM-ID’s zijn opnieuw genummerd vanaf 1.');
        break;
      }

      case 'delete_all_sims_without_orders': {
        if (!table_exists($pdo,'sims')) {
          flash_set('warning','Tabel sims bestaat niet.');
          break;
        }
        $pdo->beginTransaction();
        if (table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id')) {
          // verwijder alleen sims die niet in orders voorkomen
          $sql = "DELETE s FROM sims s LEFT JOIN orders o ON o.sim_id = s.id WHERE o.id IS NULL";
          $pdo->exec($sql);
        } else {
          // als er überhaupt geen orders-tabel is, dan alles weg
          $pdo->exec("DELETE FROM sims");
        }
        $pdo->commit();
        flash_set('success','Alle simkaarten zonder orders zijn verwijderd.');
        break;
      }

      case 'delete_all_orders_hard': {
        if (!table_exists($pdo,'orders')) {
          flash_set('warning','Tabel orders bestaat niet.');
          break;
        }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM orders");
        $pdo->commit();
        flash_set('success','Alle orders zijn verwijderd.');
        break;
      }

      case 'save_settings': {
        // Ordernummer-prefix opslaan (max 5 tekens)
        $prefix = trim((string)($_POST['order_prefix'] ?? ''));
        if (mb_strlen($prefix) > 5) {
          flash_set('warning','Prefix te lang (max 5 tekens).');
        } else {
          set_setting($pdo, 'order_prefix', $prefix);
          flash_set('success','Systeeminstellingen opgeslagen.');
        }
        break;
      }

      default:
        flash_set('warning','Onbekende actie.');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash_set('danger','Actie mislukt: '.$e->getMessage());
  }

  redirect('index.php?route=system_admin');
}

// ---------- huidige instellingen ----------
$orderPrefix = get_setting($pdo, 'order_prefix') ?? '';

// ---------- pagina ----------
echo function_exists('flash_output') ? flash_output() : '';
?>

<h4>Systeembeheer</h4>

<div class="row g-3">
  <!-- Reset tellers -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Reset tellers</h5>
        <p class="text-muted">Zet volgnummering terug naar een frisse start.</p>

        <form method="post" class="d-inline me-2">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="reset_order_numbers">
          <button class="btn btn-primary"
                  onclick="return confirm('Alle ordernummers opnieuw instellen?')">
            Reset alle ordernummers
          </button>
        </form>

        <form method="post" class="d-inline">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="reset_sim_ids">
          <button class="btn btn-outline-primary"
                  onclick="return confirm('SIM-ID’s hernummeren vanaf 1? Dit kan alleen als er geen orders aan sims hangen.')">
            Reset SIM-ID’s (hernummer vanaf 1)
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Verwijderen -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Verwijderen</h5>
        <p class="text-muted">Permanente verwijder-acties. Niet omkeerbaar.</p>

        <form method="post" class="d-inline me-2">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="delete_all_sims_without_orders">
          <button class="btn btn-danger"
                  onclick="return confirm('Alle simkaarten zonder gekoppelde orders verwijderen?')">
            Verwijder alle simkaarten (zonder orders)
          </button>
        </form>

        <form method="post" class="d-inline">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="delete_all_orders_hard">
          <button class="btn btn-outline-danger"
                  onclick="return confirm('ALLE orders hard verwijderen? Dit is niet terug te draaien!')">
            Verwijder alle orders (hard)
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Systeeminstellingen -->
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Systeeminstellingen</h5>
        <form method="post" class="row g-3">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="save_settings">

          <div class="col-sm-6 col-md-4 col-lg-3">
            <label class="form-label">Ordernummer-prefix</label>
            <input type="text" name="order_prefix" maxlength="5" class="form-control" value="<?= e($orderPrefix) ?>" placeholder="max 5 tekens">
            <div class="form-text">Bijv. <code>SEGU-</code>. Maximaal 5 tekens.</div>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-success">Instellingen opslaan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
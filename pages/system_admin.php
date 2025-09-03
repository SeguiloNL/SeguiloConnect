<?php
// views/system_admin.php — Systeembeheer (alleen Super-admin)
// Wordt gerenderd binnen je layout (header/footer via index.php)

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
function ensure_settings_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
      k VARCHAR(64) PRIMARY KEY,
      v VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function get_setting(PDO $pdo, string $key): ?string {
  ensure_settings_table($pdo);
  $st = $pdo->prepare("SELECT v FROM system_settings WHERE k=?");
  $st->execute([$key]);
  $v = $st->fetchColumn();
  return $v === false ? null : (string)$v;
}
function set_setting(PDO $pdo, string $key, ?string $val): void {
  ensure_settings_table($pdo);
  $st = $pdo->prepare("INSERT INTO system_settings (k,v) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE v=VALUES(v)");
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
        $pdo->beginTransaction();
        $pdo->exec("UPDATE orders SET order_number = NULL");
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
        $ordersRef = 0;
        if (table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id')) {
          $ordersRef = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE sim_id IS NOT NULL")->fetchColumn();
        }
        if ($ordersRef > 0) {
          flash_set('danger','Kan SIM-ID’s niet hernummeren: er zijn orders die naar sims verwijzen.');
          break;
        }

        // Hernummer via tijdelijke kopie (FK-veilig)
        $cols = [];
        $stc = $pdo->query("SHOW COLUMNS FROM sims");
        while ($r = $stc->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Field']; }
        if (!in_array('id', $cols, true)) { flash_set('danger','Kolom sims.id niet gevonden.'); break; }
        $otherCols = array_values(array_filter($cols, fn($c)=>$c !== 'id'));
        if (!$otherCols) { flash_set('danger','Geen kolommen om te kopiëren.'); break; }
        $colList = implode(',', array_map(fn($c)=>"`$c`", $otherCols));

        $pdo->beginTransaction();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec("CREATE TEMPORARY TABLE tmp_sims AS SELECT $colList FROM sims ORDER BY id ASC");
        $pdo->exec("TRUNCATE TABLE sims");
        $pdo->exec("INSERT INTO sims ($colList) SELECT $colList FROM tmp_sims");
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS tmp_sims");
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        $pdo->commit();

        flash_set('success','SIM-ID’s zijn opnieuw genummerd vanaf 1.');
        break;
      }

      case 'delete_all_sims_without_orders': {
        if (!table_exists($pdo,'sims')) { flash_set('warning','Tabel sims bestaat niet.'); break; }
        $pdo->beginTransaction();
        if (table_exists($pdo,'orders') && column_exists($pdo,'orders','sim_id')) {
          $pdo->exec("DELETE s FROM sims s LEFT JOIN orders o ON o.sim_id = s.id WHERE o.id IS NULL");
        } else {
          $pdo->exec("DELETE FROM sims");
        }
        $pdo->commit();
        flash_set('success','Alle simkaarten zonder orders zijn verwijderd.');
        break;
      }

      case 'delete_all_orders_hard': {
        if (!table_exists($pdo,'orders')) { flash_set('warning','Tabel orders bestaat niet.'); break; }
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM orders");
        $pdo->commit();
        flash_set('success','Alle orders zijn verwijderd.');
        break;
      }

      case 'save_brand_settings': {
        $orderPrefix = trim((string)($_POST['order_prefix'] ?? ''));
        $logoUrl     = trim((string)($_POST['brand_logo_url'] ?? ''));

        if (mb_strlen($orderPrefix) > 5) {
          flash_set('warning','Prefix te lang (max 5 tekens).');
        } else {
          set_setting($pdo, 'order_prefix', $orderPrefix !== '' ? $orderPrefix : null);
          set_setting($pdo, 'brand_logo_url', $logoUrl !== '' ? $logoUrl : null);
          flash_set('success','Huisstijl & instellingen opgeslagen.');
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
$brandLogoUrl = get_setting($pdo, 'brand_logo_url') ?? '';

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
          <button class="btn btn-primary" onclick="return confirm('Alle ordernummers opnieuw instellen?')">
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

  <!-- Huisstijl & instellingen -->
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Huisstijl & instellingen</h5>

        <form method="post" class="row g-3">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="save_brand_settings">

          <div class="col-sm-6 col-md-4">
            <label class="form-label">Ordernummer-prefix</label>
            <input type="text" name="order_prefix" maxlength="5" class="form-control"
                   value="<?= e($orderPrefix) ?>" placeholder="bv. SEGU-">
            <div class="form-text">Max. 5 tekens. Wordt gebruikt bij tonen/genereren van ordernummers (als je die logica toepast).</div>
          </div>

          <div class="col-sm-6 col-md-6">
            <label class="form-label">Logo-URL</label>
            <input type="url" name="brand_logo_url" class="form-control"
                   placeholder="https://…/logo.png" value="<?= e($brandLogoUrl) ?>">
            <div class="form-text">
              Dit logo wordt linksboven in het menu getoond (zie <code>views/header.php</code>). Gebruik bij voorkeur een transparante PNG/SVG.
            </div>
          </div>

          <div class="col-12 d-flex align-items-end gap-3">
            <button class="btn btn-success">Opslaan</button>
            <?php if (!empty($brandLogoUrl)): ?>
              <div class="border rounded p-2">
                <div class="text-muted small mb-1">Voorbeeld:</div>
                <img src="<?= e($brandLogoUrl) ?>" alt="Logo preview" style="height:40px; width:auto;">
              </div>

          <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-body">
              <h5 class="card-title">Systeemgebruikers</h5>
              <p class="text-muted">Beheer accounts met rol <code>super_admin</code>.</p>
              <a class="btn btn-outline-primary" href="index.php?route=system_users">
                    Open beheer Systeemgebruikers
              </a>
                </div>
              </div>
            </div>  
            <?php endif; ?>
          </div>
        </form>

      </div>
    </div>
  </div>
</div>
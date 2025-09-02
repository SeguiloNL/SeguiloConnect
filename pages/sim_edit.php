<?php
// pages/sim_edit.php — simkaart bewerken (alleen Super-admin)
// Toont/opslaat o.a. ICCID, Label, IMSI, PIN, PUK (als de kolommen bestaan)
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<h3>Simkaart bewerken</h3><div class="alert alert-danger mt-3">Geen toegang.</div>';
    return;
}

/* ===== PDO bootstrap ===== */
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

    $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
    foreach ($candidates as $f) {
        if (is_file($f)) { require_once $f; if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; }
    }

    $cfg = app_config(); $db = $cfg['db'] ?? []; $dsn = $db['dsn'] ?? null;
    if ($dsn) {
        $pdo = new PDO($dsn, $db['user']??null, $db['pass']??null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } else {
        $host=$db['host']??'localhost';
        $name=$db['name']??$db['database']??'';
        $user=$db['user']??$db['username']??'';
        $pass=$db['pass']??$db['password']??'';
        $charset=$db['charset']??'utf8mb4';
        if ($name==='') throw new RuntimeException('DB-naam ontbreekt in config');
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
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

$has = [
    'iccid'               => column_exists($pdo,'sims','iccid'),
    'label'               => column_exists($pdo,'sims','label'),
    'status'              => column_exists($pdo,'sims','status'),
    'retired'             => column_exists($pdo,'sims','retired'),
    'assigned_to_user_id' => column_exists($pdo,'sims','assigned_to_user_id'),
    'owner_user_id'       => column_exists($pdo,'sims','owner_user_id'),
    // nieuwe velden
    'imsi'                => column_exists($pdo,'sims','imsi'),
    'pin'                 => column_exists($pdo,'sims','pin'),
    'puk'                 => column_exists($pdo,'sims','puk'),
];

/* ===== sim laden ===== */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<h3>Simkaart bewerken</h3><div class="alert alert-danger mt-3">Geen geldige ID opgegeven.</div>';
    return;
}

$row = null; $err = '';
try {
    $st = $pdo->prepare("SELECT * FROM sims WHERE id = :id LIMIT 1");
    $st->execute([':id'=>$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { $err = 'Simkaart niet gevonden.'; }
} catch (Throwable $e) {
    $err = 'Kon gegevens niet laden: ' . e($e->getMessage());
}

$alerts = [];
function add_alert(array &$alerts, string $type, string $msg){ $alerts[] = ['type'=>$type, 'msg'=>$msg]; }

if (!$row) {
    echo '<h3>Simkaart bewerken</h3>';
    if ($err) echo '<div class="alert alert-danger mt-3">'.e($err).'</div>';
    echo '<a class="btn btn-outline-secondary" href="index.php?route=sims_list">Terug naar lijst</a>';
    return;
}

/* ===== POST verwerken ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch(Throwable $e) { add_alert($alerts,'danger','Ongeldige sessie (CSRF).'); }

    $iccid = trim((string)($_POST['iccid'] ?? ($row['iccid'] ?? '')));
    $label = trim((string)($_POST['label'] ?? ($row['label'] ?? '')));
    $imsi  = trim((string)($_POST['imsi']  ?? ($row['imsi']  ?? '')));
    $pin   = trim((string)($_POST['pin']   ?? ($row['pin']   ?? '')));
    $puk   = trim((string)($_POST['puk']   ?? ($row['puk']   ?? '')));

    $status = (string)($_POST['status'] ?? ($row['status'] ?? ''));
    $retired = isset($_POST['retired']) ? 1 : (int)($row['retired'] ?? 0);

    if ($has['iccid'] && $iccid === '') {
        add_alert($alerts,'danger','ICCID is verplicht.');
    }

    if (!$alerts) {
        try {
            $sets=[]; $bind=[':id'=>$id];

            if ($has['iccid']) { $sets[]='`iccid` = :iccid'; $bind[':iccid']=$iccid; }
            if ($has['label']) { $sets[]='`label` = :label'; $bind[':label']=$label; }
            if ($has['imsi'])  { $sets[]='`imsi` = :imsi';   $bind[':imsi']=($imsi!==''?$imsi:null); }
            if ($has['pin'])   { $sets[]='`pin`  = :pin';    $bind[':pin'] =($pin !==''?$pin :null); }
            if ($has['puk'])   { $sets[]='`puk`  = :puk';    $bind[':puk'] =($puk !==''?$puk :null); }

            if ($has['status'])  { $sets[]='`status` = :status'; $bind[':status']=$status!==''?$status:'inactive'; }
            if ($has['retired']) { $sets[]='`retired` = :retired'; $bind[':retired']=(int)$retired; }

            if (!$sets) throw new RuntimeException('Geen bewerkbare velden gevonden.');

            $sql = "UPDATE sims SET ".implode(', ',$sets)." WHERE id = :id";
            $st = $pdo->prepare($sql);

            foreach ($bind as $k=>$v) {
                if (is_int($v)) $st->bindValue($k, $v, PDO::PARAM_INT);
                elseif ($v === null) $st->bindValue($k, null, PDO::PARAM_NULL);
                else $st->bindValue($k, $v, PDO::PARAM_STR);
            }
            $st->execute();

            // Herlaad laatste waarden
            $st2 = $pdo->prepare("SELECT * FROM sims WHERE id = :id LIMIT 1");
            $st2->execute([':id'=>$id]);
            $row = $st2->fetch(PDO::FETCH_ASSOC);

            add_alert($alerts,'success','Wijzigingen opgeslagen.');
        } catch (Throwable $e) {
            add_alert($alerts,'danger','Opslaan mislukt: ' . e($e->getMessage()));
        }
    }
}

/* ===== UI ===== */
?>
<h3>Simkaart bewerken</h3>

<?php foreach ($alerts as $a): ?>
  <div class="alert alert-<?= e($a['type']) ?>"><?= e($a['msg']) ?></div>
<?php endforeach; ?>

<form method="post" class="row g-3" action="index.php?route=sim_edit&id=<?= (int)$id ?>">
  <?php csrf_field(); ?>

  <div class="col-md-3">
    <label class="form-label">ID</label>
    <input type="text" class="form-control" value="<?= (int)$row['id'] ?>" disabled>
  </div>

  <?php if ($has['iccid']): ?>
    <div class="col-md-9">
      <label class="form-label">ICCID <span class="text-danger">*</span></label>
      <input type="text" name="iccid" class="form-control" value="<?= e($row['iccid'] ?? '') ?>" required>
    </div>
  <?php endif; ?>

  <?php if ($has['label']): ?>
    <div class="col-md-6">
      <label class="form-label">Label</label>
      <input type="text" name="label" class="form-control" value="<?= e($row['label'] ?? '') ?>">
    </div>
  <?php endif; ?>

  <?php if ($has['imsi']): ?>
    <div class="col-md-6">
      <label class="form-label">IMSI</label>
      <input type="text" name="imsi" class="form-control" value="<?= e($row['imsi'] ?? '') ?>">
    </div>
  <?php endif; ?>

  <?php if ($has['pin']): ?>
    <div class="col-md-6">
      <label class="form-label">PIN</label>
      <input type="text" name="pin" class="form-control" value="<?= e($row['pin'] ?? '') ?>">
    </div>
  <?php endif; ?>

  <?php if ($has['puk']): ?>
    <div class="col-md-6">
      <label class="form-label">PUK</label>
      <input type="text" name="puk" class="form-control" value="<?= e($row['puk'] ?? '') ?>">
    </div>
  <?php endif; ?>

  <?php if ($has['status']): ?>
    <div class="col-md-6">
      <label class="form-label">Status</label>
      <select name="status" class="form-select">
        <?php
          $stVal = (string)($row['status'] ?? 'inactive');
          // gangbare waarden: inactive (voorraad), assigned, retired/overruled door retired-flag
          $opts = ['inactive'=>'inactive','assigned'=>'assigned'];
          foreach ($opts as $k=>$lbl):
        ?>
          <option value="<?= e($k) ?>" <?= $stVal===$k?'selected':'' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Gebruik <em>inactive</em> voor voorraad; <em>assigned</em> voor toegewezen.</div>
    </div>
  <?php endif; ?>

  <?php if ($has['retired']): ?>
    <div class="col-md-6">
      <label class="form-label d-block">Retired</label>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="retired" id="retired"
               <?= (int)($row['retired'] ?? 0) === 1 ? 'checked' : '' ?>>
        <label class="form-check-label" for="retired">Markeer deze SIM als retired (niet meer in gebruik)</label>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-12">
    <button class="btn btn-primary">Opslaan</button>
    <a class="btn btn-outline-secondary" href="index.php?route=sims_list">Terug naar lijst</a>
  </div>
</form>

<?php
// pages/sim_add.php — simkaart(en) toevoegen (single & bulk CSV) — alleen Super-admin
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<h3>Nieuwe simkaart(en)</h3><div class="alert alert-danger mt-3">Geen toegang.</div>';
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
        $pdo = new PDO($dsn, $db['user'] ?? null, $db['pass'] ?? null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } else {
        $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
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

/* ===== kolommen dynamisch ===== */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}
$has = [
    'iccid'               => column_exists($pdo,'sims','iccid'),
    'label'               => column_exists($pdo,'sims','label'),
    'status'              => column_exists($pdo,'sims','status'),
    'assigned_to_user_id' => column_exists($pdo,'sims','assigned_to_user_id'),
    'owner_user_id'       => column_exists($pdo,'sims','owner_user_id'),
    'retired'             => column_exists($pdo,'sims','retired'),
    // nieuwe velden:
    'imsi'                => column_exists($pdo,'sims','imsi'),
    'pin'                 => column_exists($pdo,'sims','pin'),
    'puk'                 => column_exists($pdo,'sims','puk'),
];

/* ===== UI state ===== */
$tab = ($_GET['tab'] ?? 'single') === 'bulk' ? 'bulk' : 'single';

/* ===== alerts ===== */
$alerts = [];
function add_alert(array &$alerts, string $type, string $msg){ $alerts[] = ['type'=>$type,'msg'=>$msg]; }

/* ===== Enkelvoudig toevoegen ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($tab !== 'bulk')) {
    try { if (function_exists('verify_csrf')) verify_csrf(); }
    catch (Throwable $e) { add_alert($alerts,'danger','Ongeldige sessie (CSRF). Probeer opnieuw.'); }

    if (!$alerts) {
        $iccid = trim((string)($_POST['iccid'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));
        $imsi  = trim((string)($_POST['imsi']  ?? ''));
        $pin   = trim((string)($_POST['pin']   ?? ''));
        $puk   = trim((string)($_POST['puk']   ?? ''));

        if ($has['iccid'] && $iccid === '') {
            add_alert($alerts,'danger','ICCID is verplicht.');
        }

        if (!$alerts) {
            try {
                $cols = []; $vals = []; $bind = [];

                if ($has['iccid']) { $cols[]='iccid'; $vals[]=':iccid'; $bind[':iccid']=$iccid; }
                if ($has['label']) { $cols[]='label'; $vals[]=':label'; $bind[':label']=$label; }
                if ($has['status']) { $cols[]='status'; $vals[]=':status'; $bind[':status']='inactive'; }
                if ($has['retired']) { $cols[]='retired'; $vals[]='0'; }
                if ($has['assigned_to_user_id']) { $cols[]='assigned_to_user_id'; $vals[]='NULL'; }
                if ($has['owner_user_id']) { $cols[]='owner_user_id'; $vals[]=':owner'; $bind[':owner']=(int)$me['id']; }

                // nieuwe velden – alleen opnemen als kolom bestaat
                if ($has['imsi']) { $cols[]='imsi'; $vals[]=':imsi'; $bind[':imsi']=$imsi !== '' ? $imsi : null; }
                if ($has['pin'])  { $cols[]='pin';  $vals[]=':pin';  $bind[':pin'] =$pin  !== '' ? $pin  : null; }
                if ($has['puk'])  { $cols[]='puk';  $vals[]=':puk';  $bind[':puk'] =$puk  !== '' ? $puk  : null; }

                if (!$cols) throw new RuntimeException('Geen geschikte kolommen in tabel sims.');
                $sql = "INSERT INTO sims (".implode(',',array_map(fn($c)=>"`$c`",$cols)).") VALUES (".implode(',',$vals).")";
                $st = $pdo->prepare($sql);
                foreach ($bind as $k=>$v) {
                    if ($v === null) { $st->bindValue($k, null, PDO::PARAM_NULL); }
                    elseif (is_int($v)) { $st->bindValue($k, $v, PDO::PARAM_INT); }
                    else { $st->bindValue($k, $v, PDO::PARAM_STR); }
                }
                $st->execute();

                add_alert($alerts,'success','Simkaart toegevoegd.');
                $_POST = [];
            } catch (Throwable $e) {
                add_alert($alerts,'danger','Opslaan mislukt: '.$e->getMessage());
            }
        }
    }
}

/* ===== Bulk CSV ===== */
$bulkResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $tab === 'bulk') {
    try { if (function_exists('verify_csrf')) verify_csrf(); }
    catch (Throwable $e) { add_alert($alerts,'danger','Ongeldige sessie (CSRF). Probeer opnieuw.'); }

    if (!$alerts) {
        if (empty($_FILES['csv']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            add_alert($alerts,'danger','Geen bestand geüpload.');
        } else {
            $tmp = $_FILES['csv']['tmp_name'];
            $raw = file_get_contents($tmp);
            if ($raw === false) {
                add_alert($alerts,'danger','Kon bestand niet lezen.');
            } else {
                if (substr($raw,0,3) === "\xEF\xBB\xBF") $raw = substr($raw,3);
                $tmp2 = tempnam(sys_get_temp_dir(), 'sims_csv_');
                file_put_contents($tmp2, $raw);
                $fh = fopen($tmp2, 'r');
                if (!$fh) {
                    add_alert($alerts,'danger','Kon geüploade CSV niet openen.');
                } else {
                    // delimiter bepalen
                    $firstLine = '';
                    while (($l = fgets($fh)) !== false) { $l = trim($l); if ($l !== '') { $firstLine = $l; break; } }
                    fseek($fh, 0);
                    $delimiter = (substr_count($firstLine,';') > substr_count($firstLine,',')) ? ';' : ',';

                    $row1 = fgetcsv($fh, 0, $delimiter);
                    if ($row1 === false) {
                        add_alert($alerts,'danger','Lege CSV.');
                        fclose($fh); @unlink($tmp2);
                    } else {
                        $cells = array_map('trim', $row1);
                        $lc = array_map(fn($s)=>mb_strtolower($s,'UTF-8'), $cells);
                        $hasHeader = in_array('iccid',$lc,true) || in_array('label',$lc,true) || in_array('imsi',$lc,true) || in_array('pin',$lc,true) || in_array('puk',$lc,true);

                        // prepared insert klaarzetten
                        $insCols = [];
                        $valTpl  = [];
                        if ($has['iccid']) { $insCols[]='iccid'; $valTpl[]=':iccid'; }
                        if ($has['label']) { $insCols[]='label'; $valTpl[]=':label'; }
                        if ($has['status']) { $insCols[]='status'; $valTpl[]=':status'; }
                        if ($has['retired']) { $insCols[]='retired'; $valTpl[]='0'; }
                        if ($has['assigned_to_user_id']) { $insCols[]='assigned_to_user_id'; $valTpl[]='NULL'; }
                        if ($has['owner_user_id']) { $insCols[]='owner_user_id'; $valTpl[]=':owner'; }
                        // nieuwe velden
                        if ($has['imsi']) { $insCols[]='imsi'; $valTpl[]=':imsi'; }
                        if ($has['pin'])  { $insCols[]='pin';  $valTpl[]=':pin'; }
                        if ($has['puk'])  { $insCols[]='puk';  $valTpl[]=':puk'; }

                        if (!$insCols) {
                            add_alert($alerts,'danger','Tabel sims mist invoerbare kolommen.');
                            fclose($fh); @unlink($tmp2);
                        } else {
                            $sql = "INSERT INTO sims (".implode(',',array_map(fn($c)=>"`$c`",$insCols)).") VALUES (".implode(',',$valTpl).")";
                            $st = $pdo->prepare($sql);

                            if ($hasHeader) { fseek($fh, 0); fgetcsv($fh, 0, $delimiter); }

                            $maxRows = 10000;
                            $added=0; $skipped=0; $errors=[];
                            $lineNo = $hasHeader ? 2 : 1;

                            $headerMap = [];
                            if ($hasHeader) {
                                foreach ($cells as $i=>$name) {
                                    $key = mb_strtolower(trim($name),'UTF-8');
                                    $headerMap[$key] = $i;
                                }
                            }

                            while (($row = fgetcsv($fh, 0, $delimiter)) !== false && $lineNo <= $maxRows) {
                                $row = array_map('trim', $row);
                                if (count($row)===1 && $row[0]==='') { $lineNo++; continue; }

                                $iccid = $label = $imsi = $pin = $puk = '';

                                if ($hasHeader) {
                                    $iccid = isset($headerMap['iccid']) && isset($row[$headerMap['iccid']]) ? $row[$headerMap['iccid']] : ($row[0] ?? '');
                                    $label = isset($headerMap['label']) && isset($row[$headerMap['label']]) ? $row[$headerMap['label']] : ($row[1] ?? '');
                                    if ($has['imsi']) $imsi = isset($headerMap['imsi']) && isset($row[$headerMap['imsi']]) ? $row[$headerMap['imsi']] : '';
                                    if ($has['pin'])  $pin  = isset($headerMap['pin'])  && isset($row[$headerMap['pin']])  ? $row[$headerMap['pin']]  : '';
                                    if ($has['puk'])  $puk  = isset($headerMap['puk'])  && isset($row[$headerMap['puk']])  ? $row[$headerMap['puk']]  : '';
                                } else {
                                    // zonder header: [0]=iccid, [1]=label, [2]=imsi, [3]=pin, [4]=puk (optioneel)
                                    $iccid = $row[0] ?? '';
                                    $label = $row[1] ?? '';
                                    if ($has['imsi']) $imsi = $row[2] ?? '';
                                    if ($has['pin'])  $pin  = $row[3] ?? '';
                                    if ($has['puk'])  $puk  = $row[4] ?? '';
                                }

                                if ($has['iccid'] && $iccid === '') {
                                    $skipped++; $errors[] = "Regel {$lineNo}: ICCID ontbreekt.";
                                    $lineNo++; continue;
                                }

                                try {
                                    if ($has['iccid']) $st->bindValue(':iccid', $iccid, PDO::PARAM_STR);
                                    if ($has['label']) $st->bindValue(':label', $label, PDO::PARAM_STR);
                                    if ($has['status']) $st->bindValue(':status', 'inactive', PDO::PARAM_STR);
                                    if ($has['owner_user_id']) $st->bindValue(':owner', (int)$me['id'], PDO::PARAM_INT);
                                    if ($has['imsi']) $st->bindValue(':imsi', ($imsi!==''?$imsi:null), $imsi!==''?PDO::PARAM_STR:PdO::PARAM_NULL);
                                    if ($has['pin'])  $st->bindValue(':pin',  ($pin !==''?$pin :null), $pin !==''?PDO::PARAM_STR:PdO::PARAM_NULL);
                                    if ($has['puk'])  $st->bindValue(':puk',  ($puk !==''?$puk :null), $puk !==''?PDO::PARAM_STR:PdO::PARAM_NULL);

                                    $st->execute();
                                    $added++;
                                } catch (Throwable $ie) {
                                    $skipped++;
                                    $msg = $ie->getMessage();
                                    if (stripos($msg,'Duplicate') !== false || stripos($msg,'1062') !== false) {
                                        $errors[] = "Regel {$lineNo}: al bestaand (ICCID=".e($iccid).").";
                                    } else {
                                        $errors[] = "Regel {$lineNo}: fout bij invoegen: {$msg}";
                                    }
                                }
                                $lineNo++;
                            }

                            fclose($fh); @unlink($tmp2);
                            $bulkResult = ['added'=>$added,'skipped'=>$skipped,'errors'=>$errors];
                            if ($added>0)   add_alert($alerts,'success',"Toegevoegd: {$added} simkaart(en).");
                            if ($skipped>0) add_alert($alerts,'warning',"Overgeslagen: {$skipped} regel(s).");
                            if ($errors)    add_alert($alerts,'warning',"Er waren meldingen bij het verwerken. Zie onder het formulier.");
                        }
                    }
                }
            }
        }
    }
    $tab = 'bulk';
}

/* ===== UI ===== */
?>
<h3>Nieuwe simkaart(en)</h3>

<?php foreach ($alerts as $a): ?>
  <div class="alert alert-<?= e($a['type']) ?>"><?= $a['msg'] ?></div>
<?php endforeach; ?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='single'?'active':'' ?>" href="index.php?route=sim_add&tab=single">Enkelvoudig</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='bulk'?'active':'' ?>" href="index.php?route=sim_add&tab=bulk">Bulk (CSV)</a>
  </li>
</ul>

<?php if ($tab === 'single'): ?>
  <form method="post" action="index.php?route=sim_add&tab=single" class="row g-3">
    <?php csrf_field(); ?>
    <?php if ($has['iccid']): ?>
      <div class="col-md-6">
        <label class="form-label">ICCID <span class="text-danger">*</span></label>
        <input type="text" name="iccid" class="form-control" value="<?= e($_POST['iccid'] ?? '') ?>" required>
      </div>
    <?php endif; ?>
    <?php if ($has['label']): ?>
      <div class="col-md-6">
        <label class="form-label">Label (optioneel)</label>
        <input type="text" name="label" class="form-control" value="<?= e($_POST['label'] ?? '') ?>">
      </div>
    <?php endif; ?>

    <?php if ($has['imsi']): ?>
      <div class="col-md-4">
        <label class="form-label">IMSI</label>
        <input type="text" name="imsi" class="form-control" value="<?= e($_POST['imsi'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($has['pin']): ?>
      <div class="col-md-4">
        <label class="form-label">PIN</label>
        <input type="text" name="pin" class="form-control" value="<?= e($_POST['pin'] ?? '') ?>">
      </div>
    <?php endif; ?>
    <?php if ($has['puk']): ?>
      <div class="col-md-4">
        <label class="form-label">PUK</label>
        <input type="text" name="puk" class="form-control" value="<?= e($_POST['puk'] ?? '') ?>">
      </div>
    <?php endif; ?>

    <div class="col-12">
      <div class="form-text">
        Nieuwe simkaarten worden als <strong>op voorraad</strong> toegevoegd: status <code>inactive</code>, niet toegewezen en niet retired.
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Toevoegen</button>
      <a class="btn btn-outline-secondary" href="index.php?route=sims_list&status=stock">Terug naar lijst</a>
    </div>
  </form>

<?php else: /* BULK */ ?>

  <div class="card">
    <div class="card-body">
      <form method="post" action="index.php?route=sim_add&tab=bulk" enctype="multipart/form-data" class="row g-3">
        <?php csrf_field(); ?>
        <div class="col-md-8">
          <label class="form-label">CSV-bestand</label>
          <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
          <div class="form-text">
            Met of zonder header. Verwerkbare kolommen: <code>iccid</code> (verplicht), optioneel <code>label</code>, <code>imsi</code>, <code>pin</code>, <code>puk</code> (alleen als deze kolommen in de database bestaan).  
            Delimiter <code>,</code> of <code>;</code> wordt automatisch herkend. Max ~10.000 regels per upload.  
            Elke nieuwe SIM krijgt status <code>inactive</code>, is niet toegewezen en niet retired.
          </div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary">Uploaden & Importeren</button>
          <a class="btn btn-outline-secondary" href="index.php?route=sims_list&status=stock">Terug naar lijst</a>
        </div>
      </form>

      <?php if ($bulkResult): ?>
        <hr>
        <h6 class="mb-2">Resultaat</h6>
        <ul class="mb-3">
          <li>Toegevoegd: <strong><?= (int)$bulkResult['added'] ?></strong></li>
          <li>Overgeslagen: <strong><?= (int)$bulkResult['skipped'] ?></strong></li>
        </ul>
        <?php if (!empty($bulkResult['errors'])): ?>
          <details>
            <summary>Toon meldingen (<?= count($bulkResult['errors']) ?>)</summary>
            <ul class="mt-2">
              <?php foreach ($bulkResult['errors'] as $msg): ?>
                <li><?= e($msg) ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

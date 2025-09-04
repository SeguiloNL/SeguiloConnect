<?php
// pages/sim_assign.php — SIM(s) toewijzen (single + bulk) met eigen PDO, scope & dynamische kolommen
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

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
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } else {
        $host = $db['host'] ?? 'localhost';
        $name = $db['name'] ?? $db['database'] ?? '';
        $user = $db['user'] ?? $db['username'] ?? '';
        $pass = $db['pass'] ?? $db['password'] ?? '';
        $charset = $db['charset'] ?? 'utf8mb4';
        if ($name === '') throw new RuntimeException('DB-naam ontbreekt in config');
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
/** alle user-ids onder (inclusief) een manager op basis van parent_user_id */
function users_under(PDO $pdo, int $rootId): array {
    $ids = [$rootId];
    $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
    if (!$st || !$st->fetch()) return $ids;

    $queue = [$rootId];
    $seen  = [$rootId => true];
    while ($queue) {
        $chunk = array_splice($queue, 0, 100);
        $params=[]; foreach ($chunk as $i=>$v) $params['p'.$i]=(int)$v;
        $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($params)));
        $sql = "SELECT id FROM users WHERE parent_user_id IN ($ph)";
        $st2 = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $st2->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st2->execute();
        $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $cid) {
            $cid = (int)$cid;
            if (!isset($seen[$cid])) { $seen[$cid]=true; $ids[]=$cid; $queue[]=$cid; }
        }
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

/* ===== schema check ===== */
$hasOwner = column_exists($pdo, 'sims', 'owner_user_id');
if (!$hasOwner) {
    echo '<div class="alert alert-danger">Deze functie vereist de kolom <code>sims.owner_user_id</code>.</div>';
    return;
}

/* ===== ids (single of bulk) ===== */
$simIds = [];
if (isset($_GET['sim_id'])) $simIds[] = (int)$_GET['sim_id'];
if (!empty($_GET['sim_ids'])) {
    foreach (explode(',', (string)$_GET['sim_ids']) as $sid) {
        $sid = (int)trim($sid);
        if ($sid > 0) $simIds[] = $sid;
    }
}
$simIds = array_values(array_unique(array_filter($simIds)));
if (!$simIds) {
    echo '<div class="alert alert-warning">Geen SIM geselecteerd.</div>';
    echo '<a class="btn btn-secondary" href="index.php?route=sims_list">Terug naar simkaarten</a>';
    return;
}

/* ===== kolommen dynamisch bepalen ===== */
$simCols = ['id','owner_user_id'];
foreach (['iccid','imsi','msisdn','label'] as $c) {
    if (column_exists($pdo,'sims',$c)) $simCols[] = $c;
}
$simSelect = implode(', ', array_map(fn($c)=>"`$c`", $simCols));

/* ===== huidige sims laden ===== */
$in = in_named($simIds, 's');
try {
    $st = $pdo->prepare("SELECT $simSelect FROM sims WHERE id IN (".$in['ph'].") ORDER BY id");
    foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
    $st->execute();
    $sims = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>';
    return;
}
if (!$sims) {
    echo '<div class="alert alert-warning">Geen geldige SIMs gevonden.</div>';
    echo '<a class="btn btn-secondary" href="index.php?route=sims_list">Terug</a>';
    return;
}

/* ===== doelgebruikers volgens rol ===== */
$targets = [];
try {
    if ($isSuper) {
        $stU = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('reseller','sub_reseller','customer') ORDER BY FIELD(role,'reseller','sub_reseller','customer'), name");
        $targets = $stU->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($isRes) {
        $ids = users_under($pdo, (int)$me['id']);
        $inU = in_named($ids,'u');
        $sql = "SELECT id, name, role FROM users WHERE role IN ('sub_reseller','customer') AND id IN (".$inU['ph'].") ORDER BY FIELD(role,'sub_reseller','customer'), name";
        $stU = $pdo->prepare($sql);
        foreach ($inU['params'] as $k=>$v) $stU->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $stU->execute();
        $targets = $stU->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($isSubRes) {
        $ids = users_under($pdo, (int)$me['id']);
        $inU = in_named($ids,'u');
        $sql = "SELECT id, name, role FROM users WHERE role = 'customer' AND id IN (".$inU['ph'].") ORDER BY name";
        $stU = $pdo->prepare($sql);
        foreach ($inU['params'] as $k=>$v) $stU->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $stU->execute();
        $targets = $stU->fetchAll(PDO::FETCH_ASSOC);
    } else {
        echo '<div class="alert alert-danger">Je rol ondersteunt het toewijzen van SIMs niet.</div>';
        return;
    }
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Doelgebruikers laden mislukt: '.e($e->getMessage()).'</div>';
    return;
}

/* ===== POST: toewijzen ===== */
$messages = [];
$errors   = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) { $errors[] = 'Ongeldige sessie (CSRF). Probeer opnieuw.'; }

    $targetId = (int)($_POST['target_user_id'] ?? 0);
    if ($targetId <= 0) {
        $errors[] = 'Kies een doelgebruiker.';
    } else {
        $allowedTarget = false; $targetRow = null;
        foreach ($targets as $t) {
            if ((int)$t['id'] === $targetId) { $allowedTarget = true; $targetRow = $t; break; }
        }
        if (!$allowedTarget) $errors[] = 'Je mag niet aan deze gebruiker toewijzen.';
    }

    if (!$errors && !$isSuper) {
        $ids = users_under($pdo, (int)$me['id']);
        $allowedOwners = array_flip($ids);
        foreach ($sims as $s) {
            $owner = (int)($s['owner_user_id'] ?? 0);
            if (!isset($allowedOwners[$owner]) && $owner !== (int)$me['id']) {
                $errors[] = 'SIM ID '.(int)$s['id'].' valt niet binnen jouw beheer.';
                break;
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $inUpd = in_named($simIds, 's');
            // updated_at alleen gebruiken als kolom bestaat
            $hasUpdated = column_exists($pdo,'sims','updated_at');
            $sql = "UPDATE sims SET owner_user_id = :target".($hasUpdated? ", updated_at = NOW()":"")." WHERE id IN (".$inUpd['ph'].")";
            $up  = $pdo->prepare($sql);
            $up->bindValue(':target', $targetId, PDO::PARAM_INT);
            foreach ($inUpd['params'] as $k=>$v) $up->bindValue(':'.$k,$v,PDO::PARAM_INT);
            $up->execute();
            $pdo->commit();

            $messages[] = count($simIds).' SIM(s) toegewezen aan '.e($targetRow['name']).' ('.e(role_label($targetRow['role'])).').';
            header('Location: index.php?route=sims_list&msg='.rawurlencode($messages[0]));
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Toewijzen mislukt: '.$e->getMessage();
        }
    }
}

/* ===== UI ===== */
?>
<h3>SIM(s) toewijzen</h3>

<?php if ($messages): ?>
  <div class="alert alert-success">
    <?php foreach ($messages as $m): ?><div><?= e($m) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body">
    <h5 class="card-title">Geselecteerde SIMs</h5>
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <?php if (in_array('iccid', $simCols, true)): ?><th>ICCID</th><?php endif; ?>
            <?php if (in_array('imsi',  $simCols, true)): ?><th>IMSI</th><?php endif; ?>
            <?php if (in_array('msisdn',$simCols, true)): ?><th>MSISDN</th><?php endif; ?>
            <?php if (in_array('label', $simCols, true)): ?><th>Label</th><?php endif; ?>
            <th>Huidige eigenaar</th>
          </tr>
        </thead>
        <tbody>
          <?php
          // owner namen
          $ownerIds = array_values(array_unique(array_map(fn($r)=>(int)($r['owner_user_id'] ?? 0), $sims)));
          $ownerNames = [];
          if ($ownerIds) {
              $inO = in_named($ownerIds, 'o');
              $stO = $pdo->prepare("SELECT id, name, role FROM users WHERE id IN (".$inO['ph'].")");
              foreach ($inO['params'] as $k=>$v) $stO->bindValue(':'.$k,$v,PDO::PARAM_INT);
              $stO->execute();
              foreach ($stO->fetchAll(PDO::FETCH_ASSOC) as $ou) {
                  $ownerNames[(int)$ou['id']] = $ou['name'].' ('.role_label($ou['role']).')';
              }
          }
          ?>
          <?php foreach ($sims as $s): ?>
            <tr>
              <td><?= (int)$s['id'] ?></td>
              <?php if (in_array('iccid', $simCols, true)): ?><td><?= e($s['iccid'] ?? '') ?></td><?php endif; ?>
              <?php if (in_array('imsi',  $simCols, true)): ?><td><?= e($s['imsi']  ?? '') ?></td><?php endif; ?>
              <?php if (in_array('msisdn',$simCols, true)): ?><td><?= e($s['msisdn']?? '') ?></td><?php endif; ?>
              <?php if (in_array('label', $simCols, true)): ?><td><?= e($s['label'] ?? '') ?></td><?php endif; ?>
              <td><?= isset($ownerNames[(int)($s['owner_user_id'] ?? 0)]) ? e($ownerNames[(int)$s['owner_user_id']]) : '<span class="text-muted">Voorraad/Super</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<form method="post" action="index.php?route=sim_assign&<?= $simIds ? 'sim_ids='.implode(',', array_map('intval',$simIds)) : '' ?>">
  <?php if (function_exists('csrf_field')) csrf_field(); ?>
  <div class="card">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label">Toewijzen aan</label>
        <select name="target_user_id" class="form-select" required>
          <option value="">— kies —</option>
          <?php foreach ($targets as $t): ?>
            <option value="<?= (int)$t['id'] ?>">
              <?= e($t['name']) ?> (<?= e(role_label($t['role'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <?php if ($isRes || $isSubRes): ?>
          <div class="form-text">Je kunt alleen toewijzen binnen je eigen klanten/sub-resellers.</div>
        <?php endif; ?>
        <?php if ($isSuper): ?>
          <div class="form-text">Super-admin: toewijzen aan Reseller, Sub-reseller of Eindklant.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-primary">Opslaan</button>
      <a class="btn btn-outline-secondary" href="index.php?route=sims_list">Annuleren</a>
    </div>
  </div>
</form>
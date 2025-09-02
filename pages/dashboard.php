<?php
// pages/dashboard.php — robuust dashboard met eigen PDO-bootstrap en scope
// nog een test
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$u = auth_user();
if (!$u) {
    header('Location: index.php?route=login');
    exit;
}

$role = $u['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes    = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);
$isMgr    = ($isSuper || $isRes || $isSubRes);
$isCust   = !$isMgr;

/* ====== ZORG VOOR EEN WERKENDE PDO ====== */
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

/* ====== HELPERS ====== */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}

/** alle user-ids onder (inclusief) een manager op basis van parent_user_id */
function users_under(PDO $pdo, int $rootId): array {
    $ids = [$rootId];
    // als parent_user_id niet bestaat: alleen zichzelf
    $st = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'parent_user_id'");
    if (!$st || !$st->fetch()) return $ids;

    $queue = [$rootId];
    $seen  = [$rootId => true];
    while ($queue) {
        $chunk = array_splice($queue, 0, 100);
        // named IN-lijst maken
        $params=[]; foreach ($chunk as $i=>$v) $params['p'.$i]=(int)$v;
        $ph = implode(',', array_map(fn($k)=>':'.$k, array_keys($params)));
        $sql = "SELECT id FROM users WHERE parent_user_id IN ($ph)";
        $st2 = $pdo->prepare($sql);
        foreach ($params as $k=>$v) $st2->bindValue(':'.$k, $v, PDO::PARAM_INT);
        $st2->execute();
        $rows = $st2->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $cid) {
            $cid = (int)$cid;
            if (!isset($seen[$cid])) {
                $seen[$cid] = true;
                $ids[] = $cid;
                $queue[] = $cid;
            }
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

/* ====== TELWAARDEN BEREKENEN ====== */
$err = '';
$cards = [
    'active_sims'   => 0,
    'stock_sims'    => 0,
    'awaiting'      => 0,
    'active_customers' => 0,
];

try {
    $hasIsRetired = column_exists($pdo, 'sims', 'is_retired');   // optioneel
    $hasOwner     = column_exists($pdo, 'sims', 'owner_user_id');

    // scope-ids
    if ($isSuper) {
        $scopeIds = []; // niet nodig voor super
    } elseif ($isRes || $isSubRes) {
        $scopeIds = users_under($pdo, (int)$u['id']);
    } else {
        $scopeIds = [(int)$u['id']];
    }

    /* ---- Actieve SIMs ----
       Def.: sim toegewezen aan eindklant + er bestaat order met status 'completed'
    */
    if ($isCust) {
        // eindklant: tel eigen sim + order completed
        $sql = "
           SELECT COUNT(DISTINCT s.id)
           FROM sims s
           JOIN users cu ON cu.id = s.owner_user_id AND cu.role = 'customer'
           JOIN orders o ON o.sim_id = s.id AND o.status = 'completed'
           WHERE s.owner_user_id = :me
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':me'=>(int)$u['id']]);
        $cards['active_sims'] = (int)$st->fetchColumn();
    } elseif ($isSuper) {
        $sql = "
           SELECT COUNT(DISTINCT s.id)
           FROM sims s
           JOIN users cu ON cu.id = s.owner_user_id AND cu.role = 'customer'
           JOIN orders o ON o.sim_id = s.id AND o.status = 'completed'
        ";
        $cards['active_sims'] = (int)$pdo->query($sql)->fetchColumn();
    } else {
        // reseller/sub: klanten in scope
        $in = in_named($scopeIds,'u');
        $sql = "
           SELECT COUNT(DISTINCT s.id)
           FROM sims s
           JOIN users cu ON cu.id = s.owner_user_id AND cu.role = 'customer' AND cu.id IN (".$in['ph'].")
           JOIN orders o ON o.sim_id = s.id AND o.status = 'completed'
        ";
        $st = $pdo->prepare($sql);
        foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st->execute();
        $cards['active_sims'] = (int)$st->fetchColumn();
    }

    /* ---- SIMs op voorraad ----
       Def.: niet retired (indien kolom bestaat) + GEEN order met status in ('concept','awaiting_activation','completed')
       + binnen scope qua eigenaar (voor niet-super).
    */
    $statusBlock = "'concept','awaiting_activation','completed'";
    $whereNotRetired = $hasIsRetired ? " AND s.is_retired = 0 " : "";

    if ($isSuper) {
        $sql = "
         SELECT COUNT(*)
         FROM sims s
         WHERE 1=1
           {$whereNotRetired}
           AND NOT EXISTS (
             SELECT 1 FROM orders o WHERE o.sim_id = s.id AND o.status IN ($statusBlock)
           )
        ";
        $cards['stock_sims'] = (int)$pdo->query($sql)->fetchColumn();
    } else {
        // alleen sims waarvan de eigenaar in onze keten zit (indien owner_user_id bestaat)
        if ($hasOwner) {
            $in = in_named($scopeIds,'o');
            $sql = "
             SELECT COUNT(*)
             FROM sims s
             WHERE s.owner_user_id IN (".$in['ph'].")
               {$whereNotRetired}
               AND NOT EXISTS (
                 SELECT 1 FROM orders o WHERE o.sim_id = s.id AND o.status IN ($statusBlock)
               )
            ";
            $st = $pdo->prepare($sql);
            foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
            $st->execute();
            $cards['stock_sims'] = (int)$st->fetchColumn();
        } else {
            // geen owner kolom; tel dan globaal niet-retired en niet in gebruik
            $sql = "
             SELECT COUNT(*)
             FROM sims s
             WHERE 1=1
               {$whereNotRetired}
               AND NOT EXISTS (
                 SELECT 1 FROM orders o WHERE o.sim_id = s.id AND o.status IN ($statusBlock)
               )
            ";
            $cards['stock_sims'] = (int)$pdo->query($sql)->fetchColumn();
        }
    }

    /* ---- Wachten op activatie (orders) ---- */
    if ($isCust) {
        // eindklant: orders op jouw id als klant (detecteer klantkolom)
        $custCol = 'customer_user_id';
        foreach (['customer_user_id','customer_id','end_customer_id','user_id'] as $c) {
            if (column_exists($pdo,'orders',$c)) { $custCol = $c; break; }
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='awaiting_activation' AND `$custCol` = :me");
        $st->execute([':me'=>(int)$u['id']]);
        $cards['awaiting'] = (int)$st->fetchColumn();
    } elseif ($isSuper) {
        $cards['awaiting'] = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='awaiting_activation'")->fetchColumn();
    } else {
        // reseller/sub: simpele regel — sinds we created_by_user_id gebruiken
        $hasCreatedBy = column_exists($pdo,'orders','created_by_user_id');
        if ($hasCreatedBy) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='awaiting_activation' AND created_by_user_id = :me");
            $st->execute([':me'=>(int)$u['id']]);
            $cards['awaiting'] = (int)$st->fetchColumn();
        } else {
            // fallback op klant-scope als created_by_user_id nog niet bestaat
            $in = in_named($scopeIds,'c');
            $custCol = 'customer_user_id';
            foreach (['customer_user_id','customer_id','end_customer_id','user_id'] as $c) {
                if (column_exists($pdo,'orders',$c)) { $custCol = $c; break; }
            }
            $sql = "SELECT COUNT(*) FROM orders WHERE status='awaiting_activation' AND `$custCol` IN (".$in['ph'].")";
            $st = $pdo->prepare($sql);
            foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
            $st->execute();
            $cards['awaiting'] = (int)$st->fetchColumn();
        }
    }

    /* ---- Actieve klanten ---- */
    if ($isCust) {
        $cards['active_customers'] = 1; // jijzelf
    } elseif ($isSuper) {
        $cards['active_customers'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1")->fetchColumn();
    } else {
        $in = in_named($scopeIds,'u');
        $sql = "SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1 AND id IN (".$in['ph'].")";
        $st = $pdo->prepare($sql);
        foreach ($in['params'] as $k=>$v) $st->bindValue(':'.$k,$v,PDO::PARAM_INT);
        $st->execute();
        $cards['active_customers'] = (int)$st->fetchColumn();
    }

} catch (Throwable $e) {
    $err = "Laden mislukt: " . $e->getMessage();
}

?>
<h3>Dashboard</h3>

<?php if ($err): ?>
  <div class="alert alert-danger"><?= e($err) ?></div>
<?php endif; ?>

<?php if ($isCust): ?>
  <div class="row g-3">
    <div class="col-md-6 col-lg-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['active_sims'] ?></div>
          <div class="text-muted">Actieve SIMs</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=sims_list">Bekijk SIMs</a>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['awaiting'] ?></div>
          <div class="text-muted">Wachten op activatie</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=orders_list&status=awaiting_activation">Bekijk bestellingen</a>
        </div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-md-6 col-lg-3">
      <div class="card text-center border-success">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['active_sims'] ?></div>
          <div class="text-muted">Actieve SIMs</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=sims_list&status=active">Naar SIMs</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['stock_sims'] ?></div>
          <div class="text-muted">SIMs op voorraad</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=sims_list&status=stock">Naar voorraad</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['awaiting'] ?></div>
          <div class="text-muted">Wachten op activatie</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=orders_list&status=awaiting_activation">Naar bestellingen</a>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-lg-3">
      <div class="card text-center">
        <div class="card-body">
          <div class="h1"><?= (int)$cards['active_customers'] ?></div>
          <div class="text-muted">Actieve klanten</div>
        </div>
        <div class="card-footer">
          <a class="stretched-link" href="index.php?route=users_list&role=customer&is_active=1">Naar klanten</a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
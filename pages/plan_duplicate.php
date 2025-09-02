<?php
// pages/plan_duplicate.php — kopieert een abonnement (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

// Alleen Super-admin
$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-3">Geen toegang.</div>';
    exit;
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

/* ===== plan-id ophalen ===== */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?route=plans_list&error='.rawurlencode('Ongeldig of ontbrekend ID.'));
    exit;
}

/* ===== bron plan laden (dynamische kolommen) ===== */
try {
    // Haal alle kolommen van de tabel op
    $colsStmt = $pdo->query("SHOW COLUMNS FROM `plans`");
    $allCols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$allCols) {
        header('Location: index.php?route=plans_list&error='.rawurlencode('Kan plans-schema niet lezen.'));
        exit;
    }

    // Selecteer alles van het bron-record
    $selectCols = implode(', ', array_map(fn($c)=>'`'.$c.'`', $allCols));
    $st = $pdo->prepare("SELECT $selectCols FROM `plans` WHERE `id` = :id LIMIT 1");
    $st->execute([':id'=>$id]);
    $src = $st->fetch(PDO::FETCH_ASSOC);
    if (!$src) {
        header('Location: index.php?route=plans_list&error='.rawurlencode('Abonnement niet gevonden.'));
        exit;
    }

    // Kolommen die we NIET kopiëren
    $skip = ['id','created_at','updated_at'];
    // Bouw doelkolommen & waarden
    $destCols = [];
    $bind = [];

    foreach ($allCols as $c) {
        if (in_array($c, $skip, true)) continue;
        $destCols[] = $c;
        $bind[':'.$c] = $src[$c] ?? null;
    }

    // Naam aanpassen: " (kopie)", en numeriek ophogen als nodig
    if (in_array('name', $destCols, true)) {
        $baseName = (string)$src['name'];
        $newName = $baseName.' (kopie)';

        // Zorg voor unieke naam
        $check = $pdo->prepare("SELECT COUNT(*) FROM `plans` WHERE `name` = :n");
        $tryName = $newName;
        $i = 2;
        while (true) {
            $check->execute([':n'=>$tryName]);
            $cnt = (int)$check->fetchColumn();
            if ($cnt === 0) break;
            $tryName = $baseName.' (kopie '.$i.')';
            $i++;
        }
        $bind[':name'] = $tryName;
    }

    // is_active laten staan zoals bron; als kolom niet bestaat, niets doen
    // created_at en updated_at (als aanwezig) op NOW()
    $vals = [];
    foreach ($destCols as $c) {
        $vals[] = ':'.$c;
    }
    if (in_array('created_at', $allCols, true)) {
        $destCols[] = 'created_at';
        $vals[] = 'NOW()';
    }
    if (in_array('updated_at', $allCols, true)) {
        $destCols[] = 'updated_at';
        $vals[] = 'NOW()';
    }

    // Insert uitvoeren
    $sql = "INSERT INTO `plans` (".implode(',', array_map(fn($c)=>'`'.$c.'`', $destCols)).") VALUES (".implode(',', $vals).")";
    $ins = $pdo->prepare($sql);
    foreach ($bind as $k=>$v) {
        // ints als int binden
        if (is_int($v)) {
            $ins->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $ins->bindValue($k, $v);
        }
    }
    $ins->execute();
    $newId = (int)$pdo->lastInsertId();

    flash_set('success', 'Abonnement gedupliceerd.');
    header('Location: index.php?route=plan_edit&id='.$newId);
    exit;

} catch (Throwable $e) {
    header('Location: index.php?route=plans_list&error='.rawurlencode('Dupliceren mislukt: '.$e->getMessage()));
    exit;
}
<?php
// pages/sim_bulk_delete.php — bulk verwijderen van simkaarten (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
    header('Location: index.php?route=sims_list&error=' . rawurlencode('Geen toestemming.'));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php?route=sims_list&error=' . rawurlencode('Ongeldige aanroep.'));
    exit;
}
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
    header('Location: index.php?route=sims_list&error=' . rawurlencode('Ongeldige sessie (CSRF). Probeer opnieuw.'));
    exit;
}

// PDO
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
    foreach ($candidates as $f) { if (is_file($f)) { require_once $f; if ($GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; } }
    $cfg = app_config(); $db=$cfg['db']??[]; $dsn=$db['dsn']??null;
    if ($dsn) {
        $pdo = new PDO($dsn, $db['user']??null, $db['pass']??null, [
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

// helpers
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}

// IDs ophalen (ids[] of ids_csv)
$ids = array_map('intval', $_POST['ids'] ?? []);
if (!$ids) {
    $csv = trim((string)($_POST['ids_csv'] ?? ''));
    if ($csv !== '') {
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
    }
}
$ids = array_values(array_unique(array_filter($ids)));

if (!$ids) {
    header('Location: index.php?route=sims_list&error=' . rawurlencode('Geen simkaarten geselecteerd.'));
    exit;
}

// Veiligheid: verwijder alleen sims zonder orders-koppeling (als die kolom bestaat)
try {
    // Als er een orders.sim_id bestaat, verwijder alleen die die niet gebruikt worden
    $protected = column_exists($pdo, 'orders', 'sim_id');

    $pdo->beginTransaction();

    if ($protected) {
        // Filter ids die niet in orders zitten
        $inQ = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT s.id
                              FROM sims s
                         LEFT JOIN orders o ON o.sim_id = s.id
                             WHERE s.id IN ($inQ)
                               AND o.sim_id IS NULL");
        $q->execute($ids);
        $deletable = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));

        if (!$deletable) {
            $pdo->rollBack();
            header('Location: index.php?route=sims_list&error=' . rawurlencode('Geen van de geselecteerde simkaarten kan worden verwijderd (gebruikt in orders).'));
            exit;
        }

        $inD = implode(',', array_fill(0, count($deletable), '?'));
        $st = $pdo->prepare("DELETE FROM sims WHERE id IN ($inD)");
        $st->execute($deletable);

        $pdo->commit();
        header('Location: index.php?route=sims_list&msg=' . rawurlencode('Verwijderd: ' . count($deletable) . ' simkaart(en).'));
        exit;
    } else {
        // Geen koppeling bekend → verwijder geselecteerde direct
        $inD = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("DELETE FROM sims WHERE id IN ($inD)");
        $st->execute($ids);
        $pdo->commit();
        header('Location: index.php?route=sims_list&msg=' . rawurlencode('Verwijderd: ' . count($ids) . ' simkaart(en).'));
        exit;
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch(Throwable $e2) {} }
    header('Location: index.php?route=sims_list&error=' . rawurlencode('Verwijderen mislukt: '.$e->getMessage()));
    exit;
}
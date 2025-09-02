<?php
// pages/plan_delete.php — verwijdert een abonnement (alleen Super-admin)
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

app_session_start();
$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

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

/* ===== alleen POST met geldige CSRF ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo '<div class="alert alert-warning m-3">Ongeldige methode. Gebruik de verwijder-knop in de lijst.</div>';
    echo '<p class="m-3"><a class="btn btn-secondary" href="index.php?route=plans_list">Terug</a></p>';
    exit;
}
try {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo '<div class="alert alert-danger m-3">Ongeldige sessie (CSRF). Probeer opnieuw.</div>';
    echo '<p class="m-3"><a class="btn btn-secondary" href="index.php?route=plans_list">Terug</a></p>';
    exit;
}

/* ===== plan-id ophalen ===== */
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger m-3">Ongeldig of ontbrekend ID.</div>';
    echo '<p class="m-3"><a class="btn btn-secondary" href="index.php?route=plans_list">Terug</a></p>';
    exit;
}

/* ===== verwijderen ===== */
try {
    // Optioneel: check of plan bestaat
    $chk = $pdo->prepare("SELECT id FROM plans WHERE id = :id LIMIT 1");
    $chk->execute([':id'=>$id]);
    if (!$chk->fetch()) {
        // Al weg? Prima, terug naar lijst
        flash_set('success','Abonnement was al verwijderd.');
        header('Location: index.php?route=plans_list');
        exit;
    }

    // Probeer hard delete
    $del = $pdo->prepare("DELETE FROM plans WHERE id = :id LIMIT 1");
    $del->execute([':id'=>$id]);

    flash_set('success','Abonnement verwijderd.');
    header('Location: index.php?route=plans_list');
    exit;

} catch (Throwable $e) {
    // Mogelijk FK-constraint (orders verwijzen naar plan). Toon nette melding.
    http_response_code(409);
    echo '<div class="alert alert-danger m-3">Verwijderen mislukt: '.e($e->getMessage()).'</div>';
    echo '<p class="m-3">Tip: als dit abonnement gekoppeld is aan bestaande bestellingen, kun je het ook <strong>inactief</strong> maken via “Bewerken”.</p>';
    echo '<p class="m-3"><a class="btn btn-secondary" href="index.php?route=plans_list">Terug</a></p>';
    exit;
}
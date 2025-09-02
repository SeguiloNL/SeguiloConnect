<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();

// Alleen POST toegestaan
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php?route=login&error=' . rawurlencode('Ongeldige aanroep.'));
    exit;
}

// CSRF check
try {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
} catch (Throwable $e) {
    header('Location: index.php?route=login&error=' . rawurlencode('Sessie verlopen. Probeer opnieuw.'));
    exit;
}

// Input ophalen
$email = trim((string)($_POST['email'] ?? ''));
$pass  = (string)($_POST['password'] ?? ($_POST['pass'] ?? ''));

if ($email === '' || $pass === '') {
    header('Location: index.php?route=login&error=' . rawurlencode('Vul e-mail en wachtwoord in.'));
    exit;
}

// --- DB connectie ---
function get_pdo_login(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    $cfg = app_config();
    $db  = $cfg['db'] ?? [];

    if (!empty($db['dsn'])) {
        $pdo = new PDO(
            $db['dsn'],
            $db['user'] ?? null,
            $db['pass'] ?? null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $GLOBALS['pdo'] = $pdo;
    }

    $host    = $db['host'] ?? 'localhost';
    $name    = $db['name'] ?? ($db['database'] ?? '');
    $user    = $db['user'] ?? ($db['username'] ?? '');
    $pwd     = $db['pass'] ?? ($db['password'] ?? '');
    $charset = $db['charset'] ?? 'utf8mb4';

    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset={$charset}",
        $user,
        $pwd,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $GLOBALS['pdo'] = $pdo;
}

$pdo = get_pdo_login();

// Check of kolommen bestaan
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q  = $pdo->quote($column);
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

$hasHash = column_exists($pdo, 'users', 'password_hash');
$hasPass = column_exists($pdo, 'users', 'password');

// User ophalen
$cols = 'id, name, email, role, is_active';
if ($hasHash) $cols .= ', password_hash';
if ($hasPass) $cols .= ', password';

$st = $pdo->prepare("SELECT $cols FROM users WHERE email = ? LIMIT 1");
$st->execute([$email]);
$user = $st->fetch();

if (!$user) {
    header('Location: index.php?route=login&error=' . rawurlencode('Onjuiste inloggegevens.'));
    exit;
}

if (isset($user['is_active']) && (int)$user['is_active'] === 0) {
    header('Location: index.php?route=login&error=' . rawurlencode('Account is gedeactiveerd.'));
    exit;
}

// Wachtwoord controleren
$ok = false;

// 1) Met password_hash
if ($hasHash && !empty($user['password_hash'])) {
    $ok = password_verify($pass, $user['password_hash']);
}

// 2) Legacy fallback
if (!$ok && $hasPass && !empty($user['password'])) {
    if (strlen($user['password']) === 32 && ctype_xdigit($user['password'])) {
        $ok = (md5($pass) === strtolower($user['password']));
    } else {
        $ok = hash_equals((string)$user['password'], $pass);
    }

    // Upgrade naar password_hash
    if ($ok && $hasHash) {
        try {
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$newHash, (int)$user['id']]);
        } catch (Throwable $e) {
            // negeren
        }
    }
}

if (!$ok) {
    header('Location: index.php?route=login&error=' . rawurlencode('Onjuiste inloggegevens.'));
    exit;
}

// Login is OK → sessie zetten
unset($_SESSION['impersonator_id'], $_SESSION['auth_user'], $_SESSION['cached_user']);
$_SESSION['user_id'] = (int)$user['id'];

if (function_exists('session_regenerate_id')) {
    @session_regenerate_id(true);
}

// Door naar dashboard
header('Location: index.php?route=dashboard');
exit;
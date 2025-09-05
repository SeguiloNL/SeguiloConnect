<?php
declare(strict_types=1);

/**
 * SeguiloConnect — index.php (productieklaar)
 * - Start sessie vroeg (app_session_start)
 * - Vroege POST/handlers (do_login/logout/impersonate)
 * - Whitelist router met heldere fouten in debug
 * - Header + page + footer in vaste volgorde
 */

/* ====== DEBUG-INSTELLING ====== */
// Zet op true tijdens testen; op false in productie.
if (!defined('SC_DEBUG')) define('SC_DEBUG', false);

if (SC_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
if (!is_dir(__DIR__ . '/storage/logs')) { @mkdir(__DIR__ . '/storage/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');

if (SC_DEBUG) {
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            echo "<pre style='padding:12px;background:#111;color:#eee'>FATAL: {$e['message']} in {$e['file']}:{$e['line']}</pre>";
        }
    });
}

/* ====== BOOTSTRAP ====== */
require_once __DIR__ . '/helpers.php';
if (function_exists('app_session_start')) app_session_start();  // <<< belangrijk

$route = $_GET['route'] ?? null;

/* ====== VROEGE ROUTES (POST/acties) ====== */
$early = [
    'do_login'           => __DIR__ . '/pages/do_login.php',
    'logout'             => __DIR__ . '/pages/logout.php',
    'impersonate_start'  => __DIR__ . '/pages/impersonate_start.php',
    'impersonate_stop'   => __DIR__ . '/pages/impersonate_stop.php',
];

if ($route && isset($early[$route])) {
    require $early[$route];
    exit;
}

/* ====== ROUTE-WHITELIST ====== */
$routes = [
    // Auth
    'login'              => 'pages/login.php',

    // Dashboard
    'dashboard'          => 'pages/dashboard.php',

    // Users
    'users_list'         => 'pages/users_list.php',
    'user_add'           => 'pages/user_add.php',
    'user_edit'          => 'pages/user_edit.php',
    'user_delete'        => 'pages/user_delete.php',

    // SIMs
    'sims_list'          => 'pages/sims_list.php',
    'sim_add'            => 'pages/sim_add.php',
    'sim_edit'           => 'pages/sim_edit.php',
    'sim_delete'         => 'pages/sim_delete.php',
    'sim_retire'         => 'pages/sim_retire.php',
    'sim_assign'         => 'pages/sim_assign.php',
    'sim_bulk_action'    => 'pages/sim_bulk_action.php',
    'sim_bulk_assign'    => 'pages/sim_bulk_assign.php',
    'sim_bulk_delete'    => 'pages/sim_bulk_delete.php',

    // Orders
    'order_add'          => 'pages/order_add.php',
    'order_edit'         => 'pages/order_edit.php',
    'order_delete'       => 'pages/order_delete.php',
    'order_status'       => 'pages/order_status.php',
    'order_submit'       => 'pages/order_submit.php',

    // Plans / migratie
    'plans_list'         => 'pages/plans_list.php',
    'migrate_plans'      => 'pages/migrate_plans.php',

    // System / admin
    'system_users'       => 'pages/system_users.php',
    'system_admin'       => 'pages/system_admin.php',

    // (optioneel) debug
    // '_whoami'          => 'pages/_whoami.php',
    // '_phpinfo'         => 'pages/_phpinfo.php',
];

/* ====== RENDER ====== */
include __DIR__ . '/views/header.php';

try {
    $user = function_exists('auth_user') ? auth_user() : null;

    if ($route && isset($routes[$route])) {
        $file = __DIR__ . '/' . $routes[$route];
    } else {
        // default: ingelogd → dashboard, anders → login
        $file = __DIR__ . '/' . ($user ? 'pages/dashboard.php' : 'pages/login.php');
    }

    if (!is_file($file)) {
        throw new RuntimeException('Route file missing: ' . $file);
    }

    require $file;

} catch (Throwable $t) {
    http_response_code(500);
    if (SC_DEBUG) {
        $msg = htmlspecialchars($t->getMessage(), ENT_QUOTES, 'UTF-8');
        $fl  = htmlspecialchars($t->getFile(), ENT_QUOTES, 'UTF-8');
        $ln  = (int)$t->getLine();
        echo "<pre style='padding:12px;background:#300;color:#fee'>CRASH: {$msg}\n{$fl}:{$ln}</pre>";
    } else {
        echo "<div class='container my-4'><div class='alert alert-danger'>Er ging iets mis. Probeer het later opnieuw.</div></div>";
    }
}

include __DIR__ . '/views/footer.php';
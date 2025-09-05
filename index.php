<?php
declare(strict_types=1);



define('SC_DEBUG', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/storage/logs')) { @mkdir(__DIR__ . '/storage/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');

set_error_handler(function ($severity,$message,$file,$line){
    if (error_reporting()) { throw new ErrorException($message, 0, $severity, $file, $line); }
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo "<pre style='padding:16px;background:#111;color:#eee'>FATAL: {$e['message']} in {$e['file']}:{$e['line']}</pre>";
    }
});

/**
 * SeguiloConnect — index.php (opgeschoond)
 * - Gebruikt auth_user() i.p.v. is_logged_in()
 * - Veilige router met whitelist
 * - Fijnere foutweergave in debug
 */

// ==== DEBUG (zet tijdelijk aan tijdens testen) ===============================
if (!defined('SC_DEBUG')) {
    // Zet op true voor lokale debug; false in productie
    define('SC_DEBUG', true);
}
if (SC_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
    if (!is_dir(__DIR__ . '/storage/logs')) {
        @mkdir(__DIR__ . '/storage/logs', 0775, true);
    }
    ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');

    // Toon ook fatals/warnings netjes op de pagina
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            http_response_code(500);
            echo "<pre style='padding:16px;background:#111;color:#eee'>FATAL: {$e['message']} in {$e['file']}:{$e['line']}</pre>";
        }
    });
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}

// ==== BOOTSTRAP ==============================================================
require_once __DIR__ . '/helpers.php'; // bevat o.a. db(), auth_user(), require_login(), url(), e()

// Bepaal route (login of dashboard als default op basis van auth)
$user  = auth_user();
$route = $_GET['route'] ?? ($user ? 'dashboard' : 'login');

// Whitelist → bestandspaden
$routes = [
    // publiek
    'login'                 => 'pages/login.php',
    'do_login'              => 'pages/do_login.php',
    'logout'                => 'pages/logout.php',

    // ingelogd
    'dashboard'             => 'pages/dashboard.php',
    'profile'               => 'pages/profile.php',
    'do_change_password'    => 'pages/do_change_password.php',

    // admin (pagina’s zelf doen require_super_admin();)
    'admin_users'           => 'admin/users.php',
    'admin_user_edit'       => 'admin/user_edit.php',
    'admin_do_user_save'    => 'admin/do_user_save.php',
    'admin_do_user_toggle'  => 'admin/do_user_toggle.php',

    // debug helpers (optioneel — alleen in debug gebruiken)
    '_whoami'               => 'pages/_whoami.php',
    '_phpinfo'              => 'pages/_phpinfo.php',
];

// ==== RENDER ================================================================
include __DIR__ . '/views/header.php';

try {
    if (!isset($routes[$route])) {
        throw new RuntimeException("Unknown route: {$route}");
    }
    $file = __DIR__ . '/' . $routes[$route];
    if (!is_file($file)) {
        throw new RuntimeException("Route file missing: {$file}");
    }

    // In debug, toon bovenin kleine hint wie er ingelogd is
    if (SC_DEBUG) {
        $hintUser = $user['email'] ?? '—';
        echo "<div style='padding:8px 12px;background:#103;color:#cfe;border-bottom:1px solid #234'>
                DEBUG: route=" . e($route) . ", user=" . e($hintUser) . "
              </div>";
    }

    require $file;

} catch (Throwable $t) {
    http_response_code(500);

    if (SC_DEBUG) {
        // Duidelijke debug-output
        $msg = e($t->getMessage());
        $file = e($t->getFile());
        $line = (int)$t->getLine();
        echo "<pre style='padding:16px;background:#221;color:#fee'>Route crash: {$msg}\n{$file}:{$line}</pre>";
    } else {
        // Productie: beknopt
        echo "<div class='container'><h2>Er ging iets mis</h2><p>Probeer het later opnieuw.</p></div>";
    }
}

include __DIR__ . '/views/footer.php';
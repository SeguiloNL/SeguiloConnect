<?php
declare(strict_types=1);

/**
 * SeguiloConnect — index.php (core-fix v2)
 * Verschil met v1: ook de HEADER en FOOTER binnen try/catch,
 * zodat fatals in header/footer zichtbaar worden.
 */

// === DEBUG (tijdelijk AAN) ===
if (!defined('SC_DEBUG')) define('SC_DEBUG', true);
if (SC_DEBUG) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  ini_set('log_errors', '1');
  if (!is_dir(__DIR__ . '/storage/logs')) { @mkdir(__DIR__ . '/storage/logs', 0775, true); }
  ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');
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

// === BOOTSTRAP ===
require_once __DIR__ . '/helpers.php';
if (is_file(__DIR__ . '/helpers_compat.php')) require_once __DIR__ . '/helpers_compat.php';
if (function_exists('app_session_start')) app_session_start();

$route = $_GET['route'] ?? null;
$early = ['do_login','logout','impersonate_start','impersonate_stop'];
if ($route && in_array($route, $early, true)) {
  require __DIR__ . '/pages/' . $route . '.php';
  exit;
}

try {
  // HEADER ook binnen try
  include __DIR__ . '/views/header.php';

  // Whitelist
  $routes = [
    'login'               => 'pages/login.php',
    'dashboard'           => 'pages/dashboard.php',
    'users_list'          => 'pages/users_list.php',
    'user_add'            => 'pages/user_add.php',
    'user_edit'           => 'pages/user_edit.php',
    'user_delete'         => 'pages/user_delete.php',
    'sims_list'           => 'pages/sims_list.php',
    'sim_add'             => 'pages/sim_add.php',
    'sim_edit'            => 'pages/sim_edit.php',
    'sim_delete'          => 'pages/sim_delete.php',
    'sim_retire'          => 'pages/sim_retire.php',
    'sim_assign'          => 'pages/sim_assign.php',
    'sim_bulk_action'     => 'pages/sim_bulk_action.php',
    'sim_bulk_assign'     => 'pages/sim_bulk_assign.php',
    'sim_bulk_delete'     => 'pages/sim_bulk_delete.php',
    'order_add'           => 'pages/order_add.php',
    'order_edit'          => 'pages/order_edit.php',
    'order_delete'        => 'pages/order_delete.php',
    'order_status'        => 'pages/order_status.php',
    'order_submit'        => 'pages/order_submit.php',
    'plans_list'          => 'pages/plans_list.php',
    'migrate_plans'       => 'pages/migrate_plans.php',
    'system_users'        => 'pages/system_users.php',
    'system_admin'        => 'pages/system_admin.php',
    '_whoami'             => 'pages/_whoami.php',
    '_phpinfo'            => 'pages/_phpinfo.php',
    '_ok'                 => 'pages/_ok.php',
    'healthcheck'         => 'pages/healthcheck.php',
  ];

  $user = function_exists('auth_user') ? auth_user() : null;
  $file = null;
  if ($route && isset($routes[$route])) {
    $file = __DIR__ . '/' . $routes[$route];
  } else {
    $file = __DIR__ . '/' . ($user ? 'pages/dashboard.php' : 'pages/login.php');
  }
  if (!is_file($file)) throw new RuntimeException('Route file missing: ' . $file);

  require $file;

  // FOOTER ook binnen try
  include __DIR__ . '/views/footer.php';

} catch (Throwable $t) {
  http_response_code(500);
  $msg = htmlspecialchars($t->getMessage(), ENT_QUOTES, 'UTF-8');
  $fl  = htmlspecialchars($t->getFile(), ENT_QUOTES, 'UTF-8');
  $ln  = (int)$t->getLine();
  echo "<pre style='padding:16px;background:#300;color:#fee'>CRASH: {$msg}
{$fl}:{$ln}</pre>";
}

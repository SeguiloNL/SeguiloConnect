<?php
declare(strict_types=1);
/**
 * SeguiloConnect — index.php (refreshed core)
 */
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

require_once __DIR__ . '/helpers.php';
app_session_start();

$route = $_GET['route'] ?? null;

$early = [
  'do_login'           => __DIR__ . '/pages/do_login.php',
  'logout'             => __DIR__ . '/pages/logout.php',
  'impersonate_start'  => __DIR__ . '/pages/impersonate_start.php',
  'impersonate_stop'   => __DIR__ . '/pages/impersonate_stop.php',
];
if ($route && isset($early[$route])) { require $early[$route]; exit; }

$routes = [
  'login'              => 'pages/login.php',
  'dashboard'          => 'pages/dashboard.php',
  'users_list'         => 'pages/users_list.php',
  'sims_list'          => 'pages/sims_list.php',
  'system_admin'       => 'pages/system_admin.php',
];

include __DIR__ . '/views/header.php';
try {
  $user = function_exists('auth_user') ? auth_user() : null;
  if ($route && isset($routes[$route])) {
    $file = __DIR__ . '/' . $routes[$route];
  } else {
    $file = __DIR__ . '/' . ($user ? 'pages/dashboard.php' : 'pages/login.php');
  }
  if (!is_file($file)) { throw new RuntimeException('Route file missing: ' . $file); }
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

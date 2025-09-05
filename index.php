<?php

require_once __DIR__ . '/helpers.php';
app_session_start();
include __DIR__ . '/views/header.php';

// PANIC MODE index.php — minimal debug loader to locate the exact crash point
// Put this in webroot as index.php (backup your original).
// It prints markers A/B/C/D so you can see where execution stops.

// Force on-screen errors
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/storage/logs')) { @mkdir(__DIR__ . '/storage/logs', 0775, true); }
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    echo "<pre style='padding:10px;background:#111;color:#eee'>FATAL: {$e['message']} in {$e['file']}:{$e['line']}</pre>";
  }
});

function mark($ch){ echo "<div style='padding:4px 8px;background:#eef;border:1px solid #99f;display:inline-block;margin:4px'>MARK {$ch}</div>"; @ob_flush(); @flush(); }

$route = $_GET['route'] ?? 'dashboard';

mark('A:before-helpers');
require_once __DIR__ . '/helpers.php';
mark('B:after-helpers');

try {
  mark('H:before-header');

  include __DIR__ . '/views/header.php';
  mark('H:after-header');

  // Map
  $map = [
    'panic_ok'   => 'pages/panic_ok.php',
    'panic_php'  => 'pages/panic_phpinfo.php',
    'login'      => 'pages/login.php',
    'dashboard'  => 'pages/dashboard.php',
  ];
  $file = $map[$route] ?? ($map['dashboard']);

  mark('P:before-page');
  require __DIR__ . '/' . $file;
  mark('P:after-page');

  mark('F:before-footer');
  include __DIR__ . '/views/footer.php';
  mark('F:after-footer');

} catch (Throwable $t) {
  echo "<pre style='padding:10px;background:#300;color:#fee'>CRASH: ".htmlspecialchars($t->getMessage(),ENT_QUOTES,'UTF-8')."\n"
     . htmlspecialchars($t->getFile(),ENT_QUOTES,'UTF-8').':'.$t->getLine()."</pre>";
}

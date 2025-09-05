<?php
require_once __DIR__ . '/../helpers.php';
if (function_exists('app_session_start')) app_session_start();
header('Content-Type: text/plain; charset=utf-8');

echo "Healthcheck\n";

// PHP basics
echo "PHP_VERSION: " . PHP_VERSION . "\n";
echo "display_errors: " . ini_get('display_errors') . "\n";

// pdo_mysql
$pdoMysqlLoaded = extension_loaded('pdo_mysql') ? 'yes' : 'NO';
echo "pdo_mysql: " . $pdoMysqlLoaded . "\n";

// DB connect
try {
  $pdo = db();
  echo "DB: OK\n";
  $q = $pdo->query("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE()");
  $row = $q->fetch(PDO::FETCH_ASSOC);
  echo "Tables: " . ($row['cnt'] ?? '?') . "\n";

  // users count
  $q = $pdo->query("SELECT COUNT(*) as users FROM users");
  $u = $q->fetch(PDO::FETCH_ASSOC);
  echo "users: " . ($u['users'] ?? '?') . "\n";
} catch (Throwable $e) {
  echo "DB ERROR: " . $e->getMessage() . "\n";
}

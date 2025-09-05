<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';
app_session_start();

echo "<pre style='white-space:pre-wrap'>";
echo "HOST: " . ($_SERVER['HTTP_HOST'] ?? '-') . "\n";
echo "URI:  " . ($_SERVER['REQUEST_URI'] ?? '-') . "\n\n";

echo "SESSION:\n";
var_export($_SESSION);
echo "\n\n";

try {
    $pdo = db();
    echo "DB: OK (connected)\n";
} catch (Throwable $e) {
    echo "DB ERROR: " . $e->getMessage() . "\n";
}

$u = auth_user();
echo "\nUSER:\n";
var_export($u);
echo "\n</pre>";
<?php
$config = require __DIR__ . '/config.php';
$dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Database verbinding mislukt</h1>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();
header('Content-Type: text/plain; charset=utf-8');

echo "METHOD: ".($_SERVER['REQUEST_METHOD'] ?? '')."\n";
echo "ROUTE: ".($_GET['route'] ?? '')."\n\n";
echo "COOKIE: ".($_SERVER['HTTP_COOKIE'] ?? '')."\n\n";
echo "_SESSION:\n"; var_export($_SESSION); echo "\n\n";
echo "_POST:\n"; var_export($_POST); echo "\n";

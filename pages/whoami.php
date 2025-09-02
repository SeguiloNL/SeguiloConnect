<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();
header('Content-Type: text/plain; charset=utf-8');

echo "HOST: ".($_SERVER['HTTP_HOST'] ?? '')."\n";
echo "URI:  ".($_SERVER['REQUEST_URI'] ?? '')."\n";
echo "COOKIE: ".($_SERVER['HTTP_COOKIE'] ?? '(geen)') . "\n\n";

echo "_SESSION:\n";
var_export($_SESSION);
echo "\n\n";

$u = auth_user();
echo "auth_user():\n";
var_export($u);
echo "\n";
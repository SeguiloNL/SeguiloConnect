<?php
require_once __DIR__ . '/../helpers.php';
if (is_file(__DIR__ . '/../helpers_compat.php')) require_once __DIR__ . '/../helpers_compat.php';
if (function_exists('app_session_start')) app_session_start();
header('Content-Type: text/plain; charset=utf-8');
$u = function_exists('auth_user') ? auth_user() : null;
echo "OK\n";
echo "user: "; var_export($u); echo "\n";
echo "session: "; var_export($_SESSION ?? null); echo "\n";

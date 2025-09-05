<?php
require_once __DIR__ . '/../helpers.php';
if (is_file(__DIR__ . '/../helpers_compat.php')) require_once __DIR__ . '/../helpers_compat.php';
if (function_exists('app_session_start')) app_session_start();
header('Content-Type: text/plain; charset=utf-8');
echo "OK\n";

<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$_SESSION = [];
$past = time() - 3600;
foreach (['seguilo_sess','PHPSESSID'] as $name) {
  @setcookie($name, '', $past, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off', true);
  @setcookie($name, '', $past, '/index.php', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off', true);
  @setcookie($name, '', $past, '', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off', true);
}
if (session_status() === PHP_SESSION_ACTIVE) @session_destroy();

header('Location: index.php?route=login&msg='.rawurlencode('Je bent uitgelogd.'));
exit;
<?php
/* helpers.php — core */
if (!function_exists('app_session_start')) {
  function app_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $sessionName = 'seguilo_sess';
    if (!headers_sent()) {
      if (session_name() !== $sessionName) session_name($sessionName);
      $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
      if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
      } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secure, true);
      }
    }
    @session_start();
  }
}
if (!function_exists('app_config')) { function app_config(): array { static $c; if (is_array($c)) return $c; $f=__DIR__.'/config.php'; if (!is_file($f)) return $c=[]; $v=require $f; return $c=is_array($v)?$v:[]; } }
if (!function_exists('csrf_token')) { function csrf_token(): string { app_session_start(); if (empty($_SESSION['_csrf_token'])) $_SESSION['_csrf_token']=bin2hex(random_bytes(32)); return $_SESSION['_csrf_token']; } }
if (!function_exists('csrf_field')) { function csrf_field(): void { echo '<input type="hidden" name="_token" value="'.htmlspecialchars(csrf_token(),ENT_QUOTES,'UTF-8').'">'; } }
if (!function_exists('verify_csrf')) { function verify_csrf(): void { app_session_start(); $s=$_SESSION['_csrf_token']??null; $p=$_POST['_token']??$_GET['_token']??null; if(!$s||!$p||!hash_equals((string)$s,(string)$p)) throw new RuntimeException('Ongeldig CSRF-token.'); } }
if (!function_exists('db')) { function db(): PDO { if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; $cfg=app_config(); $db=$cfg['db']??[]; if (!empty($db['dsn'])) { $pdo=new PDO($db['dsn'],(string)($db['user']??''),(string)($db['pass']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); } else { $host=(string)($db['host']??'localhost'); $name=(string)($db['name']??''); $user=(string)($db['user']??''); $pass=(string)($db['pass']??''); $charset=(string)($db['charset']??'utf8mb4'); if($name===''||$user==='') throw new RuntimeException('DB-config onvolledig.'); $dsn="mysql:host={$host};dbname={$name};charset={$charset}"; $pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); } return $GLOBALS['pdo']=$pdo; } }
if (!function_exists('e')) { function e(?string $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); } }
if (!function_exists('url')) { function url(string $route,array $qs=[]): string { $q=$qs?('&'.http_build_query($qs)) : ''; return 'index.php?route='.rawurlencode($route).$q; } }
if (!function_exists('flash')) { function flash(string $type,string $message): void { app_session_start(); $_SESSION['_flash'][]=['type'=>$type,'message'=>$message]; } }
if (!function_exists('flash_all')) { function flash_all(): array { app_session_start(); $m=$_SESSION['_flash']??[]; unset($_SESSION['_flash']); return $m; } }
if (!function_exists('flash_output')) { function flash_output(): string { $o=''; foreach(flash_all() as $f){ $t=$f['type']??'info'; $m=e($f['message']??''); $o.='<div class="alert alert-'.e($t).' alert-dismissible fade show" role="alert">'.$m.'<button class="btn-close" data-bs-dismiss="alert"></button></div>'; } return $o; } }
if (!function_exists('auth_user')) { function auth_user(): ?array { app_session_start(); static $c=null; if($c!==null) return $c; $id=$_SESSION['user_id']??null; if(!$id) return $c=null; $pdo=db(); $st=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1'); $st->execute([(int)$id]); $u=$st->fetch(PDO::FETCH_ASSOC)?:null; if($u && isset($u['is_active']) && (int)$u['is_active']===0){ unset($_SESSION['user_id']); return $c=null; } return $c=$u; } }
if (!function_exists('require_login')) { function require_login(): void { if (!auth_user()) { header('Location: '.url('login')); exit; } } }
if (!function_exists('is_super_admin')) { function is_super_admin(): bool { $u=auth_user(); $r=$u['role']??''; return $r==='super_admin' || (defined('ROLE_SUPER') && $r===ROLE_SUPER); } }
if (!function_exists('user_display_role')) { function user_display_role(?string $r): string { $m=['super_admin'=>'Super-admin','reseller'=>'Reseller','sub_reseller'=>'Sub-reseller','customer'=>'Eindklant']; return $m[$r]??(string)$r; } }
if (!function_exists('redirect')) { function redirect(string $url,int $status=302): void { if($url==='') $url='index.php'; if(!headers_sent()){ header('Location: '.$url,true,$status); exit; } $safe=htmlspecialchars($url,ENT_QUOTES,'UTF-8'); echo '<script>location.href='.json_encode($url).';</script><noscript><meta http-equiv="refresh" content="0;url='.$safe.'"></noscript>'; exit; } }
if (!function_exists('is_logged_in')) { function is_logged_in(): bool { return (bool) auth_user(); } }
if (!function_exists('has_role')) { function has_role(string $r): bool { $u=auth_user(); return $u ? (($u['role']??'')===$r) : false; } }

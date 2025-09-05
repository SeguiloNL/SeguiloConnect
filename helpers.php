<?php
declare(strict_types=1);

/**
 * helpers.php — algemene hulpfuncties voor SeguiloConnect
 */

if (!function_exists('app_config')) {
    function app_config(): array {
        static $cfg = null;
        if ($cfg !== null) return $cfg;
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('config.php niet gevonden');
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('config.php leverde geen array op');
        }
        return $cfg;
    }
}

if (!function_exists('app_session_start')) {
    function app_session_start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $cfg = app_config();
            $name = $cfg['app']['session_name'] ?? 'seguilo_sess';
            if (!headers_sent()) {
                session_name($name);
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            @session_start();
        }
        if (!isset($_SESSION['_flash'])) $_SESSION['_flash'] = [];
    }
}

if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $cfg = app_config();
        $db  = $cfg['db'] ?? [];
        $host = $db['host'] ?? 'localhost';
        $name = $db['name'] ?? '';
        $user = $db['user'] ?? '';
        $pass = $db['pass'] ?? '';
        $port = (string)($db['port'] ?? '3306');
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, $user, $pass, $opt);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        return $pdo;
    }
}

if (!function_exists('e')) {
    function e(?string $v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(): string {
        $cfg = app_config();
        if (!empty($cfg['app']['base_url'])) {
            return rtrim($cfg['app']['base_url'], '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('index.php', '', $script), '/');
        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void {
        if (!preg_match('~^https?://~i', $url)) {
            if (str_starts_with($url, '/')) {
                $url = rtrim(base_url(), '/') . $url;
            } elseif (!str_starts_with($url, 'index.php')) {
                $url = rtrim(base_url(), '/') . '/' . ltrim($url, '/');
            } else {
                $url = rtrim(base_url(), '/') . '/' . $url;
            }
        }
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        echo '<script>location.href='.json_encode($url).';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url='.e($url).'"></noscript>';
        exit;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): void {
        echo '<input type="hidden" name="_token" value="'.e(csrf_token()).'">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void {
        $post = $_POST['_token'] ?? '';
        $sess = $_SESSION['_csrf_token'] ?? '';
        if (!is_string($post) || !is_string($sess) || $post === '' || !hash_equals($sess, $post)) {
            throw new RuntimeException('Invalid CSRF token');
        }
    }
}

if (!function_exists('flash_set')) {
    function flash_set(string $type, string $message): void {
        $_SESSION['_flash'][] = ['type'=>$type, 'msg'=>$message];
    }
}
if (!function_exists('flash_get')) {
    function flash_get(): array {
        $items = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];
        return $items;
    }
}
if (!function_exists('flash_output')) {
    function flash_output(): string {
        $out = '';
        foreach (flash_get() as $f) {
            $type = preg_replace('/[^a-z]/i', '', (string)($f['type'] ?? 'info'));
            $msg  = (string)($f['msg'] ?? '');
            $out .= '<div class="alert alert-'.e($type).'">'.$msg.'</div>';
        }
        return $out;
    }
}

if (!function_exists('role_label')) {
    function role_label(?string $role): string {
        return match ($role) {
            'super_admin'   => 'Super-admin',
            'reseller'      => 'Reseller',
            'sub_reseller'  => 'Sub-reseller',
            'customer'      => 'Eindklant',
            default         => ucfirst((string)$role),
        };
    }
}

/** Feature flags (optioneel) */
if (!function_exists('feature_allowed_for_user')) {
    function feature_allowed_for_user(PDO $pdo, array $user, string $featureKey): bool {
        $role  = (string)($user['role'] ?? '');
        $userId= (int)($user['id'] ?? 0);

        try {
            $st = $pdo->prepare("SELECT allowed FROM user_feature WHERE user_id=? AND feature_key=?");
            $st->execute([$userId, $featureKey]);
            $ov = $st->fetchColumn();
            if ($ov !== false) return ((int)$ov === 1);
        } catch (Throwable $e) {}

        try {
            $st = $pdo->prepare("SELECT allowed FROM role_feature WHERE role_name=? AND feature_key=?");
            $st->execute([$role, $featureKey]);
            $rv = $st->fetchColumn();
            if ($rv !== false) return ((int)$rv === 1);
        } catch (Throwable $e) {}

        if ($role === 'super_admin') return true;
        return false;
    }
}

if (!function_exists('column_exists')) {
    function column_exists(PDO $pdo, string $table, string $column): bool {
        $q = $pdo->quote($column);
        $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
        return (bool)($st && $st->fetch(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('build_tree_ids')) {
    function build_tree_ids(PDO $pdo, int $rootId): array {
        if (!column_exists($pdo, 'users', 'parent_user_id')) return [$rootId];
        $ids  = [$rootId];
        $seen = [$rootId => true];
        $queue = [$rootId];
        while ($queue) {
            $chunk = array_splice($queue, 0, 200);
            if (!$chunk) break;
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $cid = (int)$cid;
                if (!isset($seen[$cid])) {
                    $seen[$cid] = true;
                    $ids[] = $cid;
                    $queue[] = $cid;
                }
            }
        }
        return $ids;
    }
}

if (!function_exists('request_method')) {
    function request_method(): string {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }
}
if (!function_exists('is_post')) {
    function is_post(): bool {
        return request_method() === 'POST';
    }
}
if (!function_exists('current_route')) {
    function current_route(): string {
        return (string)($_GET['route'] ?? 'dashboard');
    }
}
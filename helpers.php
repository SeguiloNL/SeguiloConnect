<?php
/**
 * helpers.php — centrale helpers voor Seguilo Connect
 * - Stabiele sessies (vaste sessienaam, tolerant als headers al zijn verzonden)
 * - Config laden
 * - CSRF helpers
 * - DB (PDO singleton)
 * - Auth helpers (auth_user)
 * - Flash messages
 * - Kleine util-functies
 *
 * Let op: GEEN whitespace/echo vóór deze PHP-tag, om headers/sessieproblemen te voorkomen.
 */

/* ===========================
 *  Sessie & basis helpers
 * =========================== */

if (!function_exists('app_session_start')) {
    function app_session_start(): void
    {
        // Al actief? Klaar.
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = 'seguilo_sess';

        // Alleen cookie-parameters/naam aanpassen als headers nog niet zijn verzonden
        if (!headers_sent()) {
            if (session_name() !== $sessionName) {
                session_name($sessionName);
            }
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $params = [
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ];
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params($params);
            } else {
                // Oudere PHP: samesite via path-hack
                session_set_cookie_params(
                    $params['lifetime'],
                    $params['path'].'; samesite='.$params['samesite'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
        } else {
            // Headers al verzonden → sessienaam/params níet meer wijzigen.
            // We starten de sessie met bestaande instellingen.
        }

        // Start de sessie (veilig bij herhaalde aanroepen)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}

/* ===========================
 *  Config
 * =========================== */

if (!function_exists('app_config')) {
    function app_config(): array
    {
        static $cfg;
        if (is_array($cfg)) return $cfg;

        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            return $cfg = [];
        }
        $val = require $file;
        return $cfg = (is_array($val) ? $val : []);
    }
}

/* ===========================
 *  CSRF Helpers
 * =========================== */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        app_session_start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): void
    {
        $t = e(csrf_token());
        echo '<input type="hidden" name="_token" value="' . $t . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(): void
    {
        app_session_start();
        $sessionToken = $_SESSION['_csrf_token'] ?? null;
        $posted       = $_POST['_token'] ?? $_GET['_token'] ?? null;
        if (!$sessionToken || !$posted || !hash_equals((string)$sessionToken, (string)$posted)) {
            throw new RuntimeException('Ongeldig CSRF-token.');
        }
        // Token roteren na succes? Zou kunnen:
        // $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}

/* ===========================
 *  Database (PDO)
 * =========================== */

if (!function_exists('db')) {
    /**
     * db(): gedeelde PDO-verbinding (singleton per request)
     */
    function db(): PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        // 1) Optioneel: bekende paden die $pdo zetten
        foreach ([__DIR__ . '/db.php', __DIR__ . '/includes/db.php', __DIR__ . '/config/db.php'] as $f) {
            if (is_file($f)) {
                require_once $f;
                if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                    return $GLOBALS['pdo'];
                }
            }
        }

        // 2) Val terug op config.php
        $cfg = app_config();
        $db  = $cfg['db'] ?? [];

        if (!empty($db['dsn'])) {
            $pdo = new PDO(
                (string)$db['dsn'],
                (string)($db['user'] ?? ''),
                (string)($db['pass'] ?? ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } else {
            $host = (string)($db['host'] ?? 'localhost');
            $name = (string)($db['name'] ?? '');
            $user = (string)($db['user'] ?? '');
            $pass = (string)($db['pass'] ?? '');
            $charset = (string)($db['charset'] ?? 'utf8mb4');

            if ($name === '' || $user === '') {
                throw new RuntimeException('DB-config onvolledig (name/user ontbreekt).');
            }

            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
            $pdo = new PDO(
                $dsn,
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }

        return $GLOBALS['pdo'] = $pdo;
    }
}

/* ===========================
 *  Kleine utils
 * =========================== */

if (!function_exists('e')) {
    /** HTML-escape */
    function e(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /** Eenvoudige URL-builder met query */
    function url(string $route, array $qs = []): string
    {
        $q = $qs ? ('&' . http_build_query($qs)) : '';
        return 'index.php?route=' . rawurlencode($route) . $q;
    }
}

/* ===========================
 *  Flash-meldingen
 * =========================== */

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        app_session_start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('flash_all')) {
    /** Haal ALLE flash-berichten op en leeg de buffer. */
    function flash_all(): array
    {
        app_session_start();
        $msgs = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $msgs;
    }
}

if (!function_exists('flash_output')) {
    /** Render alle flash-berichten als Bootstrap alerts. */
    function flash_output(): string
    {
        $out = '';
        foreach (flash_all() as $f) {
            $type = $f['type'] ?? 'info';
            $msg  = e($f['message'] ?? '');
            $out .= '<div class="alert alert-' . e($type) . ' alert-dismissible fade show" role="alert">'
                 .  $msg
                 .  '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
                 .  '</div>';
        }
        return $out;
    }
}

/* ===========================
 *  Authenticatie helpers
 * =========================== */

if (!function_exists('auth_user')) {
    /**
     * auth_user(): haal de ingelogde gebruiker op via $_SESSION['user_id'].
     * Cached per request.
     */
    function auth_user(): ?array
    {
        app_session_start();

        static $cached = null;
        if ($cached !== null) return $cached;

        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return $cached = null;

        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$id]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        // Optioneel: blokkeer inactieve accounts
        if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
            // user is niet actief → log uit
            unset($_SESSION['user_id']);
            return $cached = null;
        }

        return $cached = $user;
    }
}

if (!function_exists('require_login')) {
    /** Redirect naar login als er geen user is. */
    function require_login(): void
    {
        if (!auth_user()) {
            redirect('index.php?route=login');
        }
    }
}

if (!function_exists('is_super_admin')) {
    function is_super_admin(): bool
    {
        $u = auth_user();
        $r = $u['role'] ?? '';
        if ($r === 'super_admin') return true;
        // Eventuele constante-rollen ondersteunen
        if (defined('ROLE_SUPER') && $r === ROLE_SUPER) return true;
        return false;
    }
}

if (!function_exists('user_display_role')) {
    function user_display_role(?string $role): string
    {
        $map = [
            'super_admin'  => 'Super-admin',
            'reseller'     => 'Reseller',
            'sub_reseller' => 'Sub-reseller',
            'customer'     => 'Eindklant',
        ];
        // Eventuele constanten
        if (defined('ROLE_SUPER') && $role === ROLE_SUPER)             return 'Super-admin';
        if (defined('ROLE_RESELLER') && $role === ROLE_RESELLER)       return 'Reseller';
        if (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER) return 'Sub-reseller';
        if (defined('ROLE_CUSTOMER') && $role === ROLE_CUSTOMER)       return 'Eindklant';

        return $map[$role] ?? (string)$role;
    }
}

/* ===========================
 *  Redirect
 * =========================== */

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): void
    {
        if ($url === '') { $url = 'index.php'; }

        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
            exit;
        }

        // Fallback als headers al weg zijn
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo '<script>window.location.href = ' . json_encode($url) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safeUrl . '"></noscript>';
        exit;
    }
}

/* ===========================
 *  Backwards-compat shims
 * =========================== */

if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool { return (bool) auth_user(); }
}
if (!function_exists('has_role')) {
    function has_role(string $role): bool {
        $u = auth_user();
        return $u ? (($u['role'] ?? '') === $role) : false;
    }
}
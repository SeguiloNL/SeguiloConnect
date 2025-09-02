<?php
/**
 * helpers.php — centrale helpers voor Seguilo Connect
 * - Zorgt voor stabiele sessies (1 vaste sessienaam)
 * - Leest config in
 * - Biedt CSRF, DB, auth_user en kleine util-functies
 */

/* ===========================
 *  Sessie & basis helpers
 * =========================== */

if (!function_exists('app_session_start')) {
    function app_session_start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Vaste sessienaam om conflicten met andere PHP-sites op dezelfde host te voorkomen
        if (session_name() !== 'seguilo_sess') {
            session_name('seguilo_sess');
        }

        // Veilige cookie-instellingen
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            // Tip: gebruik óf 1 vaste host via .htaccess, óf zet expliciet een domein:
            // 'domain'   => '.seguilo-connect.nl',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // voorkom URL-sessies
        if (function_exists('ini_set')) {
            @ini_set('session.use_trans_sid', '0');
            @ini_set('session.use_cookies', '1');
            @ini_set('session.use_only_cookies', '1');
        }

        @session_start();

        // Oude PHPSESSID-cookie opruimen (kan dubbele sessies veroorzaken)
        if (!empty($_COOKIE['PHPSESSID'])) {
            $past = time() - 3600;
            foreach (['/','/index.php',''] as $p) {
                @setcookie('PHPSESSID', '', $past, $p, '', $isHttps, true);
            }
            unset($_COOKIE['PHPSESSID']);
        }
    }
}

if (!function_exists('e')) {
    /** Veilig escapen voor HTML output */
    function e(?string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_config')) {
    /** Laad config.php éénmalig in (verwacht array return) */
    function app_config(): array
    {
        static $cfg = null;
        if ($cfg !== null) return $cfg;

        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            // fallback: probeer 1 map omhoog
            $alt = dirname(__DIR__) . '/config.php';
            if (is_file($alt)) $file = $alt;
        }
        if (!is_file($file)) {
            throw new RuntimeException('config.php niet gevonden.');
        }
        $cfg = require $file;
        if (!is_array($cfg)) {
            throw new RuntimeException('config.php moet een array retourneren.');
        }
        return $cfg;
    }
}

if (!function_exists('base_url')) {
    /** Bepaal basis-URL (uit config of afgeleid) */
    function base_url(): string
    {
        $cfg = app_config();
        if (!empty($cfg['app']['base_url'])) {
            return rtrim($cfg['app']['base_url'], '/');
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/.');
        return rtrim($https . '://' . $host . ($dir ? $dir : ''), '/');
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
    }
}

/* ===========================
 *  Database connectie
 * =========================== */

if (!function_exists('db')) {
    /**
     * db(): gedeelde PDO-verbinding (singleton per request)
     * Probeert bekende paden, valt terug op config.php['db']
     */
    function db(): PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }

        // 1) Probeer veelgebruikte include-paden die $pdo zetten
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
                $db['dsn'],
                $db['user'] ?? null,
                $db['pass'] ?? null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            return $GLOBALS['pdo'] = $pdo;
        }

        $host    = $db['host'] ?? 'localhost';
        $name    = $db['name'] ?? ($db['database'] ?? '');
        $user    = $db['user'] ?? ($db['username'] ?? '');
        $pass    = $db['pass'] ?? ($db['password'] ?? '');
        $charset = $db['charset'] ?? 'utf8mb4';

        if ($name === '') {
            throw new RuntimeException('DB-naam ontbreekt in config.');
        }

        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset={$charset}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        return $GLOBALS['pdo'] = $pdo;
    }
}

// --- Flash messages ---
if (!function_exists('flash_set')) {
    /**
     * Sla een melding op voor de volgende request.
     * $type: 'success' | 'info' | 'warning' | 'danger'
     */
    function flash_set(string $type, string $message): void
    {
        app_session_start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('flash_all')) {
    /** Haal alle flash-berichten op en wis ze direct. */
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
     * - Per-request cache (op basis van sessie user_id)
     */
    function auth_user(): ?array
    {
        app_session_start();
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        static $cachedUserId = null;
        static $cachedUser   = null;

        if ($cachedUser !== null && $cachedUserId === (int)$_SESSION['user_id']) {
            return $cachedUser;
        }

        try {
            $pdo = db();
            $st  = $pdo->prepare(
                "SELECT id, name, email, role, is_active
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );
            $st->execute([ (int)$_SESSION['user_id'] ]);
            $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            // Bij DB-probleem: beschouw als niet ingelogd
            return null;
        }

        $cachedUserId = (int)$_SESSION['user_id'];
        $cachedUser   = $user;

        return $user;
    }
}

/* ===========================
 *  Kleine util-functies
 * =========================== */

if (!function_exists('role_label')) {
    function role_label(?string $role): string
    {
        $map = [
            'super_admin'  => 'Super-admin',
            'reseller'     => 'Reseller',
            'sub_reseller' => 'Sub-reseller',
            'customer'     => 'Eindklant',
        ];
        // Eventuele constanten ondersteunen
        if (defined('ROLE_SUPER') && $role === ROLE_SUPER)               return 'Super-admin';
        if (defined('ROLE_RESELLER') && $role === ROLE_RESELLER)         return 'Reseller';
        if (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER)   return 'Sub-reseller';
        if (defined('ROLE_CUSTOMER') && $role === ROLE_CUSTOMER)         return 'Eindklant';

        return $map[$role] ?? (string)$role;
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
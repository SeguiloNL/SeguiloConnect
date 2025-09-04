<?php
/**
 * auth.php — compacte authenticatie-helpers
 *
 * Verwacht:
 * - helpers.php met: app_session_start(), db(), auth_user(), redirect(), e(), etc.
 * - auth_user() haalt de user op op basis van $_SESSION['user_id'].
 */

require_once __DIR__ . '/helpers.php';
app_session_start();


/**
 * Log een gebruiker in door de sessie-user te zetten.
 * @param array $user  Minimaal ['id'=>int]
 */
if (!function_exists('auth_login')) {
    function auth_login(array $user): void
    {
        if (!isset($user['id'])) {
            throw new InvalidArgumentException('auth_login: $user["id"] ontbreekt.');
        }

        // Als er impersonatie actief was, verbreek die
        unset($_SESSION['impersonator_id']);

        // Zet actuele user
        $_SESSION['user_id'] = (int)$user['id'];

        // Ruim eventuele cached user-info op
        unset($_SESSION['auth_user'], $_SESSION['cached_user']);

        // Verander session id bij privilege change
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
    }
}


<?php
function has_role(string $role): bool {
    if (!isset($_SESSION['user']) || empty($_SESSION['user']['role'])) {
        return false;
    }
    return $_SESSION['user']['role'] === $role;
}

/**
 * Uitloggen: wis login & impersonatie-informatie uit de sessie.
 */
if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        // Wis alleen auth-gerelateerde sleutels
        unset($_SESSION['user_id'], $_SESSION['impersonator_id'], $_SESSION['auth_user'], $_SESSION['cached_user']);

        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
    }
}

/**
 * Is er iemand ingelogd?
 */
if (!function_exists('is_logged_in')) {
    function is_logged_in(): bool
    {
        return auth_user() !== null;
    }
}

/**
 * Vereist ingelogd zijn; zoniet: redirect naar login.
 */
if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!is_logged_in()) {
            // Optioneel: bewaar gewenste URL
            // $_SESSION['after_login_redirect'] = $_SERVER['REQUEST_URI'] ?? 'index.php?route=dashboard';
            header('Location: index.php?route=login');
            exit;
        }
    }
}

/**
 * Is impersonatie actief?
 */
if (!function_exists('impersonation_active')) {
    function impersonation_active(): bool
    {
        return !empty($_SESSION['impersonator_id']);
    }
}

/**
 * Start impersonatie (hulpfunctie; je gebruikt doorgaans de pagina/route `impersonate_start`).
 * - Slaat de oorspronkelijke user op in `impersonator_id` (indien nog leeg)
 * - Zet de actuele sessie-user naar het doel-id
 */
if (!function_exists('impersonation_start')) {
    function impersonation_start(int $targetUserId): void
    {
        $me = auth_user();
        if (!$me) {
            header('Location: index.php?route=login');
            exit;
        }

        if (empty($_SESSION['impersonator_id'])) {
            $_SESSION['impersonator_id'] = (int)$me['id'];
        }
        $_SESSION['user_id'] = $targetUserId;

        // Cache legen
        unset($_SESSION['auth_user'], $_SESSION['cached_user']);

        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
    }
}

/**
 * Stop impersonatie en keer terug naar oorspronkelijke account.
 */
if (!function_exists('impersonation_stop')) {
    function impersonation_stop(): void
    {
        if (!empty($_SESSION['impersonator_id'])) {
            $_SESSION['user_id'] = (int)$_SESSION['impersonator_id'];
            unset($_SESSION['impersonator_id'], $_SESSION['auth_user'], $_SESSION['cached_user']);

            if (function_exists('session_regenerate_id')) {
                @session_regenerate_id(true);
            }
        }
    }
}

/**
 * (Optioneel) Rollen-checks — gebruik naar behoefte
 */
if (!function_exists('require_role')) {
    /**
     * @param string|array $roles  Eén rol of lijst met rollen die toegestaan zijn
     */
    function require_role($roles): void
    {
        $u = auth_user();
        if (!$u) {
            header('Location: index.php?route=login');
            exit;
        }
        $allowed = (array)$roles;
        if (!in_array($u['role'] ?? '', $allowed, true)) {
            http_response_code(403);
            echo '<div class="alert alert-danger">Geen toegang.</div>';
            exit;
        }
    }
}

/**
 * (Optioneel) Manager-check (super/reseller/sub-reseller)
 */
if (!function_exists('is_manager')) {
    function is_manager(): bool
    {
        $u = auth_user();
        if (!$u) return false;
        $r = $u['role'] ?? '';
        return in_array($r, ['super_admin','reseller','sub_reseller'], true)
            || (defined('ROLE_SUPER') && $r === ROLE_SUPER)
            || (defined('ROLE_RESELLER') && $r === ROLE_RESELLER)
            || (defined('ROLE_SUBRESELLER') && $r === ROLE_SUBRESELLER);
    }
}

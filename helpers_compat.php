<?php
/**
 * helpers_compat.php — Backwards-compat laag voor oude functienamen.
 * Laad NA helpers.php.
 */

// is_logged_in() → wrapper op auth_user()
if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool { return (bool) (function_exists('auth_user') ? auth_user() : null); }
}

// require_login() → gebruik auth.php implementatie of simpele redirect
if (!function_exists('require_login')) {
  function require_login(): void {
    $u = function_exists('auth_user') ? auth_user() : null;
    if (!$u) {
      if (!headers_sent()) {
        header('Location: index.php?route=login');
        exit;
      }
      echo '<script>location.href="index.php?route=login";</script>';
      echo '<noscript><meta http-equiv="refresh" content="0;url=index.php?route=login"></noscript>';
      exit;
    }
  }
}

// has_role()
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    $u = function_exists('auth_user') ? auth_user() : null;
    if (!$u) return false;
    $r = $u['role'] ?? '';
    return $r === $role;
  }
}

// is_super_admin()
if (!function_exists('is_super_admin')) {
  function is_super_admin(): bool {
    $u = function_exists('auth_user') ? auth_user() : null;
    $r = $u['role'] ?? '';
    if ($r === 'super_admin') return true;
    return (defined('ROLE_SUPER') && $r === ROLE_SUPER);
  }
}

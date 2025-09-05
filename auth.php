<?php
declare(strict_types=1);

// auth.php — authenticatie helpers
require_once __DIR__ . '/helpers.php';

if (!function_exists('auth_user')) {
    function auth_user(): ?array {
        app_session_start();
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        try {
            $pdo = db();
            $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
            $st->execute([(int)$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            return $u ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('auth_login')) {
    function auth_login(array $user): void {
    app_session_start();
    // regenereer sessie-id bij login
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['impersonator_id']);
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void {
        app_session_start();
        unset($_SESSION['user_id'], $_SESSION['impersonator_id']);
    }
}

if (!function_exists('auth_impersonate_start')) {
    function auth_impersonate_start(int $targetId): void {
        app_session_start();
        if (empty($_SESSION['impersonator_id'])) {
            $_SESSION['impersonator_id'] = $_SESSION['user_id'] ?? null;
        }
        $_SESSION['user_id'] = $targetId;
    }
}

if (!function_exists('auth_impersonate_stop')) {
    function auth_impersonate_stop(): void {
        app_session_start();
        if (!empty($_SESSION['impersonator_id'])) {
            $_SESSION['user_id'] = $_SESSION['impersonator_id'];
            unset($_SESSION['impersonator_id']);
        }
    }
}
<?php
// lib/csrf.php — eenvoudige, sessiebrede CSRF helpers (idempotent)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
        $t = csrf_token();
        echo '<input type="hidden" name="_token" value="'.htmlspecialchars($t, ENT_QUOTES, 'UTF-8').'">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Verifieert POST _token tegen sessie-token.
     * Gooi een Exception bij mismatch i.p.v. die() zodat caller zelf boodschap kan tonen.
     */
    function verify_csrf(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sent = $_POST['_token'] ?? '';
        $sess = $_SESSION['_csrf_token'] ?? '';
        if (!$sent || !$sess || !hash_equals($sess, $sent)) {
            throw new RuntimeException('Invalid CSRF token');
        }
        // Optioneel: token roteren NA succesvolle check
        // $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}
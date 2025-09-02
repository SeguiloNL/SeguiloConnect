<?php
// pages/impersonate_stop.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

// Alleen POST (voorkomt bots/GET-misbruik). Wil je GET toestaan? Pas dit aan.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    // Toestaan via GET? zet hier 'GET' of redirect met melding:
    flash_set('warning', 'Ongeldige aanroep voor impersonatie stoppen.');
    header('Location: index.php?route=dashboard');
    exit;
}

// Optioneel: CSRF check
try {
    if (function_exists('verify_csrf')) {
        verify_csrf();
    }
} catch (Throwable $e) {
    flash_set('danger', 'Sessie verlopen. Probeer het opnieuw.');
    header('Location: index.php?route=dashboard');
    exit;
}

// Terugschakelen naar oorspronkelijke gebruiker (als actief)
if (!empty($_SESSION['impersonator_id'])) {
    $_SESSION['user_id'] = (int)$_SESSION['impersonator_id'];
    unset($_SESSION['impersonator_id'], $_SESSION['auth_user'], $_SESSION['cached_user']);
    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
    flash_set('success', 'Je bent teruggeschakeld naar je eigen account.');
} else {
    flash_set('info', 'Er was geen actieve impersonatie.');
}

header('Location: index.php?route=dashboard');
exit;
<?php

//DEBUG

define('SC_DEBUG', true);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Log ook naar file (handig als er niets getoond wordt)
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/logs/php-error.log');
if (!is_dir(__DIR__ . '/storage/logs')) { @mkdir(__DIR__ . '/storage/logs', 0775, true); }

// Vang fatals
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo "<pre style='padding:16px;background:#111;color:#eee'>FATAL: {$e['message']} in {$e['file']}:{$e['line']}</pre>";
    }
});
set_error_handler(function($severity,$message,$file,$line){
    // gooi warnings/notices ook omhoog in debug
    if (error_reporting()) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});

// EINDE DEBUG



// index.php — hoofdrouting voor SeguiloConnect portal
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/helpers.php';
app_session_start();

// ---- 1) Bepaal route ----
$route = $_GET['route'] ?? null;

// ---- 2) Early routes (POST/redirect handlers) ----
$earlyRoutes = [
    'do_login',
    'logout',
    'impersonate_start',
    'impersonate_stop',
    'sim_bulk_assign',
    'sim_bulk_delete',
    'sim_bulk_action',
    'plan_delete',
    'order_delete',
    'user_delete',
];

if ($route && in_array($route, $earlyRoutes, true)) {
    $file = __DIR__ . '/pages/' . basename($route) . '.php';
    if (is_file($file)) {
        include $file;
        exit;
    } else {
        http_response_code(404);
        echo "Pagina niet gevonden.";
        exit;
    }
}

// ---- 3) Default route: dashboard als ingelogd, login anders ----
if (!$route) {
    $route = auth_user() ? 'dashboard' : 'login';
}

// ---- 4) Als al ingelogd, nooit loginpagina tonen ----
if ($route === 'login' && auth_user()) {
    header('Location: index.php?route=dashboard');
    exit;
}

// ---- 5) Header tonen ----
include __DIR__ . '/views/header.php';

// ---- 6) Router voor views/pages ----
try {
    switch ($route) {
        case 'dashboard':
            include __DIR__ . '/pages/dashboard.php';
            break;

        case 'login':
            include __DIR__ . '/pages/login.php';
            break;

        case 'users_list':
            include __DIR__ . '/pages/users_list.php';
            break;

        case 'user_add':
            include __DIR__ . '/pages/user_add.php';
            break;

        case 'user_edit':
            include __DIR__ . '/pages/user_edit.php';
            break;

        case 'sims_list':
            include __DIR__ . '/pages/sims_list.php';
            break;

        case 'sim_add':
            include __DIR__ . '/pages/sim_add.php';
            break;

        case 'sim_edit':
            include __DIR__ . '/pages/sim_edit.php';
            break;

        case 'sim_delete':
            require __DIR__ . '/pages/sim_delete.php';
            break;    

        case 'sim_assign':
            include __DIR__ . '/pages/sim_assign.php';
            break;

        case 'plans_list':
            include __DIR__ . '/pages/plans_list.php';
            break;

        case 'plan_add':
            include __DIR__ . '/pages/plan_add.php';
            break;

        case 'plan_edit':
            include __DIR__ . '/pages/plan_edit.php';
            break;

        case 'plan_duplicate':
            include __DIR__ . '/pages/plan_duplicate.php';
            break;

        case 'orders_list':
            include __DIR__ . '/pages/orders_list.php';
            break;

        case 'order_add':
            include __DIR__ . '/pages/order_add.php';
            break;

        case 'order_edit':
            include __DIR__ . '/pages/order_edit.php';
            break;

        case 'system_admin':
            include __DIR__ . '/pages/system_admin.php';
            break;

        case 'forgot_password':
            include __DIR__ . '/pages/forgot_password.php';
            break;

        case 'reset_password':
            include __DIR__ . '/pages/reset_password.php';
            break;

        case 'order_cancel':
            include __DIR__ . '/pages/order_cancel.php';
            break;    

        case 'ajax_sims_search':
            require __DIR__ . '/pages/ajax_sims_search.php';
            break;

        case 'admin_users':           
            require 'admin/users.php'; 
            break;

        case 'admin_user_edit':       
            require 'admin/user_edit.php'; 
            break;

        case 'admin_do_user_save':    
            require 'admin/do_user_save.php'; 
            break;

        case 'admin_do_user_toggle':  
            require 'admin/do_user_toggle.php'; 
            break;

        case 'profile': 
            require 'pages/profile.php'; 
            break;

        case 'do_change_password': 
            require 'pages/do_change_password.php'; 
            break;





        case 'system_users':
            require __DIR__ . '/pages/system_users.php';
            break;

        default:
            // fallback: als ingelogd → dashboard, anders → login
            if (auth_user()) {
                include __DIR__ . '/pages/dashboard.php';
            } else {
                include __DIR__ . '/pages/login.php';
            }
            break;
    }
} catch (Throwable $e) {
    echo '<div class="alert alert-danger">Laden mislukt: ' . e($e->getMessage()) . '</div>';
}

// ---- 7) Footer tonen ----
include __DIR__ . '/views/footer.php';
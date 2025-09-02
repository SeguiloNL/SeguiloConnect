<?php
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

        case 'whoami':
            include __DIR__ . '/pages/whoami.php';
            break;

        case 'ajax_sims_search':
            require __DIR__ . '/pages/ajax_sims_search.php';
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
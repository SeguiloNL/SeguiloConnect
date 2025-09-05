<?php
declare(strict_types=1);

/**
 * index.php — SeguiloConnect route autoloader (schoon en correct laadvolgorde)
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

app_session_start();

// Forceer DB-verbinding vroeg; toont nette melding als het faalt
try {
    $pdo = db();
} catch (Throwable $e) {
    echo "<!doctype html><html lang='nl'><head><meta charset='utf-8'>";
    echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>";
    echo "<title>SeguiloConnect</title></head><body><div class='container mt-4'>";
    echo "<div class='alert alert-danger'>Database-verbinding mislukt. Probeer het later opnieuw.</div>";
    echo "</div></body></html>";
    exit;
}

// Route bepalen en valideren
$routeRaw = $_GET['route'] ?? 'dashboard';
$route    = strtolower((string)$routeRaw);
$route    = preg_replace('~[^a-z0-9_]~', '', $route);
if ($route === '') $route = 'dashboard';

$pageFile = __DIR__ . '/pages/' . $route . '.php';

// Header (let op: header mag auth_user() gebruiken; auth.php is al geladen)
include __DIR__ . '/views/header.php';

// Content
try {
    if (is_file($pageFile)) {
        include $pageFile;
    } else {
        http_response_code(404);
        echo "<div class='alert alert-danger'>Ongeldige of ontbrekende route: <strong>" . e($routeRaw) . "</strong></div>";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<div class='alert alert-danger'>Er is iets misgegaan bij het laden van deze pagina.</div>";
}

// Footer
include __DIR__ . '/views/footer.php';
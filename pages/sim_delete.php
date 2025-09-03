<?php
// pages/sim_delete.php — enkel Super-admin
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role = (string)($me['role'] ?? '');
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
  flash_set('danger', 'Alleen Super-admin mag simkaarten verwijderen.');
  redirect('index.php?route=sims_list');
  exit;
}

// Alleen POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  flash_set('warning', 'Ongeldige aanroep.');
  redirect('index.php?route=sims_list');
  exit;
}

// CSRF
try { if (function_exists('verify_csrf')) verify_csrf(); }
catch (Throwable $e) {
  flash_set('danger', 'Sessie verlopen. Probeer opnieuw.');
  redirect('index.php?route=sims_list');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash_set('danger', 'Ongeldige simkaart.');
  redirect('index.php?route=sims_list');
  exit;
}

try {
  $pdo = db();

  // Optioneel: voorkom verwijderen als er orders aan hangen
  $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE sim_id = ?");
  $st->execute([$id]);
  if ((int)$st->fetchColumn() > 0) {
    flash_set('warning', 'Kan niet verwijderen: simkaart is gekoppeld aan bestellingen.');
    redirect('index.php?route=sims_list');
    exit;
  }

  $del = $pdo->prepare("DELETE FROM sims WHERE id = ? LIMIT 1");
  $del->execute([$id]);

  if ($del->rowCount() > 0) {
    flash_set('success', 'Simkaart verwijderd.');
  } else {
    flash_set('warning', 'Simkaart niet gevonden of al verwijderd.');
  }
} catch (Throwable $e) {
  // FK of andere DB-fout
  flash_set('danger', 'Verwijderen mislukt: ' . $e->getMessage());
}

redirect('index.php?route=sims_list');
<?php
// pages/sim_delete.php
$u = auth_user();
if ($u['role'] !== ROLE_SUPER) { http_response_code(403); echo "Alleen super-admin."; exit; }
global $pdo;

$id = (int)($_POST['id'] ?? 0);
if (!$id) { redirect('sims_list', ['error'=>'Ongeldig sim-ID.']); }

try {
    $stmt = $pdo->prepare("DELETE FROM sims WHERE id = ?");
    $stmt->execute([$id]);
    redirect('sims_list', ['msg'=>'Simkaart verwijderd.']);
} catch (Throwable $e) {
    // Vaak FK vanwege orders: geef nette hint
    redirect('sims_list', ['error'=>'Kon niet verwijderen. Mogelijk gekoppeld aan bestellingen. Zet de sim eventueel op inactief.']);
}
<?php
// pages/sim_retire.php
$u = auth_user();
if (!in_array($u['role'], [ROLE_SUPER, ROLE_RESELLER, ROLE_SUBRESELLER], true)) {
    http_response_code(403); echo "Geen toegang."; exit;
}
global $pdo;

$id = (int)($_POST['id'] ?? 0);
if (!$id) { redirect('sims_list', ['error'=>'Ongeldig sim-ID.']); }

// Haal sim op
$stmt = $pdo->prepare("SELECT * FROM sims WHERE id = ?");
$stmt->execute([$id]);
$sim = $stmt->fetch();
if (!$sim) { redirect('sims_list', ['error'=>'Sim niet gevonden.']); }

// Reseller/sub: alleen als de sim in hun boom zit
if ($u['role'] !== ROLE_SUPER) {
    $ids = user_descendant_ids((int)$u['id']);
    $ids[] = (int)$u['id'];
    if (!in_array((int)$sim['owner_user_id'], array_map('intval', $ids), true)) {
        http_response_code(403); echo "Je mag alleen simkaarten in jouw beheer op inactief zetten."; exit;
    }
}

if ($sim['status'] === 'retired') {
    redirect('sims_list', ['msg'=>'Simkaart was al inactief.']);
}

$upd = $pdo->prepare("UPDATE sims SET status='retired' WHERE id=?");
$upd->execute([$id]);
redirect('sims_list', ['msg'=>'Simkaart op inactief gezet.']);
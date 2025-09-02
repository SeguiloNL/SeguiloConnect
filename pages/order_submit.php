<?php
$u = auth_user();
if (!in_array($u['role'], [ROLE_RESELLER, ROLE_SUBRESELLER], true)) { http_response_code(403); echo "Geen toegang."; exit; }
global $pdo;
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$stmt->execute([$id]);
$o = $stmt->fetch();
if (!$o) { echo "Bestelling niet gevonden"; exit; }

$ids = user_descendant_ids((int)$u['id']);
$ids[] = (int)$u['id'];
if (!in_array((int)$o['ordered_by_user_id'], $ids, true)) { http_response_code(403); echo "Geen toegang."; exit; }
if ($o['status'] !== 'concept') { echo "Alleen conceptbestellingen kunnen worden ingediend."; exit; }

$upd = $pdo->prepare("UPDATE orders SET status='awaiting_activation', updated_at=NOW() WHERE id=?");
$upd->execute([$id]);
redirect('order_edit', ['id'=>$id]);

<?php
$u = auth_user();
if ($u['role'] !== ROLE_SUPER) { http_response_code(403); echo "Alleen super-admin."; exit; }
global $pdo;
$id = (int)($_GET['id'] ?? 0);
$st = $_POST['status'] ?? '';
$notes = trim($_POST['notes'] ?? '');
$valid = ['concept','awaiting_activation','cancelled','completed'];
if (!in_array($st, $valid, true)) { echo "Ongeldige status"; exit; }

$extra = "";
if ($st === 'completed') $extra = ", activated_at = NOW()";
if ($st === 'cancelled') $extra = ", cancelled_at = NOW()";

$stmt = $pdo->prepare("UPDATE orders SET status=?, notes=CONCAT(COALESCE(notes,''), CASE WHEN ?<>'' THEN CONCAT('\n[Admin] ', ?, ' — ', NOW()) ELSE '' END), updated_at=NOW() $extra WHERE id=?");
$stmt->execute([$st, $notes, $notes, $id]);
redirect('order_edit', ['id'=>$id]);

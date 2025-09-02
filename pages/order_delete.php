<?php
// pages/order_delete.php
$u = auth_user();
global $pdo;

$id = (int)($_POST['id'] ?? 0);
if (!$id) { redirect('orders_list', ['error'=>'Ongeldig order-ID.']); }

// Haal de order op
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { redirect('orders_list', ['error'=>'Bestelling niet gevonden.']); }

// Rechtencheck
$canDelete = false;
if ($u['role'] === ROLE_SUPER) {
    $canDelete = true;
} elseif (in_array($u['role'], [ROLE_RESELLER, ROLE_SUBRESELLER], true)) {
    $ids = user_descendant_ids((int)$u['id']); // alle onderliggende
    $ids[] = (int)$u['id'];                    // en jezelf
    // Reseller mag alleen verwijderen als de order in zijn boom valt
    if (in_array((int)$order['customer_id'], $ids, true) || in_array((int)$order['ordered_by_user_id'], $ids, true)) {
        $canDelete = true;
    }
}
if (!$canDelete) {
    http_response_code(403);
    echo "Geen rechten om deze bestelling te verwijderen.";
    exit;
}

// Verwijderen
try {
    $del = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $del->execute([$id]);
    redirect('orders_list', ['msg'=>'Bestelling verwijderd.']);
} catch (Throwable $e) {
    redirect('orders_list', ['error'=>'Verwijderen mislukt: '.$e->getMessage()]);
}
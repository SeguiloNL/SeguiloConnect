<?php
require_once __DIR__ . '/../helpers.php';
require_super_admin();
csrf_verify_or_die($_POST['_csrf'] ?? null);

$db = db();
$id = (int)($_POST['id'] ?? 0);
$active = (int)($_POST['active'] ?? 0);

$st = $db->prepare("UPDATE users SET is_active=? WHERE id=?");
$st->execute([$active,$id]);

flash('success', $active ? 'Gebruiker geactiveerd.' : 'Gebruiker geblokkeerd.');
redirect('admin_users');
<?php
require_once __DIR__ . '/../helpers.php';
require_super_admin();
csrf_verify_or_die($_POST['_csrf'] ?? null);

$db   = db();
$id   = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$email= trim($_POST['email'] ?? '');
$role = trim($_POST['role'] ?? 'reseller');
$act  = isset($_POST['is_active']) ? 1 : 0;

if (!$name || !$email || !$role) {
  flash('error','Ontbrekende velden.');
  redirect($id ? 'admin_user_edit&id='.$id : 'admin_user_edit');
}

if ($id) {
  $st = $db->prepare("UPDATE users SET name=?, email=?, role=?, is_active=? WHERE id=?");
  $st->execute([$name,$email,$role,$act,$id]);
  flash('success','Gebruiker bijgewerkt.');
} else {
  $temp = $_POST['temp_password'] ?? '';
  if (strlen($temp) < 10) {
    flash('error','Tijdelijk wachtwoord is te zwak (min. 10 tekens).');
    redirect('admin_user_edit');
  }
  $hash = password_hash($temp, PASSWORD_BCRYPT, ['cost'=>12]);
  $st = $db->prepare("INSERT INTO users(name,email,password_hash,role,is_active,created_at) VALUES(?,?,?,?,?,NOW())");
  $st->execute([$name,$email,$hash,$role,$act]);
  flash('success','Gebruiker aangemaakt.');
}
redirect('admin_users');
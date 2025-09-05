<?php
require_once __DIR__ . '/../helpers.php';
require_login();
csrf_verify_or_die($_POST['_csrf'] ?? null);

$db   = db();
$user = auth_user();

$current = $_POST['current'] ?? '';
$new     = $_POST['new'] ?? '';
$confirm = $_POST['confirm'] ?? '';

if (!password_verify($current, $user['password_hash'])) {
    flash('error', 'Huidig wachtwoord klopt niet.');
    redirect('profile');
}
if ($new !== $confirm) {
    flash('error', 'Nieuwe wachtwoorden komen niet overeen.');
    redirect('profile');
}
// simpele sterktecheck
if (strlen($new) < 10 || !preg_match('/[A-Za-z]/',$new) || !preg_match('/\d/',$new)) {
    flash('error', 'Nieuw wachtwoord is te zwak (min. 10 tekens, mix letters en cijfers).');
    redirect('profile');
}

$newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $db->prepare("UPDATE users SET password_hash = ?, password_updated_at = NOW() WHERE id = ?");
$stmt->execute([$newHash, $user['id']]);

flash('success', 'Wachtwoord bijgewerkt.');
redirect('profile');
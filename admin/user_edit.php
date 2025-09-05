<?php
require_once __DIR__ . '/../helpers.php';
require_super_admin();
$db = db();
$id = (int)($_GET['id'] ?? 0);
$row = null;
if ($id) {
  $st = $db->prepare("SELECT * FROM users WHERE id=?");
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
}
csrf_boot();
?>
<div class="container">
  <h1><?= $id ? 'Gebruiker bewerken' : 'Nieuwe gebruiker' ?></h1>
  <form method="post" action="<?= url('admin_do_user_save') ?>">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div>
      <label>Naam</label>
      <input type="text" name="name" required value="<?= e($row['name'] ?? '') ?>">
    </div>
    <div>
      <label>Email</label>
      <input type="email" name="email" required value="<?= e($row['email'] ?? '') ?>">
    </div>
    <div>
      <label>Rol</label>
      <select name="role" required>
        <?php
        $roles = ['super_admin','reseller','sub_reseller','end_customer'];
        $curr = $row['role'] ?? 'reseller';
        foreach ($roles as $role) {
          $sel = $curr === $role ? 'selected' : '';
          echo "<option $sel>$role</option>";
        }
        ?>
      </select>
    </div>
    <?php if (!$id): ?>
    <div>
      <label>Tijdelijk wachtwoord</label>
      <input type="text" name="temp_password" required minlength="10">
      <small>Wordt als initieel wachtwoord gezet; gebruiker kan het daarna wijzigen.</small>
    </div>
    <?php endif; ?>
    <div>
      <label>Actief</label>
      <input type="checkbox" name="is_active" <?= !isset($row) || ($row['is_active'] ?? 1) ? 'checked' : '' ?>>
    </div>
    <button type="submit">Opslaan</button>
  </form>
</div>
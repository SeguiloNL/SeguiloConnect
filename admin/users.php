<?php
require_once __DIR__ . '/../helpers.php';
require_super_admin();
$db = db();

$q = $db->query("SELECT id,name,email,role,is_active,created_at FROM users ORDER BY created_at DESC");
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
csrf_boot();
?>
<div class="container">
  <h1>Gebruikers</h1>
  <p><a class="btn" href="<?= url('admin_user_edit') ?>">+ Nieuwe gebruiker</a></p>
  <table class="table">
    <thead><tr><th>Naam</th><th>Email</th><th>Rol</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e($r['email']) ?></td>
        <td><?= e($r['role']) ?></td>
        <td><?= $r['is_active'] ? 'Actief' : 'Geblokkeerd' ?></td>
        <td>
          <a href="<?= url('admin_user_edit', ['id'=>$r['id']]) ?>">Bewerken</a>
          |
          <form style="display:inline" method="post" action="<?= url('admin_do_user_toggle') ?>">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="active" value="<?= $r['is_active'] ? 0 : 1 ?>">
            <button type="submit"><?= $r['is_active'] ? 'Blokkeren' : 'Activeren' ?></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
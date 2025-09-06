<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Gebruikers</h3>
  <a href="/index.php?page=user_edit" class="btn btn-primary"><i class="bi bi-plus"></i> Nieuwe gebruiker</a>
</div>
<table class="table table-hover bg-white shadow-sm">
  <thead><tr>
    <th>ID</th><th>E-mail</th><th>Rol</th><th>Bedrijfsnaam</th><th>Actief</th><th>Acties</th>
  </tr></thead>
  <tbody>
  <?php foreach($users as $u): ?>
    <tr>
      <td><?= e($u['id']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><?= e($u['role']) ?></td>
      <td><?= e($u['company_name']) ?></td>
      <td><?= $u['is_active'] ? 'Ja' : 'Nee' ?></td>
      <td>
        <a class="btn btn-sm btn-outline-secondary" href="/index.php?page=user_edit&id=<?= e($u['id']) ?>"><i class="bi bi-pencil"></i></a>
        <?php if(is_super() && (int)$u['id'] !== (int)current_user()['id']): ?>
          <a class="btn btn-sm btn-outline-danger" href="/index.php?page=user_delete&id=<?= e($u['id']) ?>" onclick="return confirm('Verwijderen?')"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__.'/partials/footer.php'; ?>

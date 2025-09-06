<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Plannen</h3>
  <a href="/index.php?page=plan_edit" class="btn btn-primary"><i class="bi bi-plus"></i> Nieuw plan</a>
</div>
<table class="table table-hover bg-white shadow-sm">
  <thead><tr><th>ID</th><th>Naam</th><th>Beschrijving</th><th>Maandprijs</th><th>Acties</th></tr></thead>
  <tbody>
    <?php foreach($plans as $p): ?>
      <tr>
        <td><?= e($p['id']) ?></td>
        <td><?= e($p['name']) ?></td>
        <td><?= e($p['description']) ?></td>
        <td>€ <?= e($p['monthly_price']) ?></td>
        <td><a class="btn btn-sm btn-outline-secondary" href="/index.php?page=plan_edit&id=<?= e($p['id']) ?>"><i class="bi bi-pencil"></i></a>
            <a class="btn btn-sm btn-outline-danger" href="/index.php?page=plan_delete&id=<?= e($p['id']) ?>" onclick="return confirm('Verwijderen?')"><i class="bi bi-trash"></i></a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__.'/partials/footer.php'; ?>

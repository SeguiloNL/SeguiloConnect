<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Leveranciers</h3>
  <a href="/index.php?page=supplier_edit" class="btn btn-primary"><i class="bi bi-plus"></i> Nieuwe leverancier</a>
</div>
<table class="table table-hover bg-white shadow-sm">
  <thead><tr><th>ID</th><th>Naam</th><th>API base URL</th><th>Acties</th></tr></thead>
  <tbody>
    <?php foreach($suppliers as $s): ?>
      <tr>
        <td><?= e($s['id']) ?></td>
        <td><?= e($s['name']) ?></td>
        <td><?= e($s['api_base_url']) ?></td>
        <td><a class="btn btn-sm btn-outline-secondary" href="/index.php?page=supplier_edit&id=<?= e($s['id']) ?>"><i class="bi bi-pencil"></i></a>
            <a class="btn btn-sm btn-outline-danger" href="/index.php?page=supplier_delete&id=<?= e($s['id']) ?>" onclick="return confirm('Verwijderen?')"><i class="bi bi-trash"></i></a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__.'/partials/footer.php'; ?>

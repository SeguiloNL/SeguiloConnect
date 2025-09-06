<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Simkaarten</h3>
  <?php if(is_super()): ?>
    <a href="/index.php?page=simcard_edit" class="btn btn-primary"><i class="bi bi-plus"></i> Nieuwe simkaart</a>
  <?php endif; ?>
</div>
<table class="table table-hover bg-white shadow-sm">
  <thead><tr>
    <th>ID</th><th>ICCID</th><th>Status</th><th>Toegewezen aan</th><th>Acties</th>
  </tr></thead>
  <tbody>
  <?php foreach($simcards as $s): ?>
    <tr>
      <td><?= e($s['id']) ?></td>
      <td><?= e($s['iccid']) ?></td>
      <td><?= e($s['status']) ?></td>
      <td><?= e($s['assigned_to'] ?? '—') ?></td>
      <td>
        <a class="btn btn-sm btn-outline-secondary" href="/index.php?page=simcard_edit&id=<?= e($s['id']) ?>"><i class="bi bi-pencil"></i></a>
        <?php if(is_super()): ?>
          <a class="btn btn-sm btn-outline-danger" href="/index.php?page=simcard_delete&id=<?= e($s['id']) ?>" onclick="return confirm('Verwijderen?')"><i class="bi bi-trash"></i></a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__.'/partials/footer.php'; ?>

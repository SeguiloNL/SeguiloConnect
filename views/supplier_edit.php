<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3"><?= isset($supplier) ? 'Bewerk leverancier' : 'Nieuwe leverancier' ?></h3>
    <form method="post" action="/index.php?page=supplier_save">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= e($supplier['id'] ?? '') ?>">
      <div class="mb-3">
        <label class="form-label">Naam</label>
        <input name="name" class="form-control" value="<?= e($supplier['name'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">API Base URL</label>
        <input name="api_base_url" class="form-control" value="<?= e($supplier['api_base_url'] ?? '') ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Opmerkingen</label>
        <textarea name="notes" class="form-control"><?= e($supplier['notes'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-primary"><i class="bi bi-save"></i> Opslaan</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

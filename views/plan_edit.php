<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3"><?= isset($plan) ? 'Bewerk plan' : 'Nieuw plan' ?></h3>
    <form method="post" action="/index.php?page=plan_save">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= e($plan['id'] ?? '') ?>">
      <div class="mb-3">
        <label class="form-label">Naam</label>
        <input name="name" class="form-control" value="<?= e($plan['name'] ?? '') ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Beschrijving</label>
        <textarea name="description" class="form-control"><?= e($plan['description'] ?? '') ?></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Maandprijs (EUR)</label>
        <input name="monthly_price" type="number" step="0.01" class="form-control" value="<?= e($plan['monthly_price'] ?? '0.00') ?>" required>
      </div>
      <button class="btn btn-primary"><i class="bi bi-save"></i> Opslaan</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

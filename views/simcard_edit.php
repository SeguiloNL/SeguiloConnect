<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3"><?= isset($sim) ? 'Bewerk simkaart' : 'Nieuwe simkaart' ?></h3>
    <form method="post" action="/index.php?page=simcard_save">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= e($sim['id'] ?? '') ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">ICCID</label>
          <input name="iccid" class="form-control" value="<?= e($sim['iccid'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach(['niet_actief','toegewezen','actief'] as $st): ?>
              <option value="<?= e($st) ?>" <?php if(($sim['status'] ?? '')===$st) echo 'selected'; ?>><?= e($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Toewijzen aan (user ID, leeg = voorraad)</label>
          <input name="assigned_to" type="number" class="form-control" value="<?= e($sim['assigned_to'] ?? '') ?>">
          <div class="form-text">Super-admin wijst toe aan resellers. Resellers verder aan sub-resellers of eindklanten.</div>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Opslaan</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

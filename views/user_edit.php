<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3"><?= isset($user) ? 'Bewerk gebruiker' : 'Nieuwe gebruiker' ?></h3>
    <form method="post" action="/index.php?page=user_save">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= e($user['id'] ?? '') ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">E-mail</label>
          <input name="email" type="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Wachtwoord <?= isset($user) ? '(leeg = ongewijzigd)' : '' ?></label>
          <input name="password" type="password" class="form-control" <?= isset($user) ? '' : 'required' ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label">Rol</label>
          <select name="role" class="form-select" required>
            <?php foreach(['super_admin','reseller','sub_reseller','end_customer'] as $r): ?>
              <option value="<?= e($r) ?>" <?php if(($user['role'] ?? '') === $r) echo 'selected'; ?>><?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Parent gebruiker (ID)</label>
          <input name="parent_id" type="number" class="form-control" value="<?= e($user['parent_id'] ?? '') ?>" placeholder="Leeg voor top-level">
        </div>
        <div class="col-md-4">
          <label class="form-label">Actief</label>
          <select name="is_active" class="form-select">
            <option value="1" <?= !isset($user) || ($user['is_active'] ?? 1) ? 'selected' : '' ?>>Ja</option>
            <option value="0" <?= isset($user) && !$user['is_active'] ? 'selected' : '' ?>>Nee</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Bedrijfsnaam</label>
          <input name="company_name" class="form-control" value="<?= e($user['company_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Adres</label>
          <input name="address" class="form-control" value="<?= e($user['address'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Postcode</label>
          <input name="postal_code" class="form-control" value="<?= e($user['postal_code'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Plaats</label>
          <input name="city" class="form-control" value="<?= e($user['city'] ?? '') ?>">
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-save"></i> Opslaan</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

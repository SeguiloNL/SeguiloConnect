<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3"><?= isset($order) ? 'Bewerk bestelling' : 'Nieuwe bestelling' ?></h3>
    <?php if(isset($order) && $order['status'] !== 'concept' && !is_super()): ?>
      <div class="alert alert-warning">Deze bestelling is definitief en kan niet meer worden bewerkt.</div>
    <?php else: ?>
    <form method="post" action="/index.php?page=order_save">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= e($order['id'] ?? '') ?>">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Plan</label>
          <select name="plan_id" class="form-select" required>
            <?php foreach($plans as $p): ?>
              <option value="<?= e($p['id']) ?>" <?php if(($order['plan_id'] ?? '')==$p['id']) echo 'selected'; ?>>
                <?= e($p['name']) ?> (€ <?= e($p['monthly_price']) ?> / mnd)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">SIM kaart</label>
          <select name="sim_card_id" class="form-select" required>
            <?php foreach($sims as $s): ?>
              <option value="<?= e($s['id']) ?>" <?php if(($order['sim_card_id'] ?? '')==$s['id']) echo 'selected'; ?>>
                <?= e($s['iccid']) ?> (<?= e($s['status']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Eindklant (user ID)</label>
          <select name="end_customer_id" class="form-select" required>
            <?php foreach($end_customers as $ec): ?>
              <option value="<?= e($ec['id']) ?>" <?php if(($order['end_customer_id'] ?? '')==$ec['id']) echo 'selected'; ?>>
                <?= e($ec['email']) ?> (ID <?= e($ec['id']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-save"></i> Opslaan</button>
        <?php if(!isset($order) || $order['status']==='concept'): ?>
          <button name="finalize" value="1" class="btn btn-success"><i class="bi bi-check2-circle"></i> Definitief plaatsen</button>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

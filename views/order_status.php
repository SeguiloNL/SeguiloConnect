<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3">Order #<?= e($order['id']) ?> status</h3>
    <form method="post" action="/index.php?page=order_status&id=<?= e($order['id']) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="mb-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <?php foreach(['concept','wachten_op_activatie','geannuleerd','voltooid'] as $st): ?>
            <option value="<?= e($st) ?>" <?php if($order['status']===$st) echo 'selected'; ?>><?= e($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-primary"><i class="bi bi-save"></i> Opslaan</button>
    </form>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

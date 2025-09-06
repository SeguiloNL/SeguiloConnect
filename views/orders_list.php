<?php require __DIR__.'/partials/header.php'; require_login(); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">Bestellingen</h3>
  <?php if(!is_endcustomer()): ?>
    <a href="/index.php?page=order_edit" class="btn btn-primary"><i class="bi bi-plus"></i> Nieuwe bestelling</a>
  <?php endif; ?>
</div>
<table class="table table-hover bg-white shadow-sm">
  <thead><tr>
    <th>ID</th><th>Status</th><th>Plan</th><th>SIM ICCID</th><th>Eindklant</th><th>Aangemaakt door</th><th>Acties</th>
  </tr></thead>
  <tbody>
  <?php foreach($orders as $o): ?>
    <tr>
      <td><?= e($o['id']) ?></td>
      <td><?= e($o['status']) ?></td>
      <td><?= e($o['plan_name']) ?></td>
      <td><?= e($o['iccid']) ?></td>
      <td><?= e($o['end_customer_email']) ?></td>
      <td><?= e($o['created_by_email']) ?></td>
      <td>
        <a class="btn btn-sm btn-outline-secondary" href="/index.php?page=order_edit&id=<?= e($o['id']) ?>"><i class="bi bi-pencil"></i></a>
        <?php if(is_super()): ?>
          <a class="btn btn-sm btn-outline-primary" href="/index.php?page=order_status&id=<?= e($o['id']) ?>"><i class="bi bi-sliders"></i> Status</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php require __DIR__.'/partials/footer.php'; ?>

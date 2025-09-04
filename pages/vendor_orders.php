<?php
// pages/vendor_orders.php
require_role('Super-admin');
$pdo = get_pdo();

// Pas filter aan jouw workflow (bijv. status = 'paid' of 'ready')
$stmt = $pdo->query("
  SELECT o.*
  FROM orders o
  WHERE (o.status IN ('paid','ready'))
    AND (o.supplier_status IS NULL OR o.supplier_status NOT IN ('activated','completed'))
  ORDER BY o.created_at DESC
");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Haal setting op om te tonen of API actief is
$cfg = $pdo->query("SELECT * FROM vendor_api_settings WHERE name='apicontrolcenter' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<div class="container">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="mb-0">Order activatie – Leverancier</h1>
    <div>
      <a class="btn btn-outline-secondary" href="index.php?route=vendor_settings">Instellingen</a>
    </div>
  </div>

  <?php if (!$cfg || !$cfg['is_active']): ?>
    <div class="alert alert-warning">API is niet geconfigureerd of inactief. <a href="index.php?route=vendor_settings">Instellen</a></div>
  <?php endif; ?>

  <table class="table table-striped align-middle">
    <thead>
      <tr>
        <th>ID</th>
        <th>Klant</th>
        <th>Totaal</th>
        <th>Status</th>
        <th>Leverancier</th>
        <th>Acties</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>#<?=htmlspecialchars($o['id'])?></td>
          <td><?=htmlspecialchars($o['company_name'] ?? ($o['first_name'].' '.$o['last_name']))?></td>
          <td>€ <?=number_format($o['grand_total'] ?? 0, 2, ',', '.')?></td>
          <td><?=htmlspecialchars($o['status'])?></td>
          <td><?=htmlspecialchars($o['supplier_status'] ?? '—')?></td>
          <td>
            <a href="index.php?route=vendor_order_activate&order_id=<?=$o['id']?>"
               class="btn btn-primary btn-sm"
               onclick="return confirm('Deze order bij de leverancier activeren?');">
              Activeer bij leverancier
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?>
        <tr><td colspan="6" class="text-muted">Niets te activeren.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
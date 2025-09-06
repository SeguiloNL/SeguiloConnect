<?php require __DIR__.'/partials/header.php'; require_role('super_admin'); ?>
<div class="card shadow-sm">
  <div class="card-body">
    <h3 class="mb-3">/admin</h3>
    <p>Beheer systeemgebruikers, plannen en leveranciers.</p>
    <ul>
      <li><a href="/index.php?page=users">Systeemgebruikers</a></li>
      <li><a href="/index.php?page=plans">Plannen</a></li>
      <li><a href="/index.php?page=suppliers">Leveranciers</a></li>
    </ul>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

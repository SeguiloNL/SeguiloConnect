<?php require __DIR__.'/partials/header.php'; ?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h3 class="mb-3">Inloggen</h3>
        <form method="post" action="/index.php?page=login">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label">E-mail</label>
            <input name="email" type="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Wachtwoord</label>
            <input name="password" type="password" class="form-control" required>
          </div>
          <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Inloggen</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__.'/partials/footer.php'; ?>

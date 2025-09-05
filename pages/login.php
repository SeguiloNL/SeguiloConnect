<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <h4 class="mb-3 text-center"><i class="bi bi-box-arrow-in-right me-1"></i> Inloggen</h4>
    <?= function_exists('flash_output') ? flash_output() : '' ?>
    <form method="post" action="<?= url('do_login') ?>">
      <?php if (function_exists('csrf_field')) csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label"><i class="bi bi-envelope me-1"></i> E-mail</label>
        <input type="email" class="form-control" name="email" required>
      </div>
      <div class="mb-3">
        <label class="form-label"><i class="bi bi-key me-1"></i> Wachtwoord</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary"><i class="bi bi-door-open me-1"></i> Inloggen</button>
      </div>
    </form>
  </div>
</div>

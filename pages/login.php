<?php
// pages/login.php
require_once __DIR__ . '/../helpers.php';
app_session_start();
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <h4 class="mb-3 text-center">Inloggen</h4>

    <?php if (function_exists('flash_output')): ?>
      <?= flash_output(); ?>
    <?php endif; ?>

    <form method="post" action="index.php?route=do_login">
      <?php if (function_exists('csrf_field')) csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label">E-mailadres</label>
        <input type="email" class="form-control" name="email" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Wachtwoord</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary">Inloggen</button>
      </div>
    </form>

    <div class="mt-3 text-center">
      <a href="index.php?route=forgot_password">Wachtwoord vergeten?</a>
    </div>
  </div>
</div>
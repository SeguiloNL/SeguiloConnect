<?php
require_once __DIR__ . '/../helpers.php';
app_session_start();
if (auth_user()) { header('Location: index.php?route=dashboard'); exit; }
$err = $_GET['error'] ?? '';
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <h3 class="mb-3">Inloggen</h3>
    <?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

    <form method="post" action="index.php?route=do_login&debug=1">
      <?php csrf_field(); ?>
      <div class="mb-3">
        <label class="form-label">E-mail</label>
        <input type="email" class="form-control" name="email" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Wachtwoord</label>
        <input type="password" class="form-control" name="password" required>
      </div>
      <div class="d-grid">
        <button class="btn btn-primary" type="submit">Inloggen</button>
      </div>
    </form>
  </div>
</div>
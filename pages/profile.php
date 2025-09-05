<?php
require_once __DIR__ . '/../helpers.php';
require_login();
$user = auth_user();
csrf_boot();
?>
<div class="container">
  <h1>Profiel</h1>
  <p>Ingelogd als: <strong><?= e($user['email']) ?></strong></p>

  <h2>Wachtwoord wijzigen</h2>
  <form method="post" action="<?= url('do_change_password') ?>">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div>
      <label>Huidig wachtwoord</label>
      <input type="password" name="current" required>
    </div>
    <div>
      <label>Nieuw wachtwoord</label>
      <input type="password" name="new" required minlength="10">
      <small>Min. 10 tekens, graag mix van letters/cijfers/symbool</small>
    </div>
    <div>
      <label>Herhaal nieuw wachtwoord</label>
      <input type="password" name="confirm" required>
    </div>
    <button type="submit">Opslaan</button>
  </form>
</div>
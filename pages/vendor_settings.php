<?php
// pages/vendor_settings.php
require_role('Super-admin');
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare("
    UPDATE vendor_api_settings
       SET base_url = :base_url,
           auth_type = :auth_type,
           api_key = :api_key,
           bearer_token = :bearer_token,
           oauth_client_id = :oauth_client_id,
           oauth_client_secret = :oauth_client_secret,
           oauth_token_endpoint = :oauth_token_endpoint,
           account_id = :account_id,
           is_active = :is_active
     WHERE name = 'apicontrolcenter'
  ");
  $stmt->execute([
    ':base_url' => trim($_POST['base_url']),
    ':auth_type' => $_POST['auth_type'],
    ':api_key' => $_POST['api_key'] ?: null,
    ':bearer_token' => $_POST['bearer_token'] ?: null,
    ':oauth_client_id' => $_POST['oauth_client_id'] ?: null,
    ':oauth_client_secret' => $_POST['oauth_client_secret'] ?: null,
    ':oauth_token_endpoint' => $_POST['oauth_token_endpoint'] ?: null,
    ':account_id' => $_POST['account_id'] ?: null,
    ':is_active' => isset($_POST['is_active']) ? 1 : 0,
  ]);
  $msg = "Instellingen opgeslagen.";
}

$stmt = $pdo->query("SELECT * FROM vendor_api_settings WHERE name = 'apicontrolcenter' LIMIT 1");
$cfg = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container">
  <h1 class="mb-3">Leverancier API – Instellingen</h1>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-success"><?=$msg?></div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Base URL</label>
      <input type="url" name="base_url" class="form-control" value="<?=htmlspecialchars($cfg['base_url'] ?? '')?>" required>
      <div class="form-text">Bijv. <code>https://apicontrolcenter.com</code></div>
    </div>

    <div class="col-md-6">
      <label class="form-label">Auth type</label>
      <select name="auth_type" class="form-select">
        <?php foreach (['bearer_token','api_key','oauth2'] as $opt): ?>
          <option value="<?=$opt?>" <?=($cfg['auth_type']??'')===$opt?'selected':''?>><?=$opt?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">Bearer token</label>
      <input type="text" name="bearer_token" class="form-control" value="<?=htmlspecialchars($cfg['bearer_token'] ?? '')?>">
    </div>

    <div class="col-md-6">
      <label class="form-label">API key</label>
      <input type="text" name="api_key" class="form-control" value="<?=htmlspecialchars($cfg['api_key'] ?? '')?>">
    </div>

    <div class="col-md-4">
      <label class="form-label">OAuth Client ID</label>
      <input type="text" name="oauth_client_id" class="form-control" value="<?=htmlspecialchars($cfg['oauth_client_id'] ?? '')?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">OAuth Client Secret</label>
      <input type="text" name="oauth_client_secret" class="form-control" value="<?=htmlspecialchars($cfg['oauth_client_secret'] ?? '')?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">OAuth Token Endpoint</label>
      <input type="url" name="oauth_token_endpoint" class="form-control" value="<?=htmlspecialchars($cfg['oauth_token_endpoint'] ?? '')?>">
    </div>

    <div class="col-md-6">
      <label class="form-label">Account ID (leverancier)</label>
      <input type="text" name="account_id" class="form-control" value="<?=htmlspecialchars($cfg['account_id'] ?? '')?>">
      <div class="form-text">Veel API’s eisen een AccountId in de payload.</div>
    </div>

    <div class="col-md-6 form-check mt-4">
      <input type="checkbox" name="is_active" class="form-check-input" id="is_active" <?=!empty($cfg['is_active'])?'checked':''?>>
      <label class="form-check-label" for="is_active">Actief</label>
    </div>

    <div class="col-12">
      <button class="btn btn-primary">Opslaan</button>
      <a class="btn btn-outline-secondary" href="index.php?route=vendor_orders">Naar te activeren bestellingen</a>
    </div>
  </form>
</div>
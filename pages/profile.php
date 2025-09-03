<?php
// pages/profile.php — “Mijn profiel” voor ingelogde gebruiker
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

// ---------------- Helpers ----------------
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function ensure_user_meta(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_meta (
      user_id INT NOT NULL,
      k VARCHAR(64) NOT NULL,
      v TEXT NULL,
      PRIMARY KEY (user_id, k),
      KEY idx_meta_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function user_meta_get(PDO $pdo, int $userId, string $key): ?string {
  ensure_user_meta($pdo);
  $st = $pdo->prepare("SELECT v FROM user_meta WHERE user_id=? AND k=?");
  $st->execute([$userId, $key]);
  $v = $st->fetchColumn();
  return $v === false ? null : (string)$v;
}
function user_meta_set(PDO $pdo, int $userId, string $key, ?string $val): void {
  ensure_user_meta($pdo);
  $st = $pdo->prepare("INSERT INTO user_meta (user_id,k,v) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $st->execute([$userId, $key, $val]);
}
function ensure_user_addresses(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS user_addresses (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      type ENUM('admin','connect') NOT NULL,
      contact VARCHAR(255) DEFAULT NULL,
      address VARCHAR(255) DEFAULT NULL,
      postcode VARCHAR(32) DEFAULT NULL,
      city VARCHAR(255) DEFAULT NULL,
      is_current TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user_type (user_id,type),
      CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}
function current_avatar_url(PDO $pdo, int $userId): ?string {
  // 1) users.avatar_url
  if (column_exists($pdo,'users','avatar_url')) {
    $st = $pdo->prepare("SELECT avatar_url FROM users WHERE id=?");
    $st->execute([$userId]);
    $v = $st->fetchColumn();
    if ($v !== false && $v !== null && $v !== '') return (string)$v;
  }
  // 2) user_meta('avatar_url')
  $meta = user_meta_get($pdo, $userId, 'avatar_url');
  return $meta ?: null;
}
function save_avatar_url(PDO $pdo, int $userId, ?string $url): void {
  if (column_exists($pdo,'users','avatar_url')) {
    $st = $pdo->prepare("UPDATE users SET avatar_url=? WHERE id=?");
    $st->execute([$url, $userId]);
    return;
  }
  user_meta_set($pdo, $userId, 'avatar_url', $url);
}

$errors = [];
$success = [];

// ---------------- POST acties ----------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { $errors[] = 'Sessie verlopen. Probeer opnieuw.'; }

  $action = (string)($_POST['action'] ?? '');

  if (!$errors) {
    try {
      switch ($action) {

        // NAAM WIJZIGEN
        case 'save_name': {
          $name = trim((string)($_POST['name'] ?? ''));
          if ($name === '') { $errors[] = 'Naam is verplicht.'; break; }
          $st = $pdo->prepare("UPDATE users SET name=? WHERE id=?");
          $st->execute([$name, (int)$me['id']]);
          $success[] = 'Naam opgeslagen.';
          break;
        }

        // AVATAR UPLOAD
        case 'upload_avatar': {
          if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload mislukt. Kies een afbeelding.';
            break;
          }
          $file = $_FILES['avatar'];
          if ($file['size'] > 5*1024*1024) { // 5MB
            $errors[] = 'Bestand is te groot (max 5MB).';
            break;
          }
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $mime  = finfo_file($finfo, $file['tmp_name']);
          finfo_close($finfo);
          $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
          if (!isset($allowed[$mime])) {
            $errors[] = 'Ongeldig bestandstype. Toegestaan: JPG/PNG/GIF/WebP/SVG.';
            break;
          }
          // pad
          $dir = __DIR__ . '/../uploads/avatars';
          if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
          if (!is_dir($dir) || !is_writable($dir)) {
            $errors[] = 'Uploadmap niet schrijfbaar: /uploads/avatars';
            break;
          }
          $ext = $allowed[$mime];
          $fname = 'u'.$me['id'].'_'.time().'.'.$ext;
          $dest = $dir.'/'.$fname;
          if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $errors[] = 'Kon bestand niet verplaatsen.';
            break;
          }
          // oude verwijderen (optioneel)
          $old = current_avatar_url($pdo, (int)$me['id']);
          if ($old && str_starts_with($old, base_url().'/uploads/avatars/')) {
            $oldPath = __DIR__.'/..'.str_replace(base_url(), '', $old);
            if (is_file($oldPath)) @unlink($oldPath);
          }
          $publicUrl = rtrim(base_url(),'/').'/uploads/avatars/'.$fname;
          save_avatar_url($pdo, (int)$me['id'], $publicUrl);
          $success[] = 'Profielfoto bijgewerkt.';
          break;
        }

        // AVATAR VERWIJDEREN
        case 'delete_avatar': {
          $old = current_avatar_url($pdo, (int)$me['id']);
          if ($old && str_starts_with($old, base_url().'/uploads/avatars/')) {
            $oldPath = __DIR__.'/..'.str_replace(base_url(), '', $old);
            if (is_file($oldPath)) @unlink($oldPath);
          }
          save_avatar_url($pdo, (int)$me['id'], null);
          $success[] = 'Profielfoto verwijderd.';
          break;
        }

        // ADRES TOEVOEGEN
        case 'add_address': {
          ensure_user_addresses($pdo);
          $type     = (string)($_POST['addr_type'] ?? '');
          $contact  = trim((string)($_POST['contact'] ?? ''));
          $address  = trim((string)($_POST['address'] ?? ''));
          $postcode = trim((string)($_POST['postcode'] ?? ''));
          $city     = trim((string)($_POST['city'] ?? ''));
          $makeCurrent = (int)($_POST['is_current'] ?? 0);

          if (!in_array($type, ['admin','connect'], true)) { $errors[]='Ongeldig adrestype.'; break; }
          if ($address === '' && $contact === '' && $city === '' && $postcode === '') {
            $errors[] = 'Adres is leeg.';
            break;
          }

          $pdo->beginTransaction();
          if ($makeCurrent === 1) {
            $st = $pdo->prepare("UPDATE user_addresses SET is_current=0 WHERE user_id=? AND type=?");
            $st->execute([(int)$me['id'], $type]);
          }
          $st = $pdo->prepare("
            INSERT INTO user_addresses (user_id,type,contact,address,postcode,city,is_current)
            VALUES (?,?,?,?,?,?,?)
          ");
          $st->execute([(int)$me['id'],$type,$contact,$address,$postcode,$city,$makeCurrent]);
          $pdo->commit();

          $success[] = 'Adres toegevoegd.';
          break;
        }

        // ADRES ALS ACTUEEL ZETTEN
        case 'set_current_address': {
          ensure_user_addresses($pdo);
          $addrId = (int)($_POST['address_id'] ?? 0);
          $type   = (string)($_POST['addr_type'] ?? '');
          if ($addrId <= 0 || !in_array($type,['admin','connect'],true)) { $errors[]='Ongeldige invoer.'; break; }

          // check eigendom
          $st = $pdo->prepare("SELECT id FROM user_addresses WHERE id=? AND user_id=? AND type=?");
          $st->execute([$addrId, (int)$me['id'], $type]);
          if (!$st->fetchColumn()) { $errors[]='Adres niet gevonden.'; break; }

          $pdo->beginTransaction();
          $st = $pdo->prepare("UPDATE user_addresses SET is_current=0 WHERE user_id=? AND type=?");
          $st->execute([(int)$me['id'], $type]);
          $st = $pdo->prepare("UPDATE user_addresses SET is_current=1 WHERE id=?");
          $st->execute([$addrId]);
          $pdo->commit();

          $success[] = 'Actueel adres bijgewerkt.';
          break;
        }

        // ADRES VERWIJDEREN
        case 'delete_address': {
          ensure_user_addresses($pdo);
          $addrId = (int)($_POST['address_id'] ?? 0);
          if ($addrId <= 0) { $errors[]='Ongeldig adres.'; break; }
          $st = $pdo->prepare("DELETE FROM user_addresses WHERE id=? AND user_id=?");
          $st->execute([$addrId, (int)$me['id']]);
          $success[] = 'Adres verwijderd.';
          break;
        }

        // WACHTWOORD WIJZIGEN
        case 'change_password': {
          $pwd = (string)($_POST['password'] ?? '');
          $rep = (string)($_POST['password_confirm'] ?? '');
          if (strlen($pwd) < 8) { $errors[]='Wachtwoord moet minimaal 8 tekens zijn.'; break; }
          if ($pwd !== $rep)    { $errors[]='Wachtwoord en bevestiging komen niet overeen.'; break; }
          $hash = password_hash($pwd, PASSWORD_DEFAULT);
          $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
          $st->execute([$hash, (int)$me['id']]);
          $success[] = 'Wachtwoord gewijzigd.';
          break;
        }

        default:
          $errors[] = 'Onbekende actie.';
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'Actie mislukt: '.$e->getMessage();
    }
  }

  // Toon meldingen via flash en herlaad zodat refresh geen re-post is
  if ($errors)  { flash_set('danger','<ul><li>'.implode('</li><li>', array_map('e',$errors)).'</li></ul>'); }
  if ($success) { flash_set('success', implode('<br>', array_map('e',$success))); }
  redirect('index.php?route=profile');
}

// ---------------- Gegevens ophalen voor weergave ----------------

// Huidige gebruiker (vers)
$st = $pdo->prepare("SELECT id,name,email FROM users WHERE id=?");
$st->execute([(int)$me['id']]);
$user = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$avatarUrl = current_avatar_url($pdo, (int)$me['id']);

// adressen
ensure_user_addresses($pdo);
$st = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id=? AND type='admin' ORDER BY is_current DESC, id DESC");
$st->execute([(int)$me['id']]);
$adminAddresses = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT * FROM user_addresses WHERE user_id=? AND type='connect' ORDER BY is_current DESC, id DESC");
$st->execute([(int)$me['id']]);
$connectAddresses = $st->fetchAll(PDO::FETCH_ASSOC);

// fallback: als er nog geen adressen zijn, vul dan (eenmalig) het huidige uit users.* in als record?
// (optioneel; niet automatisch gedaan om verrassingen te voorkomen)

echo function_exists('flash_output') ? flash_output() : '';
?>

<h4>Mijn profiel</h4>

<div class="row g-4">

  <!-- Profielfoto -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Profielfoto</h5>
        <div class="mb-3">
          <?php if ($avatarUrl): ?>
            <img src="<?= e($avatarUrl) ?>" alt="Profielfoto" class="img-thumbnail" style="max-height:180px;">
          <?php else: ?>
            <div class="text-muted">Geen profielfoto ingesteld.</div>
          <?php endif; ?>
        </div>

        <form method="post" enctype="multipart/form-data" class="d-flex gap-2">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="upload_avatar">
          <input type="file" name="avatar" accept="image/*" class="form-control" required>
          <button class="btn btn-primary">Uploaden</button>
        </form>

        <?php if ($avatarUrl): ?>
          <form method="post" class="mt-2">
            <?php if (function_exists('csrf_field')) csrf_field(); ?>
            <input type="hidden" name="action" value="delete_avatar">
            <button class="btn btn-outline-danger" onclick="return confirm('Profielfoto verwijderen?')">Verwijderen</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Basisgegevens + Wachtwoord -->
  <div class="col-12 col-lg-8">
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Persoonlijke gegevens</h5>
        <form method="post" class="row g-3">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="save_name">

          <div class="col-md-6">
            <label class="form-label">Naam</label>
            <input type="text" name="name" class="form-control" value="<?= e($user['name'] ?? '') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">E-mail (alleen-lezen)</label>
            <input type="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" disabled>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Opslaan</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Wachtwoord wijzigen</h5>
        <form method="post" class="row g-3">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="change_password">

          <div class="col-md-6">
            <label class="form-label">Nieuw wachtwoord</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Bevestig nieuw wachtwoord</label>
            <input type="password" name="password_confirm" class="form-control" minlength="8" required>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary">Wijzigen</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Adressen -->
  <div class="col-12">
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Administratieve adressen</h5>

        <!-- Lijst -->
        <?php if ($adminAddresses): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Contact</th>
                  <th>Adres</th>
                  <th>Postcode</th>
                  <th>Plaats</th>
                  <th>Actueel</th>
                  <th class="text-end">Acties</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($adminAddresses as $a): ?>
                  <tr>
                    <td><?= e($a['contact']) ?></td>
                    <td><?= e($a['address']) ?></td>
                    <td><?= e($a['postcode']) ?></td>
                    <td><?= e($a['city']) ?></td>
                    <td><?= $a['is_current'] ? '<span class="badge bg-success">Ja</span>' : '' ?></td>
                    <td class="text-end">
                      <?php if (!$a['is_current']): ?>
                        <form method="post" class="d-inline">
                          <?php if (function_exists('csrf_field')) csrf_field(); ?>
                          <input type="hidden" name="action" value="set_current_address">
                          <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                          <input type="hidden" name="addr_type" value="admin">
                          <button class="btn btn-sm btn-outline-primary">Maak actueel</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Adres verwijderen?');">
                        <?php if (function_exists('csrf_field')) csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_address">
                        <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Verwijderen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Nog geen administratieve adressen.</div>
        <?php endif; ?>

        <hr>
        <h6>Nieuw administratief adres</h6>
        <form method="post" class="row g-2">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="add_address">
          <input type="hidden" name="addr_type" value="admin">

          <div class="col-md-4"><input type="text" name="contact"  class="form-control" placeholder="Contactpersoon"></div>
          <div class="col-md-4"><input type="text" name="address"  class="form-control" placeholder="Adres"></div>
          <div class="col-md-2"><input type="text" name="postcode" class="form-control" placeholder="Postcode"></div>
          <div class="col-md-2"><input type="text" name="city"     class="form-control" placeholder="Plaats"></div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="admin_current" name="is_current" value="1">
              <label for="admin_current" class="form-check-label">Maak dit het actuele administratieve adres</label>
            </div>
          </div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Toevoegen</button></div>
        </form>

      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Aansluitadressen</h5>

        <!-- Lijst -->
        <?php if ($connectAddresses): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Contact</th>
                  <th>Adres</th>
                  <th>Postcode</th>
                  <th>Plaats</th>
                  <th>Actueel</th>
                  <th class="text-end">Acties</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($connectAddresses as $a): ?>
                  <tr>
                    <td><?= e($a['contact']) ?></td>
                    <td><?= e($a['address']) ?></td>
                    <td><?= e($a['postcode']) ?></td>
                    <td><?= e($a['city']) ?></td>
                    <td><?= $a['is_current'] ? '<span class="badge bg-success">Ja</span>' : '' ?></td>
                    <td class="text-end">
                      <?php if (!$a['is_current']): ?>
                        <form method="post" class="d-inline">
                          <?php if (function_exists('csrf_field')) csrf_field(); ?>
                          <input type="hidden" name="action" value="set_current_address">
                          <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                          <input type="hidden" name="addr_type" value="connect">
                          <button class="btn btn-sm btn-outline-primary">Maak actueel</button>
                        </form>
                      <?php endif; ?>
                      <form method="post" class="d-inline" onsubmit="return confirm('Adres verwijderen?');">
                        <?php if (function_exists('csrf_field')) csrf_field(); ?>
                        <input type="hidden" name="action" value="delete_address">
                        <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Verwijderen</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Nog geen aansluitadressen.</div>
        <?php endif; ?>

        <hr>
        <h6>Nieuw aansluitadres</h6>
        <form method="post" class="row g-2">
          <?php if (function_exists('csrf_field')) csrf_field(); ?>
          <input type="hidden" name="action" value="add_address">
          <input type="hidden" name="addr_type" value="connect">

          <div class="col-md-4"><input type="text" name="contact"  class="form-control" placeholder="Contactpersoon"></div>
          <div class="col-md-4"><input type="text" name="address"  class="form-control" placeholder="Adres"></div>
          <div class="col-md-2"><input type="text" name="postcode" class="form-control" placeholder="Postcode"></div>
          <div class="col-md-2"><input type="text" name="city"     class="form-control" placeholder="Plaats"></div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="connect_current" name="is_current" value="1">
              <label for="connect_current" class="form-check-label">Maak dit het actuele aansluitadres</label>
            </div>
          </div>
          <div class="col-12"><button class="btn btn-primary btn-sm">Toevoegen</button></div>
        </form>
      </div>
    </div>
  </div>

</div>
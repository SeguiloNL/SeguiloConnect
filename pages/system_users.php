<?php
// pages/system_users.php — Beheer Systeemgebruikers (rol: super_admin)
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) { header('Location: index.php?route=login'); exit; }

$role    = $me['role'] ?? '';
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
if (!$isSuper) {
  echo '<div class="alert alert-danger">Alleen Super-admin heeft toegang tot Systeemgebruikers.</div>';
  return;
}

try { $pdo = db(); }
catch (Throwable $e) { echo '<div class="alert alert-danger">DB niet beschikbaar: '.e($e->getMessage()).'</div>'; return; }

function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

// ---------- POST acties ----------
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { $errors[] = 'Sessie verlopen. Probeer opnieuw.'; }

  $action = (string)($_POST['action'] ?? '');

  if (!$errors) {
    try {
      switch ($action) {

        // AANMAKEN
        case 'create': {
          $name  = trim((string)($_POST['name']  ?? ''));
          $email = trim((string)($_POST['email'] ?? ''));
          $active= (int)($_POST['is_active'] ?? 1);
          $pwd   = (string)($_POST['password'] ?? '');

          if ($name === '')  $errors[] = 'Naam is verplicht.';
          if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';
          if (strlen($pwd) < 8) $errors[] = 'Wachtwoord moet minimaal 8 tekens zijn.';

          // email uniek
          $st = $pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
          $st->execute([$email]);
          if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';

          if (!$errors) {
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $cols = ['name','email','role','is_active','password_hash'];
            $vals = [$name,$email,'super_admin',$active,$hash];

            // optionele kolommen (parent_user_id niet zetten; systeemusers staan op zichzelf)
            $sql = "INSERT INTO users (".implode(',',$cols).") VALUES (?,?,?,?,?)";
            $st = $pdo->prepare($sql);
            $st->execute($vals);

            flash_set('success','Systeemgebruiker aangemaakt.');
            redirect('index.php?route=system_users');
          }
          break;
        }

        // BEWERKEN
        case 'update': {
          $id    = (int)($_POST['id'] ?? 0);
          $name  = trim((string)($_POST['name']  ?? ''));
          $email = trim((string)($_POST['email'] ?? ''));
          $active= (int)($_POST['is_active'] ?? 1);
          $pwd   = (string)($_POST['password'] ?? '');
          $pwd2  = (string)($_POST['password_confirm'] ?? '');

          // bestaat + is super_admin?
          $st = $pdo->prepare("SELECT id, email FROM users WHERE id=? AND role='super_admin' LIMIT 1");
          $st->execute([$id]);
          $u = $st->fetch(PDO::FETCH_ASSOC);
          if (!$u) { $errors[]='Systeemgebruiker niet gevonden.'; break; }

          if ($name === '')  $errors[] = 'Naam is verplicht.';
          if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';

          // email uniek (exclusief zichzelf)
          $st = $pdo->prepare("SELECT 1 FROM users WHERE email=? AND id<>? LIMIT 1");
          $st->execute([$email,$id]);
          if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';

          $set = ['name = ?','email = ?','is_active = ?'];
          $val = [$name,$email,$active];

          if ($pwd !== '' || $pwd2 !== '') {
            if (strlen($pwd) < 8) $errors[] = 'Wachtwoord moet minimaal 8 tekens zijn.';
            if ($pwd !== $pwd2)   $errors[] = 'Wachtwoorden komen niet overeen.';
            if (!$errors) {
              $set[] = 'password_hash = ?';
              $val[] = password_hash($pwd, PASSWORD_DEFAULT);
            }
          }

          if (!$errors) {
            $val[] = $id;
            $sql = "UPDATE users SET ".implode(', ',$set)." WHERE id=? AND role='super_admin' LIMIT 1";
            $st  = $pdo->prepare($sql);
            $st->execute($val);

            flash_set('success','Systeemgebruiker bijgewerkt.');
            redirect('index.php?route=system_users');
          }
          break;
        }

        // VERWIJDEREN (niet laatste)
        case 'delete': {
          $id = (int)($_POST['id'] ?? 0);
          if ($id <= 0) { $errors[]='Ongeldige gebruiker.'; break; }

          // tel supers
          $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
          if ($total <= 1) {
            $errors[] = 'Je kunt de laatste Super-admin niet verwijderen.';
            break;
          }

          // bestaat + is super_admin?
          $st = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='super_admin' LIMIT 1");
          $st->execute([$id]);
          if (!$st->fetchColumn()) { $errors[]='Systeemgebruiker niet gevonden.'; break; }

          $st = $pdo->prepare("DELETE FROM users WHERE id=? AND role='super_admin' LIMIT 1");
          $st->execute([$id]);

          flash_set('success','Systeemgebruiker verwijderd.');
          redirect('index.php?route=system_users');
          break;
        }

        default:
          $errors[] = 'Onbekende actie.';
      }
    } catch (Throwable $e) {
      $errors[] = 'Actie mislukt: '.$e->getMessage();
    }
  }

  if ($errors) {
    flash_set('danger','<ul class="mb-0"><li>'.implode('</li><li>', array_map('e',$errors)).'</li></ul>');
  }
  redirect('index.php?route=system_users');
}

// ---------- Lijst laden ----------
try {
  $st = $pdo->query("SELECT id,name,email,is_active FROM users WHERE role='super_admin' ORDER BY name");
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

echo function_exists('flash_output') ? flash_output() : '';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4>Systeemgebruikers</h4>
  <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#createForm">Nieuwe systeemgebruiker</button>
</div>

<div id="createForm" class="collapse">
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Aanmaken</h5>
      <form method="post" class="row g-3">
        <?php if (function_exists('csrf_field')) csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-6">
          <label class="form-label">Naam</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Wachtwoord</label>
          <input type="password" name="password" class="form-control" minlength="8" required>
        </div>
        <div class="col-md-4 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" id="is_active_create" value="1" checked>
            <label class="form-check-label" for="is_active_create">Actief</label>
          </div>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success">Opslaan</button>
          <button class="btn btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#createForm">Annuleren</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h5 class="card-title">Overzicht</h5>
    <?php if (!$rows): ?>
      <div class="text-muted">Nog geen systeemgebruikers gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Naam</th>
              <th>E-mail</th>
              <th>Status</th>
              <th class="text-end">Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['id'] ?></td>
                <td><?= e($r['name']) ?></td>
                <td><?= e($r['email']) ?></td>
                <td><?= ((int)$r['is_active'] === 1) ? '<span class="badge bg-success">Actief</span>' : '<span class="badge bg-secondary">Inactief</span>' ?></td>
                <td class="text-end">
                  <!-- Bewerken: collapse per rij -->
                  <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#editForm<?= (int)$r['id'] ?>">Bewerken</button>

                  <!-- Verwijderen -->
                  <form method="post" class="d-inline" onsubmit="return confirm('Systeemgebruiker verwijderen?');">
                    <?php if (function_exists('csrf_field')) csrf_field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger">Verwijderen</button>
                  </form>
                </td>
              </tr>
              <!-- Edit form -->
              <tr class="collapse" id="editForm<?= (int)$r['id'] ?>">
                <td colspan="5">
                  <form method="post" class="row g-2 border rounded p-3 bg-light">
                    <?php if (function_exists('csrf_field')) csrf_field(); ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <div class="col-md-4">
                      <label class="form-label">Naam</label>
                      <input type="text" name="name" class="form-control" required value="<?= e($r['name']) ?>">
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">E-mail</label>
                      <input type="email" name="email" class="form-control" required value="<?= e($r['email']) ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active<?= (int)$r['id'] ?>" value="1" <?= ((int)$r['is_active']===1?'checked':'') ?>>
                        <label class="form-check-label" for="is_active<?= (int)$r['id'] ?>">Actief</label>
                      </div>
                    </div>
                    <div class="col-12"><small class="text-muted">Wachtwoord wijzigen (optioneel)</small></div>
                    <div class="col-md-4">
                      <input type="password" name="password" class="form-control" placeholder="Nieuw wachtwoord">
                    </div>
                    <div class="col-md-4">
                      <input type="password" name="password_confirm" class="form-control" placeholder="Herhaal wachtwoord">
                    </div>
                    <div class="col-12 d-flex gap-2">
                      <button class="btn btn-primary btn-sm">Opslaan</button>
                      <button class="btn btn-light btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#editForm<?= (int)$r['id'] ?>">Sluiten</button>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
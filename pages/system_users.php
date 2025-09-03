<?php
// pages/system_users.php — Beheer Systeemgebruikers (alleen Super-admin)
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
function ensure_settings_table(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
      k VARCHAR(64) PRIMARY KEY,
      v VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

// Bepaal welke kolom voor profielfoto beschikbaar is
$photoField = null;
foreach (['profile_photo_url','profile_image_url','profile_photo','avatar_url'] as $cand) {
  if (column_exists($pdo, 'users', $cand)) { $photoField = $cand; break; }
}

// ---- POST acties (create/update/delete) ----
$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try { if (function_exists('verify_csrf')) verify_csrf(); }
  catch (Throwable $e) { $errors[] = 'Sessie verlopen. Probeer opnieuw.'; }

  $action = (string)($_POST['action'] ?? '');

  if (!$errors) {
    try {
      switch ($action) {
        case 'create': {
          $name  = trim((string)($_POST['name']  ?? ''));
          $email = trim((string)($_POST['email'] ?? ''));
          $active= (int)($_POST['is_active'] ?? 1);
          $pwd   = (string)($_POST['password'] ?? '');

          if ($name === '')  $errors[] = 'Naam is verplicht.';
          if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';
          if (strlen($pwd) < 8) $errors[] = 'Wachtwoord moet minimaal 8 tekens zijn.';

          $st = $pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
          $st->execute([$email]);
          if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';

          if (!$errors) {
            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $cols = ['name','email','role','is_active','password_hash'];
            $vals = [$name,$email,'super_admin',$active,$hash];

            // optioneel: profielfoto meenemen als kolom bestaat en meegegeven is
            if ($photoField && isset($_POST['profile_photo_url']) && $_POST['profile_photo_url'] !== '') {
              $cols[] = $photoField;
              $vals[] = trim((string)$_POST['profile_photo_url']);
            }

            $sql = "INSERT INTO users (".implode(',',$cols).") VALUES (".implode(',',array_fill(0,count($cols),'?')).")";
            $st = $pdo->prepare($sql);
            $st->execute($vals);

            flash_set('success','Systeemgebruiker aangemaakt.');
            redirect('index.php?route=system_users');
          }
          break;
        }

        case 'update': {
          $id    = (int)($_POST['id'] ?? 0);
          $name  = trim((string)($_POST['name']  ?? ''));
          $email = trim((string)($_POST['email'] ?? ''));
          $active= (int)($_POST['is_active'] ?? 1);
          $pwd   = (string)($_POST['password'] ?? '');
          $pwd2  = (string)($_POST['password_confirm'] ?? '');

          $st = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='super_admin' LIMIT 1");
          $st->execute([$id]);
          if (!$st->fetchColumn()) { $errors[]='Systeemgebruiker niet gevonden.'; break; }

          if ($name === '')  $errors[] = 'Naam is verplicht.';
          if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mailadres is ongeldig.';

          $st = $pdo->prepare("SELECT 1 FROM users WHERE email=? AND id<>? LIMIT 1");
          $st->execute([$email,$id]);
          if ($st->fetchColumn()) $errors[] = 'E-mailadres is al in gebruik.';

          $set = ['name = ?','email = ?','is_active = ?'];
          $val = [$name,$email,$active];

          if ($photoField) {
            $set[] = "`$photoField` = ?";
            $val[] = trim((string)($_POST['profile_photo_url'] ?? ''));
          }

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

        case 'delete': {
          $id = (int)($_POST['id'] ?? 0);
          if ($id <= 0) { $errors[]='Ongeldige gebruiker.'; break; }

          $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
          if ($total <= 1) { $errors[] = 'Je kunt de laatste Super-admin niet verwijderen.'; break; }

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

// ---- Paginering ----
$perPage = (int)($_GET['per_page'] ?? 25);
if (!in_array($perPage, [25,50,100], true)) $perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ---- Tellen ----
try {
  $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Tellen mislukt: '.e($e->getMessage()).'</div>'; return;
}
$totalPages = max(1, (int)ceil($total / $perPage));

// ---- Ophalen ----
try {
  $selectPhoto = $photoField ? ", `$photoField` AS profile_photo_url" : ", NULL AS profile_photo_url";
  $sql = "SELECT id, name, email, is_active {$selectPhoto}
          FROM users
          WHERE role='super_admin'
          ORDER BY name ASC, id ASC
          LIMIT $perPage OFFSET $offset";
  $st  = $pdo->prepare($sql);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="alert alert-danger">Laden mislukt: '.e($e->getMessage()).'</div>'; return;
}

// ---- helpers UI ----
function su_url_keep(array $extra): string {
  $base = 'index.php?route=system_users';
  $qs = array_merge($_GET, $extra);
  return $base.'&'.http_build_query($qs);
}
function initials(string $name): string {
  $parts = preg_split('/\s+/', trim($name));
  $first = $parts[0] ?? '';
  $last  = $parts[ count($parts)-1 ] ?? '';
  $ini = mb_strtoupper(mb_substr($first,0,1) . mb_substr($last,0,1));
  return $ini ?: 'U';
}

echo function_exists('flash_output') ? flash_output() : '';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0">Systeemgebruikers</h4>
  <div class="d-flex align-items-center gap-2">
    <form method="get" class="d-flex align-items-center gap-2">
      <input type="hidden" name="route" value="system_users">
      <label class="form-label m-0">Per pagina</label>
      <select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ([25,50,100] as $opt): ?>
          <option value="<?= $opt ?>" <?= $perPage===$opt?'selected':'' ?>><?= $opt ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="page" value="1">
    </form>    
  </div>
</div>

<!-- Aanmaken (optioneel zichtbaar in een uitklapper) -->
<div class="collapse" id="createForm">
  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Nieuwe systeemgebruiker</h5>
      <form method="post" class="row g-3">
        <?php if (function_exists('csrf_field')) csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-4">
          <label class="form-label">Naam</label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">E-mail</label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Wachtwoord</label>
          <input type="password" name="password" class="form-control" minlength="8" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" id="is_active_create" value="1" checked>
            <label class="form-check-label" for="is_active_create">Actief</label>
          </div>
        </div>
        <?php if ($photoField): ?>
          <div class="col-12">
            <label class="form-label">Profielfoto URL (optioneel)</label>
            <input type="url" name="profile_photo_url" class="form-control" placeholder="https://…/avatar.png">
          </div>
        <?php endif; ?>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Opslaan</button>
          <button class="btn btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#createForm">Annuleren</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="d-flex justify-content-end mb-2">
  <button class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#createForm">
    <i class="bi bi-person-plus"></i> Nieuwe systeemgebruiker
  </button>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="text-muted">Nog geen systeemgebruikers gevonden.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead>
            <tr>
              <th style="width:80px;">ID</th>
              <th style="width:64px;">Profielfoto</th>
              <th>Naam</th>
              <th>E-mail</th>
              <th>Status</th>
              <th class="text-end" style="width:140px;">Acties</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): 
            $pf = trim((string)($r['profile_photo_url'] ?? ''));
            $ini = initials((string)($r['name'] ?? ''));
          ?>
            <tr>
              <td>#<?= (int)$r['id'] ?></td>
              <td>
                <?php if ($pf !== ''): ?>
                  <img src="<?= e($pf) ?>" alt="Avatar" class="rounded-circle" style="width:40px;height:40px;object-fit:cover;">
                <?php else: ?>
                  <div class="rounded-circle bg-secondary text-white d-inline-flex align-items-center justify-content-center"
                       style="width:40px;height:40px;font-weight:600;">
                    <?= e($ini) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td><?= e($r['name'] ?: '—') ?></td>
              <td><?= e($r['email'] ?: '—') ?></td>
              <td>
                <?= ((int)$r['is_active'] === 1)
                      ? '<span class="badge bg-success">Actief</span>'
                      : '<span class="badge bg-secondary">Inactief</span>' ?>
              </td>
              <td class="text-end">
                <!-- Bewerken -->
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#editForm<?= (int)$r['id'] ?>" title="Bewerken">
                  <i class="bi bi-pencil"></i>
                </button>

                <!-- Verwijderen (alleen super — deze pagina is al super-only, maar we tonen het expliciet) -->
                <form method="post" class="d-inline" onsubmit="return confirm('Systeemgebruiker verwijderen?');">
                  <?php if (function_exists('csrf_field')) csrf_field(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Verwijderen">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>

            <!-- Inline edit -->
            <tr class="collapse" id="editForm<?= (int)$r['id'] ?>">
              <td colspan="6">
                <form method="post" class="row g-3 border rounded p-3 bg-light">
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
                      <input class="form-check-input" type="checkbox" name="is_active" id="is_active<?= (int)$r['id'] ?>" value="1"
                             <?= ((int)$r['is_active']===1?'checked':'') ?>>
                      <label class="form-check-label" for="is_active<?= (int)$r['id'] ?>">Actief</label>
                    </div>
                  </div>
                  <?php if ($photoField): ?>
                    <div class="col-md-6">
                      <label class="form-label">Profielfoto URL</label>
                      <input type="url" name="profile_photo_url" class="form-control" value="<?= e($r['profile_photo_url'] ?? '') ?>">
                      <div class="form-text">Directe URL naar afbeelding (PNG/JPG/SVG). </div>
                    </div>
                  <?php endif; ?>

                  <div class="col-12"><small class="text-muted">Wachtwoord wijzigen (optioneel)</small></div>
                  <div class="col-md-3">
                    <input type="password" name="password" class="form-control" placeholder="Nieuw wachtwoord">
                  </div>
                  <div class="col-md-3">
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

      <!-- Paginering -->
      <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
        <div class="text-muted small">
          Totaal: <?= (int)$total ?> · Pagina <?= (int)$page ?> van <?= (int)$totalPages ?>
        </div>
        <nav>
          <ul class="pagination pagination-sm mb-0">
            <?php
              $prevDisabled = ($page <= 1) ? ' disabled' : '';
              $nextDisabled = ($page >= $totalPages) ? ' disabled' : '';
              $baseQs = $_GET; $baseQs['route'] = 'system_users'; $baseQs['per_page'] = $perPage;
            ?>
            <li class="page-item<?= $prevDisabled ?>">
              <a class="page-link" href="<?= $page > 1 ? ('index.php?'.http_build_query(array_merge($baseQs,['page'=>$page-1]))) : '#' ?>">Vorige</a>
            </li>
            <?php
              $window = 2;
              $start = max(1, $page - $window);
              $end   = min($totalPages, $page + $window);
              if ($start > 1) {
                echo '<li class="page-item"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>1])).'">1</a></li>';
                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              }
              for ($p=$start; $p<=$end; $p++) {
                $active = ($p === $page) ? ' active' : '';
                echo '<li class="page-item'.$active.'"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>$p])).'">'.$p.'</a></li>';
              }
              if ($end < $totalPages) {
                if ($end < $totalPages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                echo '<li class="page-item"><a class="page-link" href="index.php?'.http_build_query(array_merge($baseQs,['page'=>$totalPages])).'">'.$totalPages.'</a></li>';
              }
            ?>
            <li class="page-item<?= $nextDisabled ?>">
              <a class="page-link" href="<?= $page < $totalPages ? ('index.php?'.http_build_query(array_merge($baseQs,['page'=>$page+1]))) : '#' ?>">Volgende</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>
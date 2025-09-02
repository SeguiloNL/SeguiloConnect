<?php
// pages/reset_password.php — nieuw wachtwoord zetten n.a.v. reset-token (robuuste kolomdetectie)
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
app_session_start();

/* ===== PDO ===== */
function get_pdo(): PDO {
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];
    $candidates = [ __DIR__ . '/../db.php', __DIR__ . '/../includes/db.php', __DIR__ . '/../config/db.php' ];
    foreach ($candidates as $f) {
        if (is_file($f)) { require_once $f; if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo']; }
    }
    $cfg = app_config(); $db = $cfg['db'] ?? []; $dsn = $db['dsn'] ?? null;
    if ($dsn) {
        $pdo = new PDO($dsn, $db['user']??null, $db['pass']??null, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    } else {
        $host=$db['host']??'localhost'; $name=$db['name']??$db['database']??''; $user=$db['user']??$db['username']??''; $pass=$db['pass']??$db['password']??''; $charset=$db['charset']??'utf8mb4';
        if ($name==='') throw new RuntimeException('DB-naam ontbreekt in config');
        $pdo = new PDO("mysql:host={$host};dbname={$name};charset={$charset}", $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    return $GLOBALS['pdo'] = $pdo;
}
$pdo = get_pdo();

/* ===== helpers ===== */
function column_exists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->quote($column);
    $res = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $res ? (bool)$res->fetch(PDO::FETCH_ASSOC) : false;
}

/**
 * Zoek de kolomnaam waarin het wachtwoord hoort.
 * Probeert bekende varianten; als niets bestaat, maakt 'password' aan (VARCHAR(255) NULL).
 */
function resolve_password_column(PDO $pdo): string {
    $candidates = ['password', 'password_hash', 'passwd', 'pwd_hash'];
    foreach ($candidates as $col) {
        if (column_exists($pdo, 'users', $col)) return $col;
    }
    // Geen match: probeer kolom 'password' aan te maken
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `password` VARCHAR(255) NULL");
        return 'password';
    } catch (Throwable $e) {
        // Lukt het niet, gooi door met duidelijke melding
        throw new RuntimeException("Geen wachtwoordkolom gevonden en kon 'password' niet toevoegen: ".$e->getMessage());
    }
}

/* ===== token laden ===== */
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$info=''; $error='';
$validReset = null;

if ($token !== '') {
    $st = $pdo->prepare("SELECT pr.*, u.id AS uid, u.email, u.name 
                           FROM password_resets pr 
                           JOIN users u ON u.id = pr.user_id
                          WHERE pr.token = :t AND pr.used = 0 AND pr.expires_at > NOW()
                          LIMIT 1");
    $st->execute([':t'=>$token]);
    $validReset = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$validReset) {
    ?>
    <h3>Wachtwoord resetten</h3>
    <div class="alert alert-danger">Deze reset-link is ongeldig of verlopen. Vraag een nieuwe link aan.</div>
    <a class="btn btn-primary" href="index.php?route=forgot_password">Nieuwe reset-link aanvragen</a>
    <?php
    return;
}

/* ===== POST verwerking ===== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch(Throwable $e) {}

    $pw1 = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if ($pw1 === '' || $pw2 === '') {
        $error = 'Vul beide wachtwoordvelden in.';
    } elseif ($pw1 !== $pw2) {
        $error = 'Wachtwoorden komen niet overeen.';
    } elseif (mb_strlen($pw1) < 8) {
        $error = 'Kies minimaal 8 tekens.';
    } else {
        try {
            $pwCol = resolve_password_column($pdo);
            $pdo->beginTransaction();

            // Wachtwoord opslaan
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE `users` SET `{$pwCol}` = :p WHERE id = :uid");
            $up->execute([':p'=>$hash, ':uid'=>(int)$validReset['uid']]);

            // Token ongeldig maken
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = :id")
                ->execute([':id'=>(int)$validReset['id']]);

            $pdo->commit();

            // Optioneel: automatisch inloggen als de rest van je app dat verwacht
            if (function_exists('auth_login')) {
                auth_login(['id'=>(int)$validReset['uid']]);
            }

            $info = 'Je wachtwoord is ingesteld. Je kunt nu inloggen.';
            ?>
            <h3>Wachtwoord resetten</h3>
            <div class="alert alert-success"><?= e($info) ?></div>
            <a class="btn btn-primary" href="index.php?route=login">Naar inloggen</a>
            <?php
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch(Throwable $e2) {} }
            $error = 'Opslaan mislukt: ' . e($e->getMessage());
        }
    }
}
?>
<h3>Wachtwoord resetten</h3>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="row g-3" action="index.php?route=reset_password">
  <?php csrf_field(); ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="col-md-6">
    <label class="form-label">Nieuw wachtwoord</label>
    <input type="password" name="password" class="form-control" required minlength="8">
    <div class="form-text">Minimaal 8 tekens.</div>
  </div>
  <div class="col-md-6">
    <label class="form-label">Herhaal nieuw wachtwoord</label>
    <input type="password" name="password2" class="form-control" required minlength="8">
  </div>
  <div class="col-12">
    <button class="btn btn-primary">Wachtwoord instellen</button>
    <a class="btn btn-outline-secondary" href="index.php?route=login">Annuleren</a>
  </div>
</form>
<?php
// pages/forgot_password.php — wachtwoord reset aanvragen
require_once __DIR__ . '/../helpers.php';

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
app_session_start();

// ---- PDO bootstrap (zelfde patroon als elders)
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

// ---- Zorg voor password_resets tabel
$pdo->exec("
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token (token),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$info = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch(Throwable $e) {}
    $email = trim((string)($_POST['email'] ?? ''));

    // Vind gebruiker (als die bestaat)
    $user = null;
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id, email, name FROM users WHERE email = :e LIMIT 1");
        $st->execute([':e'=>$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($user) {
        // Oude tokens ongeldig maken (optioneel)
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = :uid")->execute([':uid'=>(int)$user['id']]);

        // Nieuw token
        $token = bin2hex(random_bytes(32)); // 64 chars
        $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

        $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, used) VALUES (:u,:t,:x,0)")
            ->execute([':u'=>(int)$user['id'], ':t'=>$token, ':x'=>$expiresAt]);

        // Mail sturen
        $base = rtrim(base_url(), '/');
        $resetLink = $base . '/index.php?route=reset_password&token=' . urlencode($token);

        $subject = 'Wachtwoord resetten';
        $message = "Hallo,\n\nEr is een verzoek gedaan om je wachtwoord te resetten.\n".
                   "Klik op de onderstaande link om een nieuw wachtwoord in te stellen (1 uur geldig):\n\n".
                   $resetLink . "\n\n".
                   "Heb jij dit niet aangevraagd? Negeer dan deze e-mail.\n";
        $headers = "From: no-reply@" . (parse_url($base, PHP_URL_HOST) ?: 'example.com') . "\r\n".
                   "Content-Type: text/plain; charset=UTF-8\r\n";

        // Gebruik mail(); op shared hosting meestal oké
        @mail($user['email'], $subject, $message, $headers);
    }

    // Altijd zelfde feedback (ook als e-mail niet bestaat)
    $info = 'Als het e-mailadres bij ons bekend is, ontvang je binnen enkele minuten een e-mail met verdere instructies.';
}
?>

<h3>Wachtwoord vergeten</h3>

<?php if ($info): ?>
  <div class="alert alert-info"><?= e($info) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="row g-3" action="index.php?route=forgot_password">
  <?php csrf_field(); ?>
  <div class="col-md-6">
    <label class="form-label">E-mailadres</label>
    <input type="email" name="email" class="form-control" required>
    <div class="form-text">We sturen je een link om je wachtwoord te resetten.</div>
  </div>
  <div class="col-12">
    <button class="btn btn-primary">Verstuur reset-link</button>
    <a class="btn btn-outline-secondary" href="index.php?route=login">Terug naar inloggen</a>
  </div>
</form>
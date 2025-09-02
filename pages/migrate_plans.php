<?php
// pages/migrate_plans.php
// Migreert ontbrekende kolommen in 'plans' met maximale compatibiliteit:
// - Schakelt tijdelijk ATTR_EMULATE_PREPARES in (voorkomt "near '?'")
// - Gebruikt "ADD" (zonder "COLUMN") & geen AFTER-posities
// - Toont bij fouten de exacte SQL die is uitgevoerd
// Alleen Super-admin.

if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$u = auth_user();
global $pdo;

$role = $u['role'] ?? null;
$isSuper = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);

if (!$isSuper) {
    http_response_code(403);
    echo "<h3>Migratie</h3><div class='alert alert-danger'>Geen toegang.</div>";
    return;
}

// ---- helpers ----
function table_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
    $st->execute([':col' => $column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}
function table_has_index(PDO $pdo, string $table, string $index): bool {
    $st = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :idx");
    $st->execute([':idx' => $index]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
}

echo "<h3>Migratie: plans</h3>";

try {
    $pdo->query("SELECT 1 FROM plans LIMIT 1");
} catch (Throwable $e) {
    echo "<div class='alert alert-danger'>Tabel <code>plans</code> bestaat niet. Fout: ".e($e->getMessage())."</div>";
    echo '<p><a class="btn btn-secondary" href="index.php?route=plans_list">Terug</a></p>';
    return;
}

// Definities (zo breed mogelijk compatibel)
$columns = [
    'buy_price_monthly_ex_vat'          => "DECIMAL(10,2)  NULL",
    'sell_price_monthly_ex_vat'         => "DECIMAL(10,2)  NULL",
    'buy_price_overage_per_mb_ex_vat'   => "DECIMAL(10,4)  NULL",
    'sell_price_overage_per_mb_ex_vat'  => "DECIMAL(10,4)  NULL",
    'setup_fee_ex_vat'                  => "DECIMAL(10,2)  NULL",
    'bundle_gb'                         => "INT            NULL",
    'network_operator'                  => "VARCHAR(100)   NULL",
    'is_active'                         => "TINYINT(1)     NOT NULL DEFAULT 1",
];

$ok   = [];
$warn = [];

// Zet emulate prepares tijdelijk aan om MariaDB/PDO parse-issues te voorkomen
$oldEmu = null;
try {
    $oldEmu = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
} catch (Throwable $e) { /* ignore */ }
try { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); } catch (Throwable $e) { /* ignore */ }

// (optioneel) minimalistische SQL-mode
try { $pdo->exec("SET sql_mode=''"); } catch (Throwable $e) { /* ignore */ }

// Kolommen toevoegen
foreach ($columns as $col => $ddl) {
    try {
        if (table_has_column($pdo, 'plans', $col)) {
            $ok[] = "Kolom <code>{$col}</code> bestaat al — overgeslagen.";
            continue;
        }
        // Gebruik ADD zonder COLUMN; geen AFTER; geen placeholders
        $sql = "ALTER TABLE `plans` ADD `$col` $ddl";
        $pdo->exec($sql);
        $ok[] = "Kolom <code>{$col}</code> toegevoegd.";
    } catch (Throwable $e) {
        $warn[] = "Kon kolom <code>{$col}</code> niet toevoegen. SQL: <code>".e($sql)."</code><br><small>".e($e->getMessage())."</small>";
    }
}

// Index aanmaken als is_active bestaat
try {
    if (table_has_column($pdo, 'plans', 'is_active')) {
        if (!table_has_index($pdo, 'plans', 'idx_plans_active')) {
            $sql = "CREATE INDEX idx_plans_active ON `plans` (is_active)";
            $pdo->exec($sql);
            $ok[] = "Index <code>idx_plans_active</code> aangemaakt.";
        } else {
            $ok[] = "Index <code>idx_plans_active</code> bestaat al — overgeslagen.";
        }
    } else {
        $warn[] = "Index <code>idx_plans_active</code> niet aangemaakt: kolom <code>is_active</code> ontbreekt.";
    }
} catch (Throwable $e) {
    $warn[] = "Kon index <code>idx_plans_active</code> niet aanmaken. SQL: <code>".e($sql)."</code><br><small>".e($e->getMessage())."</small>";
}

// Zet emulate prepares terug zoals het was
if ($oldEmu !== null) {
    try { $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $oldEmu); } catch (Throwable $e) { /* ignore */ }
}

// Weergave
if ($ok) {
    echo "<div class='alert alert-success'><ul class='mb-0'>";
    foreach ($ok as $line) echo "<li>{$line}</li>";
    echo "</ul></div>";
}
if ($warn) {
    echo "<div class='alert alert-warning'><strong>Gereed met waarschuwingen:</strong><ul class='mb-0'>";
    foreach ($warn as $line) echo "<li>{$line}</li>";
    echo "</ul></div>";
}
?>
<p class="mt-3">
  <a class="btn btn-primary" href="index.php?route=plans_list">Terug naar abonnementen</a>
</p>
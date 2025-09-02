<?php
// pages/user_delete.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

$me = auth_user();
if (!$me) {
    header('Location: index.php?route=login');
    exit;
}

$role      = $me['role'] ?? '';
$isSuper   = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);
$isRes     = ($role === 'reseller')    || (defined('ROLE_RESELLER') && $role === ROLE_RESELLER);
$isSubRes  = ($role === 'sub_reseller')|| (defined('ROLE_SUBRESELLER') && $role === ROLE_SUBRESELLER);

try {
    $pdo = db();
} catch (Throwable $e) {
    flash_set('danger', 'Database niet beschikbaar: ' . $e->getMessage());
    redirect('index.php?route=users_list');
}

// --- helpers ---
function column_exists(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->quote($col);
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
    return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}

/**
 * Controleer of $candidateId in de “boom” van $rootId zit (inclusief root zelf).
 * Vereist kolom users.parent_user_id.
 */
function user_in_tree(PDO $pdo, int $rootId, int $candidateId): bool {
    if ($rootId === $candidateId) return true;
    if (!column_exists($pdo, 'users', 'parent_user_id')) {
        // zonder parent-structuur mag je als reseller/sub alleen jezelf verwijderen (wat we hieronder toch blokkeren)
        return false;
    }
    $queue = [$rootId];
    $seen  = [$rootId => true];
    while ($queue) {
        $chunk = array_splice($queue, 0, 100);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            if ($cid === $candidateId) return true;
            if (!isset($seen[$cid])) { $seen[$cid] = true; $queue[] = $cid; }
        }
    }
    return false;
}

// --- accepteer POST (ids[]) en fallback GET (id) ---
$targetIds = [];

// Bulk POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    try { if (function_exists('verify_csrf')) verify_csrf(); } catch (Throwable $e) {
        flash_set('danger', 'Sessie verlopen. Probeer opnieuw.');
        redirect('index.php?route=users_list');
    }
    foreach ($_POST['ids'] as $raw) {
        $id = (int)$raw;
        if ($id > 0) $targetIds[] = $id;
    }
}

// Single GET
if (!$targetIds) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) $targetIds[] = $id;
}

// Niets te doen?
if (!$targetIds) {
    flash_set('warning', 'Geen gebruikers geselecteerd om te verwijderen.');
    redirect('index.php?route=users_list');
}

// Rechten: alleen super/res/sub mogen verwijderen; customers nooit
if (!($isSuper || $isRes || $isSubRes)) {
    flash_set('danger', 'Je hebt geen rechten om gebruikers te verwijderen.');
    redirect('index.php?route=users_list');
}

// Zelfverwijdering blokkeren
$targetIds = array_values(array_unique(array_filter($targetIds, fn($v) => (int)$v !== (int)$me['id'])));
if (!$targetIds) {
    flash_set('warning', 'Je kunt je eigen account niet verwijderen.');
    redirect('index.php?route=users_list');
}

// Laad te verwijderen users (voor permissie & feedback)
$ph = implode(',', array_fill(0, count($targetIds), '?'));
$st = $pdo->prepare("SELECT id, name, role, parent_user_id FROM users WHERE id IN ($ph)");
$st->execute($targetIds);
$victims = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$victims) {
    flash_set('warning', 'Geen geldige gebruikers gevonden om te verwijderen.');
    redirect('index.php?route=users_list');
}

// Filter op scope (reseller/sub-reseller alleen eigen boom)
$deletable = [];
$blocked   = [];

foreach ($victims as $u) {
    $uid = (int)$u['id'];

    if ($isSuper) {
        $deletable[] = $u;
        continue;
    }

    // Reseller/Sub-reseller: alleen binnen hun boom
    if (user_in_tree($pdo, (int)$me['id'], $uid)) {
        $deletable[] = $u;
    } else {
        $blocked[] = $u;
    }
}

if (!$deletable) {
    flash_set('danger', 'Geen van de geselecteerde gebruikers valt binnen je rechten om te verwijderen.');
    redirect('index.php?route=users_list');
}

// Probeer te verwijderen (met nette foutafhandeling)
$okNames   = [];
$failNames = [];

$pdo->beginTransaction();
try {
    $delSt = $pdo->prepare("DELETE FROM users WHERE id = ? LIMIT 1");

    foreach ($deletable as $u) {
        $uid = (int)$u['id'];

        // Optioneel: blokkeer verwijderen als user nog children heeft
        if (column_exists($pdo, 'users', 'parent_user_id')) {
            $stCh = $pdo->prepare("SELECT COUNT(*) FROM users WHERE parent_user_id = ?");
            $stCh->execute([$uid]);
            $childCount = (int)$stCh->fetchColumn();
            if ($childCount > 0) {
                $failNames[] = $u['name'] . ' (#' . $uid . '): heeft nog onderliggende gebruikers.';
                continue;
            }
        }

        try {
            $delSt->execute([$uid]);
            if ($delSt->rowCount() > 0) {
                $okNames[] = $u['name'] . ' (#' . $uid . ')';
            } else {
                $failNames[] = $u['name'] . ' (#' . $uid . '): niet verwijderd.';
            }
        } catch (Throwable $e) {
            // Meest voorkomend: FK constraint (orders/sims)
            $failNames[] = $u['name'] . ' (#' . $uid . '): ' . $e->getMessage();
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    flash_set('danger', 'Verwijderen afgebroken: ' . $e->getMessage());
    redirect('index.php?route=users_list');
}

// Meldingen
if ($okNames) {
    flash_set('success', 'Verwijderd: ' . implode(', ', $okNames));
}
if ($blocked) {
    $blockedNames = array_map(fn($u) => $u['name'] . ' (#' . (int)$u['id'] . ')', $blocked);
    flash_set('warning', 'Overgeslagen (geen rechten): ' . implode(', ', $blockedNames));
}
if ($failNames) {
    flash_set('danger', 'Niet verwijderd: ' . implode(' | ', $failNames));
}

redirect('index.php?route=users_list');
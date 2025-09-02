<?php
// pages/ajax_sims_search.php
require_once __DIR__ . '/../helpers.php';
app_session_start();

header('Content-Type: application/json');

$me = auth_user();
if (!$me) { http_response_code(401); echo json_encode([]); exit; }

$role     = $me['role'] ?? '';
$isSuper  = ($role === 'super_admin') || (defined('ROLE_SUPER') && $role === ROLE_SUPER);

try { $pdo = db(); }
catch (Throwable $e) { http_response_code(500); echo json_encode([]); exit; }

function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->quote($col);
  $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE {$q}");
  return $st ? (bool)$st->fetch(PDO::FETCH_ASSOC) : false;
}
function table_exists(PDO $pdo, string $table): bool {
  $q = $pdo->quote($table);
  return (bool)$pdo->query("SHOW TABLES LIKE {$q}")->fetchColumn();
}

// beveiliging + validatie
$q = trim((string)($_GET['q'] ?? ''));
if (!preg_match('/^\d{5}$/', $q)) { echo json_encode([]); exit; }

// basis: alleen als de sims-tabel bestaat
if (!table_exists($pdo,'sims')) { echo json_encode([]); exit; }

// scope opbouwen voor resellers/subs
$params = [];
$scope  = '';
if (!$isSuper && column_exists($pdo,'sims','assigned_to_user_id')) {
  // bouw tree via users.parent_user_id
  $ids = [$me['id']];
  if (column_exists($pdo,'users','parent_user_id')) {
    // eenvoudige BFS (kleine variant, voldoende hier)
    $queue = [$me['id']];
    $seen = [$me['id'] => true];
    while ($queue) {
      $chunk = array_splice($queue, 0, 200);
      $ph = implode(',', array_fill(0, count($chunk), '?'));
      $st = $pdo->prepare("SELECT id FROM users WHERE parent_user_id IN ($ph)");
      $st->execute(array_map('intval',$chunk));
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $cid = (int)$cid;
        if (!isset($seen[$cid])) { $seen[$cid] = true; $ids[] = $cid; $queue[] = $cid; }
      }
    }
  }
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $scope = " AND (s.assigned_to_user_id IN ($ph) OR s.assigned_to_user_id IS NULL)";
  $params = array_map('intval', $ids);
}

// filter op laatste 5 cijfers iccid (en optioneel imsi)
$like = '%' . $q;
$filter = " (s.iccid LIKE ?".(column_exists($pdo,'sims','imsi') ? " OR s.imsi LIKE ?" : "").") ";
array_unshift($params, $like);
if (column_exists($pdo,'sims','imsi')) $params[] = $like;

// alleen vrije sims: niet in orders met (status is null of <> 'geannuleerd')
$ordersSimCol = 'sim_id'; // jouw code gebruikt deze kolom al
$freeJoin = "
  LEFT JOIN (
    SELECT $ordersSimCol AS sim_id_used
    FROM orders
    WHERE $ordersSimCol IS NOT NULL
      AND (status IS NULL OR status <> 'geannuleerd')
    GROUP BY $ordersSimCol
  ) o_used ON o_used.sim_id_used = s.id
";

$sql = "
  SELECT s.id, s.iccid
         ".(column_exists($pdo,'sims','imsi') ? ", s.imsi" : ", NULL AS imsi")."
  FROM sims s
  $freeJoin
  WHERE o_used.sim_id_used IS NULL
    AND $filter
    $scope
  ORDER BY s.id DESC
  LIMIT 50
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows ?: []);
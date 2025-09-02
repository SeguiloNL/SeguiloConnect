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

// --- validatie ---
$q = preg_replace('/\D+/', '', (string)($_GET['q'] ?? ''));
if ($q === '') { echo json_encode([]); exit; }
if (!table_exists($pdo,'sims')) { echo json_encode([]); exit; }

// --- scope ---
$params = [];
$scope  = '';
if (!$isSuper && column_exists($pdo,'sims','assigned_to_user_id')) {
  // kleine BFS
  $ids = [$me['id']];
  if (column_exists($pdo,'users','parent_user_id')) {
    $queue = [$me['id']]; $seen = [$me['id'] => true];
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

// --- vrije sims join ---
$ordersSimCol = 'sim_id';
$freeJoin = "
  LEFT JOIN (
    SELECT $ordersSimCol AS sim_id_used
    FROM orders
    WHERE $ordersSimCol IS NOT NULL
      AND (status IS NULL OR status <> 'geannuleerd')
    GROUP BY $ordersSimCol
  ) o_used ON o_used.sim_id_used = s.id
";

// --- filter (ICCID/IMSI) ---
$hasImsi = column_exists($pdo,'sims','imsi');
$filterSql = '';
if (strlen($q) <= 7) {
  // snelle suffix-match
  $filterSql = " (RIGHT(s.iccid, ?) = ?"
             . ($hasImsi ? " OR RIGHT(s.imsi, ?) = ?" : "")
             . ") ";
  array_unshift($params, strlen($q), $q);
  if ($hasImsi) { $params[] = strlen($q); $params[] = $q; }
} else {
  // contains-match
  $like = '%' . $q . '%';
  $filterSql = " (s.iccid LIKE ?"
             . ($hasImsi ? " OR s.imsi LIKE ?" : "")
             . ") ";
  array_unshift($params, $like);
  if ($hasImsi) { $params[] = $like; }
}

$sql = "
  SELECT s.id, s.iccid
         ".($hasImsi ? ", s.imsi" : ", NULL AS imsi")."
  FROM sims s
  $freeJoin
  WHERE o_used.sim_id_used IS NULL
    AND $filterSql
    $scope
  ORDER BY s.id DESC
  LIMIT 50
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows ?: []);
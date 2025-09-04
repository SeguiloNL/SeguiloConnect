<?php
// pages/vendor_order_activate.php
require_role('Super-admin');
$pdo = get_pdo();
require_once __DIR__.'/../lib/Vendors/OrderPayloadMapper.php';

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
  die('order_id ontbreekt.');
}

// Haal order + regels (pas query’s aan jouw schema)
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) die('Order niet gevonden.');

$lines = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id");
$lines->execute([':id' => $orderId]);
$orderLines = $lines->fetchAll(PDO::FETCH_ASSOC);

// Haal settings op voor account_id etc.
$cfg = $pdo->query("SELECT * FROM vendor_api_settings WHERE name='apicontrolcenter' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cfg || !$cfg['is_active']) {
  die('API niet geconfigureerd of inactief.');
}

try {
  $client = new ApiControlCenterClient($pdo);
  $payload = buildVendorOrderPayload($order, $orderLines, $cfg);

  $res = $client->activateOrder($payload);

  // Sla logging en status op
  $vendorOrderId = $res['json']['id'] ?? $res['json']['orderId'] ?? null; // pas aan
  $vendorStatus  = $res['json']['status'] ?? null;

  $ins = $pdo->prepare("
    INSERT INTO vendor_order_links (order_id, vendor_name, vendor_order_id, vendor_status, last_request, last_response)
    VALUES (:oid, 'apicontrolcenter', :vid, :vst, :req, :res)
    ON DUPLICATE KEY UPDATE
      vendor_order_id = VALUES(vendor_order_id),
      vendor_status = VALUES(vendor_status),
      last_request = VALUES(last_request),
      last_response = VALUES(last_response),
      updated_at = NOW()
  ");
  $ins->execute([
    ':oid' => $orderId,
    ':vid' => $vendorOrderId,
    ':vst' => $vendorStatus,
    ':req' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ':res' => $res['raw'],
  ]);

  $up = $pdo->prepare("UPDATE orders SET supplier_status = :st, supplier_last_sync = NOW() WHERE id = :id");
  $up->execute([':st' => $vendorStatus ?: 'submitted', ':id' => $orderId]);

  $_SESSION['flash_success'] = 'Order verstuurd naar leverancier'.($vendorOrderId ? " (ID: $vendorOrderId)" : '').'.';
  header('Location: index.php?route=vendor_orders');
  exit;
} catch (Throwable $e) {
  // Log fout en toon bericht
  $ins = $pdo->prepare("
    INSERT INTO vendor_order_links (order_id, vendor_name, vendor_status, last_request, last_response)
    VALUES (:oid, 'apicontrolcenter', 'error', :req, :res)
    ON DUPLICATE KEY UPDATE
      vendor_status = VALUES(vendor_status),
      last_request = VALUES(last_request),
      last_response = VALUES(last_response),
      updated_at = NOW()
  ");
  $ins->execute([
    ':oid' => $orderId,
    ':req' => isset($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
    ':res' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES),
  ]);
  $_SESSION['flash_error'] = 'Activatie mislukt: '.$e->getMessage();
  header('Location: index.php?route=vendor_orders');
  exit;
}
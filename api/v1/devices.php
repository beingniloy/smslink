<?php
/**
 * SMS Gateway API - Devices Endpoint
 * GET /api/v1/devices.php
 */

require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT id, device_id, device_name, model, android_version, status, sms_sent_count, last_seen, created_at FROM devices ORDER BY status ASC, last_seen DESC");
$stmt->execute();
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($devices as &$dev) {
    $stmtSim = $pdo->prepare("SELECT slot_index AS slot, display_name AS sim_name, carrier_name AS carrier, phone_number, is_active FROM device_sims WHERE device_id = ? ORDER BY slot_index ASC");
    $stmtSim->execute([$dev['device_id']]);
    $dev['sims'] = $stmtSim->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode([
    'ok' => true,
    'count' => count($devices),
    'devices' => $devices
]);

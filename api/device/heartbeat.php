<?php
/**
 * SMS Gateway - POST /api/device/heartbeat
 * Keeps Android device online and updates last_seen timestamp.
 */

require_once __DIR__ . '/../_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendApiError('Method not allowed. Use POST.', 405);
}

$rawBody = file_get_contents('php://input');
$input = @json_decode($rawBody, true);
if (!is_array($input)) {
    $input = array_merge($_GET, $_POST);
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? $_GET['device_id'] ?? '');
$status = strtolower(trim($input['status'] ?? $_GET['status'] ?? 'online'));
$token = trim($input['token'] ?? $input['api_token'] ?? $_GET['token'] ?? '');

if (empty($deviceId) && !empty($token)) {
    $pdo = getDbConnection();
    $stmtS = $pdo->prepare("SELECT device_id FROM devices WHERE api_token = ? LIMIT 1");
    $stmtS->execute([$token]);
    $deviceId = $stmtS->fetchColumn();
}

if ($status === 'offline' || $status === 'disconnected' || $status === 'stopped') {
    if (!empty($deviceId)) {
        $pdo = getDbConnection();
        $pdo->prepare("UPDATE devices SET status = 'offline' WHERE device_id = ?")->execute([$deviceId]);
    }
    sendApiResponse([
        'ok' => true,
        'status' => 'offline',
        'message' => 'Device marked offline'
    ]);
}

$device = registerOrUpdateDeviceData($deviceId);

sendApiResponse([
    'ok' => true,
    'status' => 'online',
    'last_seen' => $device['last_seen'] ?? date('Y-m-d H:i:s')
]);

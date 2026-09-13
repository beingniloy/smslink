<?php
/**
 * SMS Gateway - POST /api/device/disconnect
 * Marks Android device as offline when disconnected from phone app.
 */

require_once __DIR__ . '/../_common.php';

$rawBody = file_get_contents('php://input');
$input = @json_decode($rawBody, true);
if (!is_array($input)) {
    $input = array_merge($_GET, $_POST);
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? '');
$token = trim($input['token'] ?? $input['api_token'] ?? $input['api_key'] ?? $_GET['token'] ?? '');
$pairingCode = trim($input['pairing_code'] ?? $input['pairingCode'] ?? $input['code'] ?? '');

$pdo = getDbConnection();
if (!empty($deviceId)) {
    $pdo->prepare("UPDATE devices SET status = 'offline' WHERE device_id = ? OR device_uuid = ?")->execute([$deviceId, $deviceId]);
} elseif (!empty($token)) {
    $pdo->prepare("UPDATE devices SET status = 'offline' WHERE api_token = ?")->execute([$token]);
} elseif (!empty($pairingCode)) {
    $pdo->prepare("UPDATE devices SET status = 'offline' WHERE pairing_code = ?")->execute([$pairingCode]);
}

logGatewayActivity('device_disconnect', 'Device disconnected: ' . ($deviceId ?: $token));

sendApiResponse([
    'ok' => true,
    'success' => true,
    'status' => 'offline',
    'message' => 'Device disconnected successfully'
]);

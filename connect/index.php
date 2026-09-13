<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendApiError('Method not allowed. Use POST request with pairing credentials.', 405);
}

$rawBody = file_get_contents('php://input');
$input = @json_decode($rawBody, true);
if (!is_array($input)) {
    $input = $_POST;
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? '');
$deviceName = trim($input['device_name'] ?? $input['deviceName'] ?? $input['name'] ?? $input['label'] ?? '');
$model = trim($input['model'] ?? $input['device_model'] ?? $input['phone_model'] ?? $input['brand'] ?? '');
$android = trim($input['android'] ?? $input['android_version'] ?? $input['os_version'] ?? $input['version'] ?? '');
$sims = $input['sims'] ?? $input['sim_cards'] ?? $input['sim_list'] ?? $input['slots'] ?? [];
$pairingCode = trim($input['pairing_code'] ?? $input['pairingCode'] ?? $input['code'] ?? '');
$token = trim($input['token'] ?? $input['api_token'] ?? $input['api_key'] ?? '');

if (empty($pairingCode) && empty($token) && empty($deviceId)) {
    sendApiError('Invalid pairing request. Pairing code, token, or device ID is required.', 400);
}

$device = registerOrUpdateDeviceData($deviceId, $deviceName, $model, $android, $sims, $pairingCode, $token);

echo json_encode([
    'ok' => true,
    'success' => true,
    'status' => 'success',
    'message' => 'Device paired successfully',
    'device' => $device,
    'device_id' => $device['device_id'],
    'api_token' => $device['api_token'] ?? null
]);
exit;

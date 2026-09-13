<?php
/**
 * SMS Gateway - POST /api/device/register
 * Registers Android device and reports SIM card details.
 */

require_once __DIR__ . '/../_common.php';

$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true);
if (!is_array($input)) {
    $input = array_merge($_GET, $_POST);
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? '');
$deviceName = trim($input['device_name'] ?? $input['deviceName'] ?? $input['name'] ?? $input['label'] ?? '');
$model = trim($input['model'] ?? $input['device_model'] ?? $input['phone_model'] ?? $input['brand'] ?? '');
$android = trim($input['android'] ?? $input['android_version'] ?? $input['os_version'] ?? $input['version'] ?? '');
$sims = $input['sims'] ?? $input['sim_cards'] ?? $input['sim_list'] ?? $input['slots'] ?? [];
$pairingCode = trim($input['pairing_code'] ?? $input['pairingCode'] ?? $input['code'] ?? $_GET['code'] ?? '');
$token = trim($input['token'] ?? $input['api_token'] ?? $input['api_key'] ?? $_GET['token'] ?? $_GET['api_key'] ?? '');

$device = registerOrUpdateDeviceData($deviceId, $deviceName, $model, $android, $sims, $pairingCode, $token);

logGatewayActivity('device_register', 'Device registered: ' . ($deviceName ?: $model ?: $device['device_id']) . ' (' . count($device['sims'] ?? []) . ' SIMs)');

sendApiResponse([
    'ok' => true,
    'success' => true,
    'status' => 'success',
    'message' => 'Device registered successfully',
    'device' => $device,
    'device_id' => $device['device_id'],
    'api_token' => $device['api_token'] ?? null
]);

<?php
/**
 * SMS Gateway - POST /api/device/sync
 * Syncs device info, model, Android OS version, and attached SIM cards.
 */

require_once __DIR__ . '/../_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendApiError('Method not allowed. Use POST.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_POST;
}

$deviceId = trim($input['device_id'] ?? '');
if (empty($deviceId)) {
    sendApiError('device_id is required', 400);
}

$deviceName = trim($input['device_name'] ?? '');
$model = trim($input['model'] ?? '');
$android = trim($input['android'] ?? trim($input['android_version'] ?? ''));
$sims = $input['sims'] ?? [];

$device = registerOrUpdateDeviceData($deviceId, $deviceName, $model, $android, $sims);

logGatewayActivity('device_sync', 'Synced device details: ' . $deviceId);

sendApiResponse([
    'ok' => true,
    'device' => $device
]);

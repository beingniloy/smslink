<?php
/**
 * SMS Gateway - POST /api/sim/sync
 * Syncs active SIM cards attached to an Android device.
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
$sims = $input['sims'] ?? [];

if (empty($deviceId)) sendApiError('device_id is required', 400);

$device = registerOrUpdateDeviceData($deviceId, '', '', '', $sims);

logGatewayActivity('sim_sync', 'Synced ' . count($device['sims'] ?? []) . ' SIM card(s) for device: ' . $deviceId);

sendApiResponse([
    'ok' => true,
    'device_id' => $deviceId,
    'sims' => $device['sims'] ?? []
]);

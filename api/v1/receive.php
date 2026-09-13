<?php
/**
 * SMS Gateway API - Receive Endpoint (incoming SMS from Android)
 * POST /api/v1/receive.php
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
$from = trim($input['from'] ?? $input['sender'] ?? '');
$message = trim($input['message'] ?? '');
$simSlot = (int)($input['sim_slot'] ?? 0);
$subId = isset($input['subscription_id']) ? (int)$input['subscription_id'] : null;

if (empty($deviceId)) sendApiError('device_id is required', 400);
if (empty($from))     sendApiError('from/sender is required', 400);
if (empty($message))  sendApiError('message is required', 400);

$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO incoming_messages (device_id, sender, message_body, sim_slot, subscription_id, received_at, created_at)
    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->execute([$deviceId, $from, $message, $simSlot, $subId]);

registerOrUpdateDeviceData($deviceId);

logGatewayActivity('sms_received', 'SMS from ' . $from . ' (SIM ' . ($simSlot ?: 'Auto') . '): ' . substr($message, 0, 100), [
    'sender' => $from, 'message' => $message, 'sim_slot' => $simSlot, 'device_id' => $deviceId
]);

sendApiResponse(['ok' => true, 'message' => 'Incoming SMS stored successfully']);


<?php
/**
 * SMS Gateway - POST /api/sms/incoming
 * Inserts incoming SMS into MySQL incoming_messages table.
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
$sender = trim($input['sender'] ?? $input['from'] ?? '');
$message = trim($input['message'] ?? '');
$simSlot = (int)($input['sim_slot'] ?? 0);
$subId = isset($input['subscription_id']) ? (int)$input['subscription_id'] : null;

if (empty($deviceId)) sendApiError('device_id is required', 400);
if (empty($sender))   sendApiError('sender/from is required', 400);
if (empty($message))  sendApiError('message is required', 400);

$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO incoming_messages (device_id, sender, message_body, sim_slot, subscription_id, received_at, created_at)
    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
");
$stmt->execute([$deviceId, $sender, $message, $simSlot, $subId]);

registerOrUpdateDeviceData($deviceId);

logGatewayActivity('sms_received', 'SMS from ' . $sender . ': ' . substr($message, 0, 100), [
    'sender' => $sender, 'message' => $message, 'sim_slot' => $simSlot, 'device_id' => $deviceId
]);

sendApiResponse(['ok' => true, 'message' => 'Incoming SMS stored successfully']);

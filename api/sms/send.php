<?php
/**
 * SMS Gateway - POST /api/sms/send
 * Instant MySQL SMS queue dispatcher with per-message SIM selection.
 */

require_once __DIR__ . '/../_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendApiError('Method not allowed. Use POST.', 405);
}

$keyData = authenticateApiRequest();

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input)) {
    $input = $_POST;
}

$to = $input['to'] ?? $input['numbers'] ?? [];
if (is_string($to)) {
    $numbers = array_filter(array_map('trim', explode(',', $to)));
} elseif (is_array($to)) {
    $numbers = array_filter(array_map('trim', $to));
} else {
    $numbers = [];
}

$message = trim($input['message'] ?? '');
$simSlot = isset($input['sim_slot']) ? (int)$input['sim_slot'] : 0;
$subId   = isset($input['subscription_id']) ? (int)$input['subscription_id'] : null;
$targetDevice = trim($input['device_id'] ?? '');

if (empty($numbers)) sendApiError('"to" or "numbers" is required.', 400);
if (empty($message)) sendApiError('"message" is required.', 400);

$msgId = 'msg_' . uniqid();
$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO sms_messages 
        (message_id, recipient, message_body, sim_slot, subscription_id, status, assigned_device_id, source, api_key_id, created_at)
    VALUES (?, ?, ?, ?, ?, 'queued', ?, 'api', ?, NOW())
");

$sentCount = 0;
foreach ($numbers as $num) {
    $stmt->execute([
        $msgId,
        $num,
        $message,
        $simSlot,
        $subId,
        !empty($targetDevice) ? $targetDevice : null,
        $keyData['id'] ?? null
    ]);
    $sentCount++;
}

logGatewayActivity('api_sms_sent', 'API queued SMS to ' . $sentCount . ' recipient(s) [SIM Slot: ' . ($simSlot ?: 'Auto') . ']', [
    'message_id' => $msgId,
    'count' => $sentCount,
    'sim_slot' => $simSlot
]);

sendApiResponse([
    'ok' => true,
    'message_id' => $msgId,
    'sent_count' => $sentCount,
    'sim_slot' => $simSlot,
    'queued' => true
]);

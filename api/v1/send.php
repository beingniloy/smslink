<?php
/**
 * SMS Gateway API - Send Endpoint
 * POST /api/v1/send.php
 */

require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}

$input = json_decode(file_get_contents('php://input'), true);
if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$numbers = $input['numbers'] ?? $input['to'] ?? [];
if (is_string($numbers)) {
    $numbers = array_filter(array_map('trim', explode(',', $numbers)));
}

$message = trim($input['message'] ?? '');
$simSlot = isset($input['sim_slot']) ? (int)$input['sim_slot'] : 0;
$subId   = isset($input['subscription_id']) ? (int)$input['subscription_id'] : null;

if (empty($numbers) || !is_array($numbers)) {
    sendError(400, 'numbers is required and must be a non-empty array.');
}

foreach ($numbers as $number) {
    $number = trim($number);
    if (empty($number)) {
        sendError(400, 'Empty phone number found in numbers array.');
    }
}

if (empty($message)) {
    sendError(400, 'message is required and cannot be empty.');
}
if (strlen($message) > 1600) {
    sendError(400, 'Message exceeds maximum length of 1600 characters.');
}

$targetDevice = trim($input['device_id'] ?? '');

$msgId = 'msg_' . uniqid();
$pdo = getDbConnection();

$stmt = $pdo->prepare("
    INSERT INTO sms_messages 
        (message_id, recipient, message_body, sim_slot, subscription_id, status, assigned_device_id, source, api_key_id, created_at)
    VALUES (?, ?, ?, ?, ?, 'queued', ?, 'api', ?, NOW())
");

$sentCount = 0;
foreach ($numbers as $number) {
    $number = trim($number);
    $stmt->execute([
        $msgId,
        $number,
        $message,
        $simSlot,
        $subId,
        !empty($targetDevice) ? $targetDevice : null,
        $__keyData['key_id'] ?? $__keyData['id'] ?? null
    ]);
    $sentCount++;
}

$numberList = implode(', ', $numbers);
logActivity('api_sms_sent', 'API queued SMS to ' . $sentCount . ' number(s) [SIM: ' . ($simSlot ?: 'Auto') . ']: ' . $numberList);

echo json_encode([
    'ok' => true,
    'message_id' => $msgId,
    'sent_count' => $sentCount,
    'sim_slot' => $simSlot,
    'queued' => true
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


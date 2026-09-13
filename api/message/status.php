<?php
/**
 * SMS Gateway - POST /api/message/status
 * Updates SMS delivery/sent/failed status in MySQL.
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
$messageId = trim($input['message_id'] ?? $input['id'] ?? '');
$status = trim($input['status'] ?? '');
$error = trim($input['error'] ?? '');

if (empty($deviceId)) sendApiError('device_id is required', 400);
if (empty($messageId)) sendApiError('message_id is required', 400);
if (!in_array($status, ['sent', 'delivered', 'failed'])) {
    sendApiError('status must be "sent", "delivered", or "failed"', 400);
}

$pdo = getDbConnection();

if (is_numeric($messageId)) {
    $stmt = $pdo->prepare("
        UPDATE sms_messages 
        SET status = ?, error_message = IF(? != '', ?, error_message), sent_at = NOW()
        WHERE id = ? OR message_id = ?
    ");
    $stmt->execute([$status, $error, $error, (int)$messageId, $messageId]);
} else {
    $stmt = $pdo->prepare("
        UPDATE sms_messages 
        SET status = ?, error_message = IF(? != '', ?, error_message), sent_at = NOW()
        WHERE message_id = ?
    ");
    $stmt->execute([$status, $error, $error, $messageId]);
}

if ($status === 'sent' || $status === 'delivered') {
    $stmtDev = $pdo->prepare("UPDATE devices SET sms_sent_count = sms_sent_count + 1, last_seen = NOW(), status = 'online' WHERE device_id = ?");
    $stmtDev->execute([$deviceId]);

    logGatewayActivity('sms_sent', 'SMS delivered: ' . $messageId . ' (Device: ' . $deviceId . ')', [
        'device_id' => $deviceId, 'message_id' => $messageId, 'status' => $status
    ]);
} else {
    logGatewayActivity('sms_failed', 'SMS failed: ' . $messageId . ' - ' . ($error ?: 'Unknown error') . ' (Device: ' . $deviceId . ')', [
        'device_id' => $deviceId, 'message_id' => $messageId, 'status' => 'failed', 'error' => $error
    ]);
}

sendApiResponse(['ok' => true, 'message_id' => $messageId, 'status' => $status]);

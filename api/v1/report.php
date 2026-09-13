<?php
/**
 * SMS Gateway API - Report Endpoint (Delivery/Sent Status)
 * POST/GET /api/v1/report.php
 * Updates SMS message delivery/sent/failed status directly in MySQL database.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../_common.php';

$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true);
if (!is_array($input)) {
    $input = array_merge($_GET, $_POST);
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? '');
$messageId = trim($input['message_id'] ?? $input['id'] ?? '');
$status = strtolower(trim($input['status'] ?? ''));
$error = trim($input['error'] ?? $input['error_message'] ?? '');

if (empty($messageId)) {
    sendApiError('message_id is required', 400);
}

if (!in_array($status, ['sent', 'delivered', 'failed', 'processing', 'queued'])) {
    $status = 'sent';
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
    if (!empty($deviceId)) {
        $stmtDev = $pdo->prepare("UPDATE devices SET sms_sent_count = sms_sent_count + 1, last_seen = NOW(), status = 'online' WHERE device_id = ?");
        $stmtDev->execute([$deviceId]);
    }

    logGatewayActivity('sms_sent', 'SMS delivered: ' . $messageId . ' (Device: ' . ($deviceId ?: 'Gateway') . ')', [
        'device_id' => $deviceId, 'message_id' => $messageId, 'status' => $status
    ]);
} elseif ($status === 'failed') {
    logGatewayActivity('sms_failed', 'SMS failed: ' . $messageId . ' - ' . ($error ?: 'Unknown error') . ' (Device: ' . ($deviceId ?: 'Gateway') . ')', [
        'device_id' => $deviceId, 'message_id' => $messageId, 'status' => 'failed', 'error' => $error
    ]);
}

echo json_encode([
    'ok' => true,
    'success' => true,
    'status' => 'success',
    'message_id' => $messageId,
    'message_status' => $status
]);
exit;

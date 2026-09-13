<?php
/**
 * SMS Gateway API - Poll Endpoint
 * POST/GET /api/v1/poll.php
 * Fetches queued SMS messages directly from MySQL database and returns them for processing.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../_common.php';

$rawInput = file_get_contents('php://input');
$input = @json_decode($rawInput, true);
if (!is_array($input)) {
    $input = array_merge($_GET, $_POST);
}

$deviceId = trim($input['device_id'] ?? $input['deviceId'] ?? $input['device_uuid'] ?? $input['uuid'] ?? $input['id'] ?? $_GET['device_id'] ?? '');
$model = trim($input['model'] ?? $input['device_model'] ?? '');
$android = trim($input['android'] ?? $input['android_version'] ?? '');
$token = trim($input['token'] ?? $input['api_token'] ?? $input['api_key'] ?? $_GET['api_key'] ?? $_GET['token'] ?? '');
$pairingCode = trim($input['pairing_code'] ?? $input['code'] ?? '');

$device = null;
if (!empty($deviceId) || !empty($token) || !empty($pairingCode)) {
    $device = registerOrUpdateDeviceData($deviceId, '', $model, $android, [], $pairingCode, $token);
    if (!empty($device['device_id'])) {
        $deviceId = $device['device_id'];
    }
}

$pdo = getDbConnection();

// Auto-reset stuck processing messages (> 2 mins old or assigned to offline devices)
try {
    $pdo->exec("UPDATE sms_messages SET status = 'queued', assigned_device_id = NULL WHERE status = 'processing' AND (sent_at IS NULL OR TIMESTAMPDIFF(MINUTE, sent_at, NOW()) >= 2)");
} catch (Exception $e) {}

// Fetch queued messages for this device OR unassigned queued messages
$waitTimeout = isset($input['wait']) || isset($_GET['wait']) ? min(20, max(1, (int)($input['wait'] ?? $_GET['wait'] ?? 10))) : 0;
$startTime = time();

$stmt = $pdo->prepare("
    SELECT 
        id, 
        message_id, 
        recipient AS `to`, 
        recipient AS `number`, 
        recipient AS `phone`, 
        recipient AS `recipient`, 
        message_body AS `message`, 
        message_body AS `text`, 
        message_body AS `message_body`, 
        sim_slot, 
        subscription_id, 
        created_at
    FROM sms_messages
    WHERE status = 'queued' 
      AND (assigned_device_id IS NULL OR assigned_device_id = '' OR assigned_device_id = ? OR assigned_device_id NOT IN (SELECT device_id FROM devices WHERE status = 'online'))
    ORDER BY id ASC
    LIMIT 10
");

$queuedMsgs = [];
while (true) {
    $stmt->execute([$deviceId]);
    $queuedMsgs = $stmt->fetchAll();

    if (!empty($queuedMsgs) || $waitTimeout <= 0 || (time() - $startTime) >= $waitTimeout) {
        break;
    }
    // High-performance sleep (200ms) for instant delivery
    usleep(200000);
}

$formattedCommands = [];
if (!empty($queuedMsgs)) {
    $ids = array_column($queuedMsgs, 'id');
    $inClause = implode(',', array_map('intval', $ids));

    // Mark messages as processing in MySQL
    $pdo->exec("
        UPDATE sms_messages 
        SET status = 'processing', assigned_device_id = '{$deviceId}', sent_at = NOW() 
        WHERE id IN ({$inClause})
    ");

    foreach ($queuedMsgs as $msg) {
        $formattedCommands[] = [
            'id' => (string)$msg['id'],
            'message_id' => (string)($msg['message_id'] ?: $msg['id']),
            'to' => (string)$msg['to'],
            'number' => (string)$msg['to'],
            'phone' => (string)$msg['to'],
            'recipient' => (string)$msg['to'],
            'message' => (string)$msg['message'],
            'text' => (string)$msg['message'],
            'message_body' => (string)$msg['message'],
            'sim_slot' => (int)($msg['sim_slot'] ?? 0),
            'sim' => (int)($msg['sim_slot'] ?? 0),
            'subscription_id' => isset($msg['subscription_id']) ? (int)$msg['subscription_id'] : null
        ];
    }

    logGatewayActivity('poll_queue', 'Device polled & received ' . count($formattedCommands) . ' message(s)', [
        'device_id' => $deviceId,
        'count' => count($formattedCommands)
    ]);
}

echo json_encode([
    'ok' => true,
    'success' => true,
    'status' => 'success',
    'messages' => $formattedCommands,
    'commands' => $formattedCommands,
    'data' => $formattedCommands,
    'queue' => $formattedCommands,
    'count' => count($formattedCommands),
    'timestamp' => date('Y-m-d H:i:s')
]);
exit;
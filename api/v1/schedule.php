<?php
/**
 * SMS Gateway API - Schedule Endpoint
 * POST /api/v1/schedule.php
 */

require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$numbers = $input['numbers'] ?? $input['to'] ?? [];
if (is_string($numbers)) {
    $numbers = array_filter(array_map('trim', explode(',', $numbers)));
}
$message = trim($input['message'] ?? '');
$scheduleTime = trim($input['schedule_time'] ?? $input['send_at'] ?? '');
$simSlot = (int)($input['sim_slot'] ?? 0);
$deviceId = trim($input['device_id'] ?? '');

if (empty($numbers) || !is_array($numbers)) {
    sendError(400, 'numbers is required.');
}
if (empty($message)) {
    sendError(400, 'message is required.');
}
if (empty($scheduleTime)) {
    sendError(400, 'schedule_time is required (format: YYYY-MM-DD HH:MM:SS).');
}

$parsedTimestamp = strtotime($scheduleTime);
if (!$parsedTimestamp) {
    sendError(400, 'Invalid schedule_time format. Use YYYY-MM-DD HH:MM:SS');
}
$parsedTime = date('Y-m-d H:i:s', $parsedTimestamp);
$schedId = 'sched_' . uniqid();

$recurring = trim($input['recurring'] ?? 'none');

$pdo = getDbConnection();
$stmt = $pdo->prepare("
    INSERT INTO scheduled_sms (id, numbers, message, schedule_time, recurring, status, sim_slot, created_at)
    VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
");
$stmt->execute([
    $schedId,
    json_encode(array_values($numbers)),
    $message,
    $parsedTime,
    $recurring,
    $simSlot
]);

logActivity('api_sms_scheduled', 'Scheduled SMS for ' . count($numbers) . ' recipient(s) at ' . $parsedTime);

echo json_encode([
    'ok' => true,
    'sched_id' => $schedId,
    'numbers_count' => count($numbers),
    'schedule_time' => $parsedTime
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
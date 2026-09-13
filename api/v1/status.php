<?php


require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$messageId = trim($_GET['id'] ?? $_GET['message_id'] ?? '');

if (empty($messageId)) {
    sendError(400, 'id parameter is required');
}

$pdo = getDbConnection();
if (is_numeric($messageId)) {
    $stmt = $pdo->prepare("SELECT * FROM sms_messages WHERE id = ? OR message_id = ? ORDER BY id ASC");
    $stmt->execute([(int)$messageId, $messageId]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM sms_messages WHERE message_id = ? ORDER BY id ASC");
    $stmt->execute([$messageId]);
}
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    sendError(404, 'Message not found');
}

$sentCount = 0;
$failedCount = 0;
$totalItems = count($items);
$createdAt = $items[0]['created_at'] ?? null;

foreach ($items as $item) {
    $st = $item['status'] ?? 'queued';
    if ($st === 'sent' || $st === 'delivered') {
        $sentCount++;
    } elseif ($st === 'failed') {
        $failedCount++;
    }
}

if ($sentCount === $totalItems) {
    $overallStatus = 'sent';
} elseif ($failedCount === $totalItems) {
    $overallStatus = 'failed';
} elseif ($sentCount > 0 || $failedCount > 0) {
    $overallStatus = 'partial';
} else {
    $overallStatus = $items[0]['status'] ?? 'queued';
}

echo json_encode([
    'ok' => true,
    'message_id' => $messageId,
    'status' => $overallStatus,
    'total_count' => $totalItems,
    'sent_count' => $sentCount,
    'failed_count' => $failedCount,
    'queued_count' => max(0, $totalItems - $sentCount - $failedCount),
    'created_at' => $createdAt,
    'items' => array_map(function($m) {
        return [
            'id' => (string)$m['id'],
            'message_id' => $m['message_id'],
            'recipient' => $m['recipient'],
            'to' => $m['recipient'],
            'message' => $m['message_body'],
            'sim_slot' => (int)$m['sim_slot'],
            'status' => $m['status'],
            'assigned_device_id' => $m['assigned_device_id'],
            'error' => $m['error_message'],
            'sent_at' => $m['sent_at'],
            'created_at' => $m['created_at']
        ];
    }, $items)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


<?php

require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError(405, 'Method not allowed. Use GET.');
}

$pdo = getDbConnection();

$stmtTotal = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered')");
$totalSent = (int)$stmtTotal->fetchColumn();

$stmtToday = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND created_at >= CURDATE()");
$todaySent = (int)$stmtToday->fetchColumn();

$stmtWeek = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$weekSent = (int)$stmtWeek->fetchColumn();

$stmtMonth = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$monthSent = (int)$stmtMonth->fetchColumn();

$stmtKeys = $pdo->query("SELECT COUNT(*) FROM api_tokens");
$apiKeysCount = (int)$stmtKeys->fetchColumn();

$stmtDevs = $pdo->query("SELECT COUNT(*) FROM devices WHERE status = 'online' AND (last_seen IS NULL OR TIMESTAMPDIFF(MINUTE, last_seen, NOW()) < 5)");
$onlineCount = (int)$stmtDevs->fetchColumn();

echo json_encode([
    'ok' => true,
    'total_sent' => $totalSent,
    'today_sent' => $todaySent,
    'week_sent' => $weekSent,
    'month_sent' => $monthSent,
    'api_keys_count' => $apiKeysCount,
    'devices_online_count' => $onlineCount
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);


<?php


header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../config/database.php';

function sendError($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function logActivity($type, $detail) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO device_logs (log_type, detail, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$type, $detail]);
    } catch (Exception $e) {}
}

function loadJson($file) {
    $path = __DIR__ . '/../../config/' . $file;
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?: [];
}

function saveJson($file, $data) {
    $path = __DIR__ . '/../../config/' . $file;
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Extract token from multiple sources
$token = null;

// 1. Check server environment headers
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

// 2. Check Apache / FastCGI request headers
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (empty($authHeader) && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!empty($authHeader)) {
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        $token = trim($authHeader);
    }
}

// 3. Custom Header
if (empty($token)) {
    $token = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
}

// 4. Query Parameter or POST Form
if (empty($token)) {
    $token = $_GET['api_key'] ?? $_POST['api_key'] ?? $_GET['token'] ?? $_POST['token'] ?? null;
}

// 5. JSON Body
if (empty($token)) {
    $raw = @file_get_contents('php://input');
    if (!empty($raw)) {
        $json = @json_decode($raw, true);
        if (is_array($json)) {
            $token = $json['api_key'] ?? $json['token'] ?? null;
        }
    }
}

if (empty($token)) {
    sendError(401, 'Authentication required. Provide API key via Authorization: Bearer header, X-API-Key header, or ?api_key= parameter.');
}

$token = trim((string)$token);
$pdo = getDbConnection();

$tokenHash = hash('sha256', $token);
$stmtKey = $pdo->prepare("SELECT * FROM api_tokens WHERE token_hash = ? OR key_id = ? LIMIT 1");
$stmtKey->execute([$tokenHash, $token]);
$keyData = $stmtKey->fetch(PDO::FETCH_ASSOC);

if (!$keyData) {
    $stmtDev = $pdo->prepare("SELECT * FROM devices WHERE api_token = ? OR pairing_code = ? OR device_id = ? LIMIT 1");
    $stmtDev->execute([$token, $token, $token]);
    $devData = $stmtDev->fetch(PDO::FETCH_ASSOC);
    if ($devData) {
        $keyData = [
            'id' => $devData['id'],
            'key_id' => $devData['api_token'] ?: $devData['device_id'],
            'name' => $devData['model'] ?: $devData['device_name'] ?: 'Gateway Device',
            'permissions' => ['send', 'read', 'devices'],
            'rate_limit' => 5000
        ];
    }
}

if (!$keyData) {
    sendError(401, 'Invalid API key.');
}

// Update last used
try {
    if (!empty($keyData['id'])) {
        $pdo->prepare("UPDATE api_tokens SET last_used = NOW(), usage_count = usage_count + 1 WHERE id = ?")->execute([$keyData['id']]);
    }
} catch (Exception $e) {}

$__keyData = $keyData;

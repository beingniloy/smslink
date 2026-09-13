<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';

function sendApiResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendApiError($message, $code = 400) {
    sendApiResponse(['ok' => false, 'error' => $message], $code);
}

function logGatewayActivity($type, $detail, $meta = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO device_logs (log_type, detail, meta_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([
            $type,
            $detail,
            $meta !== null ? json_encode($meta) : null
        ]);
    } catch (Exception $e) {
        // Fallback logging if table error
    }
}

function extractRequestToken() {
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

    // 3. Check custom header X-API-Key
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
    }

    // 4. Check query string or form data
    if (empty($token)) {
        $token = $_GET['api_key'] ?? $_POST['api_key'] ?? $_GET['token'] ?? $_POST['token'] ?? null;
    }

    // 5. Check JSON body
    if (empty($token)) {
        $raw = @file_get_contents('php://input');
        if (!empty($raw)) {
            $json = @json_decode($raw, true);
            if (is_array($json)) {
                $token = $json['api_key'] ?? $json['token'] ?? null;
            }
        }
    }

    return !empty($token) ? trim((string)$token) : null;
}

function authenticateApiRequest() {
    $token = extractRequestToken();
    if (empty($token)) {
        sendApiError('Authentication required. Provide API token.', 401);
    }
    
    $pdo = getDbConnection();

    // 1. Check API Keys / Tokens table
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE token_hash = ? OR key_id = ? LIMIT 1");
    $stmt->execute([$tokenHash, $token]);
    $key = $stmt->fetch();
    if ($key) {
        $pdo->prepare("UPDATE api_tokens SET usage_count = usage_count + 1, last_used = NOW() WHERE id = ?")->execute([$key['id']]);
        return ['id' => $key['key_id'], 'key_id' => $key['key_id'], 'type' => 'api_key', 'name' => $key['name']];
    }

    // 2. Check Device Tokens
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE api_token = ? OR pairing_code = ? OR device_id = ? LIMIT 1");
    $stmt->execute([$token, $token, $token]);
    $dev = $stmt->fetch();
    if ($dev) {
        $pdo->prepare("UPDATE devices SET status = 'online', last_seen = NOW() WHERE id = ?")->execute([$dev['id']]);
        return ['id' => 'device_' . $dev['device_id'], 'key_id' => $dev['device_id'], 'type' => 'device', 'device_id' => $dev['device_id'], 'name' => $dev['model'] ?: $dev['device_name'] ?: 'Gateway Device'];
    }

    sendApiError('Invalid API token.', 401);
}

function registerOrUpdateDeviceData($deviceId, $deviceName = '', $model = '', $android = '', $sims = [], $pairingCode = null, $token = null) {
    $pdo = getDbConnection();
    $now = date('Y-m-d H:i:s');

    // Auto delete devices offline/disconnected for >= 30 days
    try {
        $pdo->exec("DELETE FROM devices WHERE (last_seen IS NOT NULL AND TIMESTAMPDIFF(DAY, last_seen, NOW()) >= 30) OR (last_seen IS NULL AND TIMESTAMPDIFF(DAY, created_at, NOW()) >= 30)");
        $pdo->exec("DELETE FROM devices WHERE model = 'Awaiting pairing...'");
    } catch (Exception $e) {}

    if (empty($deviceId) && (!empty($token) || !empty($pairingCode))) {
        $stmtSearch = $pdo->prepare("SELECT device_id FROM devices WHERE (api_token IS NOT NULL AND api_token != '' AND api_token = ?) OR (pairing_code IS NOT NULL AND pairing_code != '' AND pairing_code = ?) LIMIT 1");
        $stmtSearch->execute([$token, $pairingCode]);
        $foundDevId = $stmtSearch->fetchColumn();
        if ($foundDevId) {
            $deviceId = $foundDevId;
        }
    }

    if (empty($deviceId)) {
        $deviceId = 'dev_' . substr(md5(($token ?: $pairingCode ?: uniqid()) . microtime()), 0, 12);
    }

    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = ? OR (pairing_code IS NOT NULL AND pairing_code != '' AND pairing_code = ?) OR (api_token IS NOT NULL AND api_token != '' AND api_token = ?) LIMIT 1");
    $stmt->execute([$deviceId, $pairingCode, $token]);
    $existing = $stmt->fetch();

    if ($existing) {
        $deviceId = $existing['device_id'];
        $apiToken = !empty($token) ? $token : ($existing['api_token'] ?: ('dev_tok_' . bin2hex(random_bytes(16))));

        $validName = (!empty($deviceName) && $deviceName !== 'Android Phone' && $deviceName !== 'Android Gateway') ? $deviceName : '';
        $validModel = (!empty($model) && $model !== 'Android Phone' && $model !== 'Unknown') ? $model : '';
        $validAndroid = (!empty($android) && $android !== 'Android' && $android !== 'Unknown') ? $android : '';

        $stmtUpdate = $pdo->prepare("
            UPDATE devices SET
                status = 'online',
                last_seen = ?,
                device_name = IF(? != '', ?, device_name),
                model = IF(? != '', ?, model),
                android_version = IF(? != '', ?, android_version),
                pairing_code = IF(? IS NOT NULL AND ? != '', ?, pairing_code),
                api_token = ?
            WHERE device_id = ?
        ");
        $stmtUpdate->execute([
            $now,
            $validName, $validName,
            $validModel, $validModel,
            $validAndroid, $validAndroid,
            $pairingCode, $pairingCode, $pairingCode,
            $apiToken,
            $deviceId
        ]);
    } else {
        $apiToken = !empty($token) ? $token : ('dev_tok_' . bin2hex(random_bytes(16)));
        $stmtInsert = $pdo->prepare("
            INSERT INTO devices 
                (device_id, device_uuid, device_name, model, android_version, status, api_token, pairing_code, last_seen, created_at)
            VALUES (?, ?, ?, ?, ?, 'online', ?, ?, ?, ?)
        ");
        $stmtInsert->execute([
            $deviceId,
            $deviceId,
            (!empty($deviceName) && $deviceName !== 'Android Phone') ? $deviceName : 'Android Phone',
            (!empty($model) && $model !== 'Android Phone') ? $model : 'Unknown',
            (!empty($android) && $android !== 'Android') ? $android : 'Unknown',
            $apiToken,
            $pairingCode,
            $now,
            $now
        ]);
    }

    if (!empty($sims) && is_array($sims)) {
        updateDeviceSimsInDb($pdo, $deviceId, $sims);
    }

    // Fetch updated device
    $stmtFetch = $pdo->prepare("SELECT * FROM devices WHERE device_id = ? LIMIT 1");
    $stmtFetch->execute([$deviceId]);
    $device = $stmtFetch->fetch();
    $device['sims'] = getDeviceSimsFromDb($pdo, $deviceId);

    return $device;
}

function updateDeviceSimsInDb($pdo, $deviceId, $sims) {
    // Delete existing SIM records for device
    $stmtDel = $pdo->prepare("DELETE FROM device_sims WHERE device_id = ?");
    $stmtDel->execute([$deviceId]);

    $stmtIns = $pdo->prepare("
        INSERT INTO device_sims 
            (sim_id, device_id, slot_index, subscription_id, carrier_name, display_name, phone_number, is_active, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");

    foreach ($sims as $idx => $sim) {
        $slotIndex = (int)($sim['slot_index'] ?? ($idx + 1));
        $subId = (int)($sim['subscription_id'] ?? $slotIndex);
        $carrier = trim($sim['carrier_name'] ?? $sim['carrier'] ?? 'SIM ' . $slotIndex);
        $display = trim($sim['display_name'] ?? $carrier);
        $phone = trim($sim['phone_number'] ?? $sim['phone'] ?? '');

        $stmtIns->execute([
            'sim_' . $slotIndex,
            $deviceId,
            $slotIndex,
            $subId,
            $carrier,
            $display,
            $phone
        ]);
    }
}

function getDeviceSimsFromDb($pdo, $deviceId) {
    $stmt = $pdo->prepare("SELECT * FROM device_sims WHERE device_id = ? ORDER BY slot_index ASC");
    $stmt->execute([$deviceId]);
    return $stmt->fetchAll();
}

<?php
session_start();
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), $_COOKIE[session_name()], [
        'expires' => 0, 'path' => '/', 'secure' => true,
        'httponly' => true, 'samesite' => 'Strict'
    ]);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/version.php';

try {
    $pdo = getDbConnection();
} catch (Exception $e) {
    if (!file_exists(__DIR__ . '/../config/installed.lock') || !file_exists(__DIR__ . '/../config/db_credentials.php')) {
        header('Location: ../install/');
        exit;
    }
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Database Error - SMSLink</title><style>body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1320;font-family:sans-serif;margin:0;padding:20px;color:#f8fafc}.card{max-width:480px;width:100%;background:#fff;color:#0f172a;padding:32px;border-radius:20px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);text-align:center}h2{color:#dc2626;margin:0 0 12px;font-size:20px}p{color:#64748b;font-size:13px;line-height:1.6;margin-bottom:24px}.btn{display:inline-block;padding:12px 24px;background:#057d77;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;font-size:14px}</style></head><body><div class="card"><h2>Database Connection Error</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><a href="../install/?force=1" class="btn">Run Installation Wizard →</a></div></body></html>';
    exit;
}


$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$userCount = $stmt->fetchColumn();
if ($userCount == 0 && file_exists(__DIR__ . '/../config/admin.json')) {
    $admin = json_decode(file_get_contents(__DIR__ . '/../config/admin.json'), true);
    if (!empty($admin['username']) && !empty($admin['password'])) {
        $stmtIns = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmtIns->execute([$admin['username'], $admin['password']]);
    }
}

function logActivity($type, $detail, $meta = null) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO device_logs (log_type, detail, meta_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$type, $detail, $meta !== null ? json_encode($meta) : null]);
    } catch (Exception $e) {}
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

if (isset($_GET['export']) && !empty($_SESSION['tf_auth'])) {
    $type = $_GET['type'] ?? 'sent';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sms_gateway_'.$type.'_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output', 'w');
    if ($type === 'sent') {
        fputcsv($out, ['ID', 'Time','To','Message','SIM Slot','Status','Device','Error']);
        $stmt = $pdo->query("SELECT id, created_at, recipient, message_body, sim_slot, status, assigned_device_id, error_message FROM sms_messages ORDER BY id DESC");
        while ($r = $stmt->fetch()) {
            fputcsv($out, [$r['id'], $r['created_at'], $r['recipient'], $r['message_body'], $r['sim_slot'], $r['status'], $r['assigned_device_id'], $r['error_message']]);
        }
    } elseif ($type === 'received') {
        fputcsv($out, ['Time','From','Message','SIM Slot','Device']);
        $stmt = $pdo->query("SELECT received_at, sender, message_body, sim_slot, device_id FROM incoming_messages ORDER BY id DESC");
        while ($r = $stmt->fetch()) {
            fputcsv($out, [$r['received_at'], $r['sender'], $r['message_body'], $r['sim_slot'], $r['device_id']]);
        }
    }
    fclose($out);
    exit;
}

if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        if (($user['status'] ?? 'active') === 'pending') {
            echo json_encode(['ok' => false, 'error' => 'Account is pending activation. Please contact the administrator.']);
            exit;
        }
        $_SESSION['tf_auth'] = true;
        $_SESSION['tf_user_id'] = $user['id'];
        $_SESSION['tf_username'] = $user['username'];
        $_SESSION['tf_role'] = $user['role'] ?? 'admin';
        session_regenerate_id(true);
        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        logActivity('login', 'User logged in: ' . $username . ' [' . ($user['role'] ?? 'admin') . ']');
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid credentials']);
    }
    exit;
}

$isAuthed = !empty($_SESSION['tf_auth']);

function renderDevicesSectionContent($devices, $simsByDevice) {
    ob_start();
    ?>
    <div class="app-card-header">
      <div>
        <div class="app-card-title">Android Gateway Phones &amp; QR Code Pairing</div>
        <div class="app-card-sub">Instant device pairing using CameraX &amp; QR Code scanning</div>
      </div>
      <button class="app-btn app-btn-primary" onclick="openQrModal()"><svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg> Pair New Phone via QR Code</button>
    </div>

    <?php if (empty($devices)): ?>
    <div style="padding:48px 20px;text-align:center;background:#f8fafc;border:1px dashed var(--app-border-color);border-radius:14px">
      <div style="width:48px;height:48px;border-radius:12px;background:var(--primary-light);color:var(--primary);display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px">
        <svg style="width:24px;height:24px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
      </div>
      <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:6px">No Android Gateway Phones Connected</h3>
      <p style="font-size:13px;color:#64748b;margin-bottom:20px;max-width:400px;margin-left:auto;margin-right:auto">Scan the QR code using the SMS Android App on your phone to register your Dual-SIM gateway device.</p>
      <button class="app-btn app-btn-primary" onclick="openQrModal()"><svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg> Pair Phone via QR Code</button>
    </div>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:16px">
      <?php foreach ($devices as $d): 
        $online = !empty($d['status']) && $d['status'] === 'online' && !empty($d['last_seen']) && abs(time() - strtotime($d['last_seen'])) < 45;
        $devSims = $simsByDevice[$d['device_id']] ?? [];
      ?>
      <div style="border:1px solid var(--app-border-color);border-radius:14px;padding:20px;background:#FFF;box-shadow:0 1px 3px rgba(0,0,0,0.02)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
          <div style="display:flex;align-items:center;gap:12px">
            <div style="width:40px;height:40px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center">
              <svg style="width:20px;height:20px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </div>
            <div>
              <strong style="font-size:16px;color:#0f172a;display:block"><?php echo htmlspecialchars($d['device_name'] ?: $d['model']); ?></strong>
              <span class="mono" style="font-size:12px;color:#64748b">ID: <?php echo htmlspecialchars($d['device_id']); ?></span>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <span class="app-badge <?php echo $online ? 'app-badge-green' : 'app-badge-rose'; ?>"><?php echo $online ? '● Online' : '● Offline'; ?></span>
            <button class="app-btn app-btn-secondary" style="padding:6px 12px;font-size:12px" onclick="openQrModal()">Re-pair QR</button>
            <button class="app-btn" style="padding:6px 12px;font-size:12px;background:#fff1f2;color:#e11d48;border:1px solid #fecdd3" onclick="deleteDevice('<?php echo htmlspecialchars($d['device_id']); ?>', '<?php echo htmlspecialchars(addslashes($d['device_name'] ?: $d['model'])); ?>')">
              <svg style="width:14px;height:14px;display:inline;vertical-align:-2px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg> Delete
            </button>
          </div>
        </div>

        <div style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));display:grid;gap:12px;padding:14px;background:#f8fafc;border-radius:10px;font-size:13px;color:#475569;margin-bottom:14px">
          <div>Model: <strong style="color:#0f172a"><?php echo htmlspecialchars($d['model']??'N/A'); ?></strong></div>
          <div>Android OS: <strong style="color:#0f172a"><?php echo htmlspecialchars($d['android_version']??'N/A'); ?></strong></div>
          <div>Last Ping: <span class="mono" style="color:#0f172a"><?php echo htmlspecialchars($d['last_seen']??'Never'); ?></span></div>
          <div>Total Sent: <strong style="color:var(--primary)"><?php echo number_format($d['sms_sent_count']??0); ?> SMS</strong></div>
        </div>

        <div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px">Detected SIM Card Slots</div>
        <?php if (empty($devSims)): ?>
        <div style="font-size:12px;color:#94a3b8">No active SIM cards reported yet</div>
        <?php else: ?>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <?php foreach ($devSims as $s): ?>
          <div style="background:#FFF;border:1px solid #cbd5e1;padding:8px 14px;border-radius:8px;display:flex;align-items:center;gap:8px;font-size:12px">
            <span class="app-badge app-badge-teal">SIM <?php echo htmlspecialchars((string)($s['slot_index'] ?? '')); ?></span>
            <span style="font-weight:600;color:#0f172a"><?php echo htmlspecialchars((string)($s['carrier_name'] ?? 'SIM')); ?></span>
            <?php if (!empty($s['phone_number'])): ?>
            <span class="mono" style="color:#64748b">(<?php echo htmlspecialchars((string)$s['phone_number']); ?>)</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif;
    return ob_get_clean();
}

function renderSentMessagesTableRows($sentMessages) {
    ob_start();
    if (empty($sentMessages)): ?>
        <tr>
            <td colspan="8" style="text-align:center;padding:30px;color:#94a3b8;font-size:13px">No SMS messages found</td>
        </tr>
    <?php else:
        foreach ($sentMessages as $msg): 
          $st = strtolower($msg['status']);
          $badgeCls = ($st === 'sent' || $st === 'delivered') ? 'app-badge-green' : (($st === 'failed') ? 'app-badge-rose' : 'app-badge-amber');
          $stIcon = ($st === 'sent' || $st === 'delivered') 
            ? '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>' 
            : (($st === 'failed') 
              ? '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>' 
              : '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');
          $stText = ($st === 'sent' || $st === 'delivered') ? 'Sent' : (($st === 'failed') ? 'Failed' : ucfirst($st));
        ?>
        <tr data-status="<?php echo htmlspecialchars($st); ?>" data-sim="<?php echo (int)$msg['sim_slot']; ?>" data-text="<?php echo htmlspecialchars(strtolower($msg['to'] . ' ' . $msg['message'])); ?>">
          <td class="mono"><?php echo htmlspecialchars($msg['id']); ?></td>
          <td class="mono" style="font-weight:700;color:#0f172a"><?php echo htmlspecialchars($msg['to']); ?></td>
          <td style="max-width:300px;word-break:break-word"><?php echo htmlspecialchars($msg['message']); ?></td>
          <td><span class="app-badge app-badge-teal">SIM <?php echo $msg['sim_slot'] ?: 'Auto'; ?></span></td>
          <td>
            <span class="app-badge <?php echo $badgeCls; ?>"><?php echo $stIcon . $stText; ?></span>
            <?php if (!empty($msg['error'])): ?>
            <div style="font-size:11px;color:#dc2626;margin-top:2px"><?php echo htmlspecialchars($msg['error']); ?></div>
            <?php endif; ?>
          </td>
          <td class="mono"><?php echo htmlspecialchars(substr($msg['assigned_device']??'Auto',0,12)); ?></td>
          <td class="mono"><?php echo htmlspecialchars($msg['created_at']); ?></td>
          <td>
            <?php if ($st === 'failed'): ?>
            <button class="app-btn app-btn-secondary" style="padding:4px 10px;font-size:11px" onclick="retrySms('<?php echo htmlspecialchars($msg['id']); ?>')">Retry</button>
            <?php else: ?>
            <span style="color:#94a3b8;font-size:11px">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach;
    endif;
    return ob_get_clean();
}

if ($isAuthed && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_sent_messages_html') {
        $stmtSentMessages = $pdo->query("SELECT id, message_id, recipient AS `to`, message_body AS message, sim_slot, status, assigned_device_id AS assigned_device, created_at, sent_at, error_message AS error FROM sms_messages ORDER BY id DESC LIMIT 200");
        $sentMessages = $stmtSentMessages->fetchAll();
        $html = renderSentMessagesTableRows($sentMessages);
        echo json_encode(['ok' => true, 'html' => $html, 'count' => count($sentMessages)]);
        exit;
    }

    if ($action === 'send_sms') {
        $numbers    = $_POST['numbers'] ?? '';
        $message    = $_POST['message'] ?? '';
        $device     = $_POST['device'] ?? 'auto';
        $simSlot    = (int)($_POST['sim_slot'] ?? 0);
        $numberList = array_filter(array_map('trim', explode(',', $numbers)));
        if (empty($numberList)) { echo json_encode(['ok' => false, 'error' => 'No valid numbers provided']); exit; }
        if (empty($message))    { echo json_encode(['ok' => false, 'error' => 'Message content is required']); exit; }

        $stmtDev = $pdo->query("SELECT * FROM devices ORDER BY id DESC");
        $allDevices = $stmtDev->fetchAll();
        if (empty($allDevices)) {
            echo json_encode(['ok' => false, 'error' => 'No Android gateway device connected. Please pair a phone first.']);
            exit;
        }

        $assigned = null;
        if ($device !== 'auto' && !empty($device)) {
            $assigned = $device;
        } else {
            $stmtOnline = $pdo->query("SELECT device_id FROM devices WHERE status = 'online' ORDER BY id DESC LIMIT 1");
            $onlineDevId = $stmtOnline->fetchColumn();
            if ($onlineDevId) {
                $assigned = $onlineDevId;
            }
        }

        $msgId = 'msg_' . uniqid();

        $stmtIns = $pdo->prepare("INSERT INTO sms_messages (message_id, recipient, message_body, sim_slot, status, assigned_device_id, source, created_at) VALUES (?, ?, ?, ?, 'queued', ?, 'dashboard', NOW())");
        foreach ($numberList as $num) {
            $stmtIns->execute([$msgId, $num, $message, $simSlot, $assigned]);
        }

        logActivity('sms_sent', 'Queued SMS to ' . count($numberList) . ' number(s) [SIM ' . ($simSlot ?: 'Auto') . ']: ' . implode(', ', $numberList));
        echo json_encode(['ok' => true, 'msg_id' => $msgId, 'count' => count($numberList), 'device' => $assigned ?: 'Auto', 'sim_slot' => $simSlot]);
        exit;
    }

    if ($action === 'check_pairing_status') {
        $token = trim($_GET['token'] ?? $_POST['token'] ?? '');
        $code = trim($_GET['pairing_code'] ?? $_POST['pairing_code'] ?? $_GET['code'] ?? '');

        $pairedDev = null;
        if (!empty($token) || !empty($code)) {
            $stmtP = $pdo->prepare("SELECT * FROM devices WHERE ((api_token IS NOT NULL AND api_token != '' AND api_token = ?) OR (pairing_code IS NOT NULL AND pairing_code != '' AND pairing_code = ?)) AND model != 'Awaiting pairing...' ORDER BY id DESC LIMIT 1");
            $stmtP->execute([$token, $code]);
            $pairedDev = $stmtP->fetch();
        }

        if ($pairedDev && !empty($pairedDev['device_id'])) {
            echo json_encode([
                'ok' => true,
                'paired' => true,
                'device_id' => $pairedDev['device_id'],
                'device_name' => $pairedDev['device_name'] ?: $pairedDev['model'],
                'model' => $pairedDev['model'],
                'android_version' => $pairedDev['android_version']
            ]);
        } else {
            echo json_encode(['ok' => true, 'paired' => false]);
        }
        exit;
    }

    if ($action === 'delete_device') {
        $devId = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');
        if (!empty($devId)) {
            $realDeviceId = null;
            if (is_numeric($devId)) {
                $s = $pdo->prepare("SELECT device_id FROM devices WHERE id = ? OR device_id = ? LIMIT 1");
                $s->execute([(int)$devId, $devId]);
                $realDeviceId = $s->fetchColumn();
            } else {
                $s = $pdo->prepare("SELECT device_id FROM devices WHERE device_id = ? LIMIT 1");
                $s->execute([$devId]);
                $realDeviceId = $s->fetchColumn();
            }

            $targetDevId = $realDeviceId ?: $devId;
            if (is_numeric($devId)) {
                $pdo->prepare("DELETE FROM devices WHERE id = ? OR device_id = ?")->execute([(int)$devId, $targetDevId]);
            } else {
                $pdo->prepare("DELETE FROM devices WHERE device_id = ?")->execute([$targetDevId]);
            }
            $pdo->prepare("DELETE FROM device_sims WHERE device_id = ?")->execute([$targetDevId]);
            logActivity('device_delete', 'Deleted device: ' . $targetDevId);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Device ID required']);
        }
        exit;
    }

    if ($action === 'get_devices_html') {
        // Auto cleanup devices offline for >= 30 days
        try {
            $pdo->exec("DELETE FROM devices WHERE (last_seen IS NOT NULL AND TIMESTAMPDIFF(DAY, last_seen, NOW()) >= 30) OR (last_seen IS NULL AND TIMESTAMPDIFF(DAY, created_at, NOW()) >= 30)");
            $pdo->exec("DELETE FROM devices WHERE model = 'Awaiting pairing...'");
        } catch (Exception $e) {}

        $stmtDevs = $pdo->query("SELECT * FROM devices ORDER BY id DESC");
        $devices = $stmtDevs->fetchAll();

        $stmtSims = $pdo->query("SELECT * FROM device_sims ORDER BY slot_index ASC");
        $allSims = $stmtSims->fetchAll();
        $simsByDevice = [];
        foreach ($allSims as $sim) {
            $simsByDevice[$sim['device_id']][] = $sim;
        }

        $html = renderDevicesSectionContent($devices, $simsByDevice);
        echo json_encode(['ok' => true, 'html' => $html, 'count' => count($devices)]);
        exit;
    }

    if ($action === 'generate_pairing_qr') {
        $pairingCode = 'PAIR_' . strtoupper(bin2hex(random_bytes(4)));
        $token = 'dev_tok_' . bin2hex(random_bytes(12));
        $sysSettings = getSystemSettings($pdo);
        $currentUrl = !empty($sysSettings['app_url']) ? $sysSettings['app_url'] : getAutoDetectedBaseUrl();

        $qrData = json_encode([
            'server_url' => $currentUrl,
            'pairing_code' => $pairingCode,
            'token' => $token
        ]);

        echo json_encode(['ok' => true, 'qr_data' => $qrData, 'pairing_code' => $pairingCode, 'token' => $token]);
        exit;
    }

    if ($action === 'retry_sms') {
        $msgId = trim($_POST['msg_id'] ?? '');
        if (is_numeric($msgId)) {
            $pdo->prepare("UPDATE sms_messages SET status = 'queued', error_message = NULL WHERE id = ? OR message_id = ?")->execute([(int)$msgId, $msgId]);
        } else {
            $pdo->prepare("UPDATE sms_messages SET status = 'queued', error_message = NULL WHERE message_id = ?")->execute([$msgId]);
        }
        logActivity('sms_sent', 'Retried SMS: ' . $msgId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'gen_api_key') {
        $keyName = trim($_POST['key_name'] ?? '');
        if (empty($keyName)) { echo json_encode(['ok' => false, 'error' => 'Key name is required']); exit; }
        $raw = 'sk_live_' . bin2hex(random_bytes(20));
        $hash = hash('sha256', $raw);
        $preview = $raw;
        $keyId = 'key_' . uniqid();

        $stmt = $pdo->prepare("INSERT INTO api_tokens (key_id, name, token_hash, preview, permissions, created_at) VALUES (?, ?, ?, ?, '[\"send\",\"status\"]', NOW())");
        $stmt->execute([$keyId, $keyName, $hash, $preview]);
        logActivity('api_key', 'Generated API key: ' . $keyName);
        echo json_encode([
            'ok' => true,
            'key' => $raw,
            'key_id' => $keyId,
            'name' => $keyName,
            'preview' => $preview,
            'created_at' => date('M d, Y H:i')
        ]);
        exit;
    }

    if ($action === 'delete_api_key') {
        $keyId = trim($_POST['key_id'] ?? $_GET['key_id'] ?? '');
        if (empty($keyId)) { echo json_encode(['ok' => false, 'error' => 'Key ID required']); exit; }
        if (is_numeric($keyId)) {
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE id = ? OR key_id = ?");
            $stmt->execute([(int)$keyId, $keyId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM api_tokens WHERE key_id = ?");
            $stmt->execute([$keyId]);
        }
        logActivity('api_key_revoked', 'Revoked API key ID: ' . $keyId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($username)) {
            echo json_encode(['ok' => false, 'error' => 'Username cannot be empty']);
            exit;
        }

        $userId = $_SESSION['tf_user_id'] ?? 1;
        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch();
        if (!$user) {
            $stmtUser = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
            $user = $stmtUser->fetch();
        }

        $avatarPath = $user['avatar_path'] ?? null;

        // Process Avatar File Upload
        if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['avatar_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (!in_array($ext, $allowed)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid file format. Allowed: JPG, PNG, WEBP, GIF']);
                exit;
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'Image size must be under 5MB']);
                exit;
            }

            $uploadDir = __DIR__ . '/uploads/avatars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $newFileName = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $avatarPath = 'uploads/avatars/' . $newFileName;
            }
        }

        if (!empty($newPassword)) {
            if (empty($currentPassword) || !password_verify($currentPassword, $user['password_hash'])) {
                echo json_encode(['ok' => false, 'error' => 'Current password is required to change password']);
                exit;
            }
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['ok' => false, 'error' => 'New password confirmation does not match']);
                exit;
            }
            if (strlen($newPassword) < 4) {
                echo json_encode(['ok' => false, 'error' => 'New password must be at least 4 characters long']);
                exit;
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmtUpd = $pdo->prepare("UPDATE users SET username = ?, password_hash = ?, avatar_path = ? WHERE id = ?");
            $stmtUpd->execute([$username, $newHash, $avatarPath, $user['id']]);
        } else {
            $stmtUpd = $pdo->prepare("UPDATE users SET username = ?, avatar_path = ? WHERE id = ?");
            $stmtUpd->execute([$username, $avatarPath, $user['id']]);
        }

        $_SESSION['tf_username'] = $username;
        logActivity('profile_update', 'Admin updated profile/avatar: ' . $username);
        echo json_encode(['ok' => true, 'message' => 'Profile updated successfully']);
        exit;
    }

    if ($action === 'update_settings') {
        $appUrl  = trim($_POST['app_url'] ?? getAutoDetectedBaseUrl());
        $themeColor = trim($_POST['theme_color'] ?? '#057d77');
        $themeHover = trim($_POST['theme_color_hover'] ?? '#04635e');

        $appUrl = rtrim($appUrl, '/');

        $stmtSave = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmtSave->execute(['app_name', 'SMSLink']);
        $stmtSave->execute(['app_url', $appUrl]);
        $stmtSave->execute(['theme_color', $themeColor]);
        $stmtSave->execute(['theme_color_hover', $themeHover]);

        logActivity('settings_update', 'Admin updated system settings (Base URL: ' . $appUrl . ')');
        echo json_encode(['ok' => true, 'message' => 'Settings updated successfully!']);
        exit;
    }

    if ($action === 'add_team_member') {
        if (($_SESSION['tf_role'] ?? 'admin') !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Permission denied: Administrator role required']);
            exit;
        }
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $status = in_array($_POST['status'] ?? '', ['active', 'pending']) ? $_POST['status'] : 'active';

        if (empty($username)) {
            echo json_encode(['ok' => false, 'error' => 'Username is required']);
            exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
            echo json_encode(['ok' => false, 'error' => 'Username can only contain alphanumeric characters, dots, underscores and hyphens']);
            exit;
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'A valid email address is required']);
            exit;
        }

        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmtChk->execute([$username, $email]);
        if ($stmtChk->fetchColumn() > 0) {
            echo json_encode(['ok' => false, 'error' => 'A user with this username or email already exists']);
            exit;
        }

        $generatedPass = null;
        if (empty($password)) {
            $generatedPass = bin2hex(random_bytes(5));
            $password = $generatedPass;
        } elseif (strlen($password) < 4) {
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 4 characters long']);
            exit;
        }

        $passHash = password_hash($password, PASSWORD_DEFAULT);
        $stmtIns = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, created_at) VALUES (?, ?, ?, 'member', ?, NOW())");
        $stmtIns->execute([$username, $email, $passHash, $status]);

        logActivity('team_member_added', "Added team member: {$username} ({$email}) [Status: {$status}]");
        echo json_encode([
            'ok' => true,
            'message' => 'Team member created successfully!',
            'generated_password' => $generatedPass
        ]);
        exit;
    }

    if ($action === 'set_team_member_password') {
        if (($_SESSION['tf_role'] ?? 'admin') !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Permission denied: Administrator role required']);
            exit;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if (!$userId || strlen($newPassword) < 4) {
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 4 characters long']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmtUpd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmtUpd->execute([$hash, $userId]);

        logActivity('password_reset', "Admin manually set password for user ID: {$userId}");
        echo json_encode(['ok' => true, 'message' => 'Password updated successfully!']);
        exit;
    }

    if ($action === 'delete_team_member') {
        if (($_SESSION['tf_role'] ?? 'admin') !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Permission denied: Administrator role required']);
            exit;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        $currId = (int)($_SESSION['tf_user_id'] ?? 0);

        if ($userId === $currId) {
            echo json_encode(['ok' => false, 'error' => 'You cannot delete your own account']);
            exit;
        }

        $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmtDel->execute([$userId]);

        logActivity('team_member_deleted', "Deleted team member ID: {$userId}");
        echo json_encode(['ok' => true, 'message' => 'Team member deleted']);
        exit;
    }

    if ($action === 'toggle_member_status') {
        if (($_SESSION['tf_role'] ?? 'admin') !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Permission denied: Administrator role required']);
            exit;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $uRow = $stmt->fetch();
        if ($uRow) {
            if ($uRow['role'] === 'admin') {
                echo json_encode(['ok' => false, 'error' => 'Cannot modify administrator status']);
                exit;
            }
            $newStatus = ($uRow['status'] === 'active') ? 'pending' : 'active';
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
            logActivity('status_toggle', "Toggled user ID {$userId} status to {$newStatus}");
            echo json_encode(['ok' => true, 'status' => $newStatus]);
            exit;
        }
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        exit;
    }

    if ($action === 'check_updates') {
        $repo = defined('APP_REPO') ? APP_REPO : 'beingniloy/smslink';
        $currentVersion = defined('APP_VERSION') ? APP_VERSION : 'v1.0.0';
        $latestTag = null;
        $releaseName = null;
        $publishedAt = null;
        $changelog = null;
        $htmlUrl = "https://github.com/{$repo}/releases";

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: SMSLink-Updater\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 8
            ]
        ];
        $ctx = stream_context_create($opts);

        
        $json = @file_get_contents("https://api.github.com/repos/{$repo}/releases/latest", false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if (!empty($data['tag_name'])) {
                $latestTag = $data['tag_name'];
                $releaseName = $data['name'] ?? $latestTag;
                $publishedAt = isset($data['published_at']) ? date('M d, Y', strtotime($data['published_at'])) : date('M d, Y');
                $changelog = $data['body'] ?? 'No changelog details provided.';
                $htmlUrl = $data['html_url'] ?? $htmlUrl;
            }
        }

        
        if (!$latestTag) {
            $tagsJson = @file_get_contents("https://api.github.com/repos/{$repo}/tags", false, $ctx);
            if ($tagsJson) {
                $tagsData = json_decode($tagsJson, true);
                if (!empty($tagsData) && isset($tagsData[0]['name'])) {
                    $latestTag = $tagsData[0]['name'];
                    $releaseName = "Release " . $latestTag;
                    $publishedAt = date('M d, Y');
                    $changelog = "New tag " . $latestTag . " released on GitHub repository.";
                    $htmlUrl = "https://github.com/{$repo}/releases/tag/{$latestTag}";
                }
            }
        }

        if (!$latestTag) {
            $latestTag = $currentVersion;
            $releaseName = "SMSLink Official Stable Release";
            $publishedAt = defined('APP_BUILD_DATE') ? date('M d, Y', strtotime(APP_BUILD_DATE)) : date('M d, Y');
            $changelog = "You are running the current verified stable release of SMSLink.";
        }

        $v1 = ltrim($currentVersion, 'v');
        $v2 = ltrim($latestTag, 'v');
        $hasUpdate = version_compare($v2, $v1, '>');

        echo json_encode([
            'ok' => true,
            'has_update' => $hasUpdate,
            'current_version' => $currentVersion,
            'latest_version' => $latestTag,
            'release_name' => $releaseName,
            'published_at' => $publishedAt,
            'changelog' => $changelog,
            'html_url' => $htmlUrl
        ]);
        exit;
    }

    if ($action === 'apply_update') {
        if (($_SESSION['tf_role'] ?? 'admin') !== 'admin') {
            echo json_encode(['ok' => false, 'error' => 'Permission denied: Administrator role required']);
            exit;
        }

        $targetVer = trim($_POST['target_version'] ?? '');
        if (empty($targetVer)) {
            $targetVer = defined('APP_VERSION') ? APP_VERSION : 'v1.0.0';
        }

        
        ensureTablesExist($pdo);

        
        $stmtSync = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmtSync->execute(['app_name', 'SMSLink']);

        
        $verContent = "<?php\nif (!defined('APP_VERSION')) define('APP_VERSION', " . var_export($targetVer, true) . ");\nif (!defined('APP_BUILD_DATE')) define('APP_BUILD_DATE', " . var_export(date('Y-m-d'), true) . ");\nif (!defined('APP_REPO')) define('APP_REPO', 'beingniloy/smslink');\nreturn ['version' => APP_VERSION, 'build_date' => APP_BUILD_DATE, 'repo' => APP_REPO];\n";
        @file_put_contents(__DIR__ . '/../config/version.php', $verContent);

        logActivity('system_update', "Applied system update and executed DB migrations to version: {$targetVer}");
        echo json_encode([
            'ok' => true,
            'message' => 'System and database schema updated successfully!',
            'version' => $targetVer
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

if (empty($_SESSION['tf_role']) && !empty($_SESSION['tf_user_id'])) {
    $stmtR = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmtR->execute([$_SESSION['tf_user_id']]);
    $_SESSION['tf_role'] = $stmtR->fetchColumn() ?: 'admin';
}
$currentUserRole = $_SESSION['tf_role'] ?? 'admin';

$stmtTeam = $pdo->query("SELECT id, username, email, role, status, avatar_path, created_at, last_login FROM users ORDER BY id ASC");
$teamMembers = $stmtTeam->fetchAll();


$totalSent = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered')")->fetchColumn();
$failedSent = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status = 'failed'")->fetchColumn();
$monthSent = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$weekSent  = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$queuedCount = $pdo->query("SELECT COUNT(*) FROM sms_messages WHERE status = 'queued'")->fetchColumn();


$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $label = date('M d', strtotime("-$i days"));
    
    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM sms_messages WHERE status IN ('sent', 'delivered') AND DATE(created_at) = ?");
    $stmtS->execute([$d]);
    $sentCount = (int)$stmtS->fetchColumn();

    $stmtF = $pdo->prepare("SELECT COUNT(*) FROM sms_messages WHERE status = 'failed' AND DATE(created_at) = ?");
    $stmtF->execute([$d]);
    $failCount = (int)$stmtF->fetchColumn();

    $last7Days[] = [
        'label' => $label,
        'sent' => $sentCount,
        'failed' => $failCount
    ];
}

$chartLabelsJson = json_encode(array_column($last7Days, 'label'));
$chartSentJson = json_encode(array_column($last7Days, 'sent'));
$chartFailedJson = json_encode(array_column($last7Days, 'failed'));

$stmtLogs = $pdo->query("SELECT log_type AS type, detail, created_at AS timestamp FROM device_logs ORDER BY id DESC LIMIT 10");
$recentLogs = $stmtLogs->fetchAll();

$stmtSentMessages = $pdo->query("SELECT id, message_id, recipient AS `to`, message_body AS message, sim_slot, status, assigned_device_id AS assigned_device, created_at, sent_at, error_message AS error FROM sms_messages ORDER BY id DESC LIMIT 200");
$sentMessages = $stmtSentMessages->fetchAll();

$stmtReceived = $pdo->query("SELECT received_at AS timestamp, sender, message_body AS message, sim_slot, device_id FROM incoming_messages ORDER BY id DESC LIMIT 100");
$receivedLogs = $stmtReceived->fetchAll();

$stmtDevs = $pdo->query("SELECT * FROM devices ORDER BY id DESC");
$devices = $stmtDevs->fetchAll();

$stmtSims = $pdo->query("SELECT * FROM device_sims ORDER BY slot_index ASC");
$allSims = $stmtSims->fetchAll();
$simsByDevice = [];
foreach ($allSims as $sim) {
    $simsByDevice[$sim['device_id']][] = $sim;
}

$onlineDevices = array_filter($devices, fn($d) => !empty($d['status']) && $d['status'] === 'online' && !empty($d['last_seen']) && abs(time() - strtotime($d['last_seen'])) < 45);
$allDevicesOffline = (!empty($devices) && empty($onlineDevices));

$stmtKeys = $pdo->query("SELECT * FROM api_tokens ORDER BY id DESC");
$apiKeys = $stmtKeys->fetchAll();

$stmtAdmin = $pdo->query("SELECT * FROM users ORDER BY id ASC LIMIT 1");
$adminRow = $stmtAdmin->fetch() ?: [];
$adminUser = $adminRow['username'] ?? ($_SESSION['tf_username'] ?? 'Admin');
$adminAvatar = !empty($adminRow['avatar_path']) ? $adminRow['avatar_path'] : null;

$sysSettings = getSystemSettings($pdo);
$appName = 'SMSLink';
$baseUrl = !empty($sysSettings['app_url']) ? $sysSettings['app_url'] : getAutoDetectedBaseUrl();
$appUrl = $baseUrl;
$themeColor = !empty($sysSettings['theme_color']) ? $sysSettings['theme_color'] : '#057d77';
$themeHover = !empty($sysSettings['theme_color_hover']) ? $sysSettings['theme_color_hover'] : '#04635e';
$secTitles = [
    'status' => 'Dashboard',
    'send' => 'Send SMS',
    'sent' => 'Sent Messages History',
    'devices' => 'Devices & QR Pair',
    'apikeys' => 'API Keys',
    'docs' => 'API Documentation',
    'team' => 'Team Management',
    'settings' => 'System Settings',
    'updates' => 'System Updates',
    'about' => 'About SMSLink',
    'profile' => 'Account Profile'
];
$reqPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$pathParts = explode('/', $reqPath);
$currentSec = end($pathParts);
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/dashboard/index.php'), '/\\');
$dashBaseUrl = (strpos($scriptDir, 'dashboard') !== false) ? $scriptDir : rtrim($scriptDir, '/') . '/dashboard';
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($appName . ' - ' . $initialSecTitle); ?></title>
<link rel="icon" type="image/png" href="https://cdn.niloy.io/projects/smslink/favicon.png">
<link rel="shortcut icon" type="image/png" href="https://cdn.niloy.io/projects/smslink/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root {
  --primary:<?php echo htmlspecialchars($themeColor); ?>;
  --primary-hover:<?php echo htmlspecialchars($themeHover); ?>;
  --primary-light:rgba(<?php list($r,$g,$b)=sscanf($themeColor,"#%02x%02x%02x"); echo "$r,$g,$b"; ?>,0.12);
  --primary-tint:rgba(<?php list($r,$g,$b)=sscanf($themeColor,"#%02x%02x%02x"); echo "$r,$g,$b"; ?>,0.15);
  --app-bg-sidebar:#ffffff;
  --app-bg-content:#f8fafc;
  --app-border-color:#e2e8f0;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;font-family:'Inter',sans-serif;color:#0f172a;background:var(--app-bg-content);-webkit-font-smoothing:antialiased}

/* Modern QR Modal & Checkmark Animations */
.qr-success-card {
  padding: 24px 20px;
  text-align: center;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 16px;
  width: 100%;
  box-shadow: 0 4px 14px rgba(16, 185, 129, 0.08);
}
.qr-success-icon-wrap {
  width: 56px;
  height: 56px;
  margin: 0 auto 14px;
  border-radius: 50%;
  background: #dcfce7;
  display: flex;
  align-items: center;
  justify-content: center;
}
.qr-check-icon {
  width: 36px;
  height: 36px;
  stroke: #10b981;
  stroke-width: 3.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  animation: qrCheckScale 0.4s ease-in-out;
}
.qr-check-circle {
  stroke-dasharray: 166;
  stroke-dashoffset: 166;
  stroke: #10b981;
  animation: qrCheckStroke 0.6s cubic-bezier(0.65, 0, 0.45, 1) forwards;
}
.qr-check-path {
  stroke-dasharray: 48;
  stroke-dashoffset: 48;
  animation: qrCheckStroke 0.4s cubic-bezier(0.65, 0, 0.45, 1) 0.3s forwards;
}
@keyframes qrCheckStroke {
  100% { stroke-dashoffset: 0; }
}
@keyframes qrCheckScale {
  0%, 100% { transform: none; }
  50% { transform: scale3d(1.1, 1.1, 1); }
}
.qr-success-title {
  font-size: 16px;
  font-weight: 700;
  color: #065f46;
  margin-bottom: 4px;
  letter-spacing: -0.01em;
}
.qr-success-sub {
  font-size: 13px;
  color: #047857;
  margin-bottom: 14px;
}
.qr-success-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #059669;
  background: #ffffff;
  border: 1px solid #a7f3d0;
  padding: 5px 14px;
  border-radius: 20px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.qr-pulse-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: qrPulse 1.6s infinite;
}
@keyframes qrPulse {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
.qr-status-spinner {
  display: inline-block;
  width: 14px;
  height: 14px;
  border: 2px solid #0284c7;
  border-top-color: transparent;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:9999px}
::-webkit-scrollbar-thumb:hover{background:#94a3b8}
*{scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}

.app-layout{display:flex;height:100vh;overflow:hidden;background:#f8fafc}
.app-sidebar{width:260px;height:100vh;background:#FFF;color:#475569;flex-shrink:0;display:flex;flex-direction:column;border-right:1px solid var(--app-border-color);position:fixed;top:0;bottom:0;left:0;z-index:40;transition:transform 0.2s ease-in-out}
.app-sidebar-header{height:64px;padding:0 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--app-border-color)}

.app-brand{display:flex;align-items:baseline;gap:6px;font-size:18px;font-weight:700;color:#0f172a;letter-spacing:-0.03em;text-decoration:none}
.app-brand span{color:var(--primary);font-weight:400}
.app-brand-ver{font-size:10px;font-family:'Fira Code',monospace;background:var(--primary-tint);color:var(--primary);padding:2px 6px;border-radius:4px;font-weight:500}

.app-sidebar-nav{flex:1;padding:16px 12px;overflow-y:auto;display:flex;flex-direction:column;gap:20px}
.app-nav-group-title{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.08em;padding:0 12px 8px;opacity:0.8}
.app-nav-items{display:flex;flex-direction:column;gap:2px}
.app-nav-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;color:#475569;font-size:13px;font-weight:500;cursor:pointer;transition:all 0.15s;text-decoration:none}
.app-nav-item:hover{background:#f1f5f9;color:#0f172a}
.app-nav-item.active{background:var(--primary-light);color:var(--primary);font-weight:600}
.app-nav-item svg{width:18px;height:18px;flex-shrink:0;color:currentColor}

.app-sidebar-footer{padding:16px;border-top:1px solid var(--app-border-color);display:flex;align-items:center;justify-content:space-between}
.app-user-avatar{width:32px;height:32px;border-radius:50%;background:var(--primary);color:#ffffff;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px}

.app-main-wrapper{flex:1;height:100vh;margin-left:260px;display:flex;flex-direction:column;overflow:hidden;min-width:0}
.app-topbar{height:64px;flex-shrink:0;background:#FFF;border-bottom:1px solid var(--app-border-color);display:flex;align-items:center;justify-content:space-between;padding:0 28px;z-index:30}
.app-page-title{font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.02em}

.app-content-scroll{flex:1;overflow-y:auto;overflow-x:hidden;width:100%}

.app-status-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:9999px;font-size:12px;font-weight:600;background:var(--primary-light);color:var(--primary);border:1px solid rgba(5,125,119,0.25)}
.app-status-badge.offline{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
.app-pulse-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);box-shadow:0 0 0 0 rgba(5,125,119,0.7);animation:pulse 2s infinite}
.app-status-badge.offline .app-pulse-dot{background:#ef4444;box-shadow:none;animation:none}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(5,125,119,0.7)}70%{box-shadow:0 0 0 8px rgba(5,125,119,0)}100%{box-shadow:0 0 0 0 rgba(5,125,119,0)}}

.app-content{padding:28px;max-width:1400px;width:100%;margin:0 auto}

.app-widget-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:24px}
.app-card{background:#FFF;border:1px solid var(--app-border-color);border-radius:16px;box-shadow:0 1px 3px 0 rgba(15,23,42,0.03);padding:24px;margin-bottom:24px}
.app-card-header{margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.app-card-title{font-size:16px;font-weight:700;color:#0f172a}
.app-card-sub{font-size:13px;color:#64748b;margin-top:2px}

.app-stat-card{background:#FFF;border:1px solid var(--app-border-color);border-radius:16px;padding:20px;box-shadow:0 1px 3px 0 rgba(15,23,42,0.03)}
.app-stat-label{font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em}
.app-stat-val{font-size:30px;font-weight:800;color:#0f172a;margin-top:6px;letter-spacing:-0.03em}
.app-stat-sub{font-size:12px;color:var(--primary);font-weight:600;margin-top:4px;display:flex;align-items:center;gap:4px}

.app-form-group{margin-bottom:18px}
.app-label{display:block;font-size:12px;font-weight:600;color:#334155;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px}
.app-input,.app-select,.app-textarea{width:100%;padding:10px 14px;background:#FFF;border:1px solid #cbd5e1;border-radius:10px;color:#0f172a;font-size:14px;outline:none;transition:all 0.15s}
.app-input:focus,.app-select:focus,.app-textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(5,125,119,0.18)}
.app-textarea{min-height:100px;resize:vertical}
.app-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px}

.app-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;font-size:13px;padding:10px 18px;border-radius:10px;border:none;cursor:pointer;transition:all 0.15s;text-decoration:none}
.app-btn-primary{background:var(--primary);color:#FFF;box-shadow:0 1px 2px rgba(5,125,119,0.25)}.app-btn-primary:hover{background:var(--primary-hover)}
.app-btn-secondary{background:#FFF;border:1px solid #cbd5e1;color:#334155}.app-btn-secondary:hover{background:#f8fafc}
.app-btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.app-btn-danger:hover{background:#fee2e2}

.app-table-wrap{border:1px solid var(--app-border-color);border-radius:12px;overflow:hidden;background:#FFF}
.app-table{width:100%;border-collapse:collapse;font-size:13px;text-align:left}
.app-table th{background:#f8fafc;padding:12px 16px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid var(--app-border-color)}
.app-table td{padding:14px 16px;border-bottom:1px solid #f1f5f9;color:#334155}
.app-table tr:last-child td{border-bottom:none}
.app-table tr:hover td{background:#f8fafc}

.app-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;font-family:'Fira Code',monospace}
.app-badge-green{background:var(--primary-light);color:var(--primary);border:1px solid rgba(5,125,119,0.3)}
.app-badge-amber{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.app-badge-rose{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.app-badge-teal{background:var(--primary-light);color:var(--primary);border:1px solid rgba(5,125,119,0.3)}

.mono{font-family:'Fira Code',monospace;font-size:12px}
.section{display:none}.section.active{display:block}

.app-login-bg{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;padding:20px}
.app-login-card{width:100%;max-width:400px;background:#FFF;border-radius:20px;padding:32px;box-shadow:0 25px 50px -12px rgba(15,23,42,0.12);border:1px solid var(--app-border-color)}

.app-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,0.65);backdrop-filter:blur(4px);z-index:999;display:none;align-items:center;justify-content:center;padding:20px}
.app-modal-card{background:#FFF;border-radius:20px;width:100%;max-width:440px;padding:28px;text-align:center;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);border:1px solid var(--app-border-color)}

.app-mobile-toggle{display:none;background:none;border:1px solid var(--app-border-color);padding:6px 10px;border-radius:8px;font-size:18px;cursor:pointer;color:#334155;line-height:1}
.app-sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(3px);z-index:39}
.app-user-profile-btn{display:flex;align-items:center;gap:10px;text-decoration:none;cursor:pointer;padding:6px 8px;border-radius:8px;transition:background 0.15s;flex:1}
.app-user-profile-btn:hover{background:#f1f5f9}

@media(max-width:1024px){
  .app-content{padding:20px}
  .app-topbar{padding:0 20px}
}
@media(max-width:768px){
  .app-mobile-toggle{display:inline-flex;align-items:center;justify-content:center}
  .app-sidebar{transform:translateX(-100%);box-shadow:4px 0 24px rgba(0,0,0,0.25)}
  .app-sidebar.mobile-open{transform:translateX(0)}
  .app-sidebar-overlay.mobile-open{display:block}
  .app-main-wrapper{margin-left:0}
  .app-topbar{padding:0 12px;height:56px;gap:8px}
  .app-page-title{font-size:15px;max-width:120px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .app-topbar-search-wrap{max-width:160px;transition:max-width 0.2s ease}
  .app-topbar-search-wrap:focus-within{max-width:240px}
  .app-topbar-search-wrap input{font-size:12px;padding-left:30px;height:34px}
  .app-topbar-sponsor-btn span{display:none}
  .app-topbar-sponsor-btn{padding:7px;border-radius:50%}
  .app-topbar-user-name{display:none}
  .app-topbar-user-btn{padding:3px;background:none;border:none}
  .app-content{padding:16px}
  .app-card{padding:16px;border-radius:14px;margin-bottom:16px}
  .app-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .app-table{min-width:640px}
  .app-modal-card{padding:20px;border-radius:16px}
  .app-btn{padding:8px 14px;font-size:12px}
}
@media(max-width:480px){
  .app-page-title{font-size:14px;max-width:90px}
  .app-topbar-search-wrap{max-width:110px}
  .app-topbar-search-wrap:focus-within{max-width:180px}
}
</style>
</head>
<body>

<?php if (!$isAuthed): ?>
<div class="app-login-bg">
  <div class="app-login-card">
    <div style="text-align:center;margin-bottom:24px">
      <div style="display:flex;align-items:center;justify-content:center;margin-bottom:8px">
        <img src="https://cdn.niloy.io/projects/smslink/logo.png" alt="SMSLink" style="height:40px;max-width:220px;object-fit:contain;display:block" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
        <div class="brand-text-fallback" style="display:none;align-items:baseline;justify-content:center;font-size:26px;font-weight:700;color:#0f172a;letter-spacing:-0.03em">SMS<span style="color:var(--primary);font-weight:400">Link</span></div>
      </div>
      <p style="font-size:13px;color:#64748b;margin-top:4px">Sign in to Dashboard</p>
    </div>
    <div id="loginError" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px;border-radius:8px;font-size:13px;margin-bottom:16px;text-align:center"></div>
    <form id="loginForm">
      <div class="app-form-group">
        <label class="app-label">Username</label>
        <input type="text" name="username" class="app-input" placeholder="admin" required autofocus>
      </div>
      <div class="app-form-group" style="margin-bottom:24px">
        <label class="app-label">Password</label>
        <input type="password" name="password" class="app-input" placeholder="••••••••" required>
      </div>
      <button type="submit" class="app-btn app-btn-primary" style="width:100%;padding:12px">Sign in</button>
    </form>
  </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit', function(e){
  e.preventDefault();
  const err = document.getElementById('loginError');
  const fd = new FormData(this);
  fetch('?login=1', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok) location.reload();
    else { err.textContent = d.error || 'Invalid credentials'; err.style.display = 'block'; }
  }).catch(()=>{ err.textContent = 'Network error'; err.style.display = 'block'; });
});
</script>
<?php else: ?>

<div class="app-sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

<div class="app-layout">
  <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

  <main class="app-main-wrapper">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <div class="app-content-scroll">
      <div class="app-content">
      
      <div class="section active" id="section-status">
        <div class="app-widget-grid">
          <div class="app-stat-card">
            <div class="app-stat-label">Total Sent SMS</div>
            <div class="app-stat-val"><?php echo number_format($totalSent); ?></div>
            <div class="app-stat-sub">Lifetime Outbound Volume</div>
          </div>
          <div class="app-stat-card">
            <div class="app-stat-label">Failed SMS</div>
            <div class="app-stat-val" style="color:#dc2626"><?php echo number_format($failedSent); ?></div>
            <div class="app-stat-sub" style="color:#dc2626">Requires retry</div>
          </div>
          <div class="app-stat-card">
            <div class="app-stat-label">This Month</div>
            <div class="app-stat-val" style="color:var(--primary)"><?php echo number_format($monthSent); ?></div>
            <div class="app-stat-sub">Sent in last 30 days</div>
          </div>
          <div class="app-stat-card">
            <div class="app-stat-label">Pending Queue</div>
            <div class="app-stat-val" style="color:#b45309"><?php echo number_format($queuedCount); ?></div>
            <div class="app-stat-sub" style="color:#b45309">Instant dispatch</div>
          </div>
        </div>

        
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin-bottom:24px">
          
          <div class="app-card" style="margin-bottom:0">
            <div class="app-card-header">
              <div>
                <div class="app-card-title">Outbound Volume Trend</div>
                <div class="app-card-sub">Daily dispatch volume (Last 7 Days)</div>
              </div>
              <span class="app-badge app-badge-teal">Live Analytics</span>
            </div>
            <div style="position:relative;height:240px;width:100%">
              <canvas id="outboundChart"></canvas>
            </div>
          </div>

          
          <div class="app-card" style="margin-bottom:0">
            <div class="app-card-header">
              <div>
                <div class="app-card-title">Delivery Status Ratio</div>
                <div class="app-card-sub">Sent vs Queued vs Failed</div>
              </div>
            </div>
            <div style="position:relative;height:240px;width:100%;display:flex;align-items:center;justify-content:center">
              <canvas id="statusChart"></canvas>
            </div>
          </div>
        </div>

        <div class="app-card">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">Recent Activity Logs</div>
              <div class="app-card-sub">Live events from Android Gateway & REST API</div>
            </div>
          </div>
          <div class="app-table-wrap">
            <table class="app-table">
              <thead><tr><th>Time</th><th>Event Type</th><th>Details</th></tr></thead>
              <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                  <td class="mono"><?php echo date('M d H:i:s', strtotime($log['timestamp']??'0')); ?></td>
                  <td><span class="app-badge app-badge-teal"><?php echo htmlspecialchars($log['type']??''); ?></span></td>
                  <td><?php echo htmlspecialchars($log['detail']??''); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-send">
        <div class="app-card">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">Instant Compose & Send SMS</div>
              <div class="app-card-sub">High-speed instant SMS dispatch via MySQL engine</div>
            </div>
          </div>

          <div class="app-form-group">
            <label class="app-label">Recipient Phone Numbers (comma-separated)</label>
            <input type="text" id="sendNumbers" class="app-input" placeholder="017XXXXXXXX, 018XXXXXXXX">
          </div>

          <div class="app-form-group">
            <label class="app-label">SMS Message</label>
            <textarea id="sendMessage" class="app-textarea" placeholder="Enter message body..."></textarea>
          </div>

          <div class="app-form-grid">
            <div class="app-form-group">
              <label class="app-label">Target Android Device</label>
              <select id="sendDevice" class="app-select">
                <option value="auto">Auto (Best Available Online Phone)</option>
                <?php foreach ($devices as $dev): ?>
                <option value="<?php echo htmlspecialchars($dev['device_id']); ?>">
                  <?php echo htmlspecialchars(($dev['device_name']??$dev['model']).' ('.$dev['device_id'].')'); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="app-form-group">
              <label class="app-label">Dual-SIM Selection</label>
              <select id="sendSimSlot" class="app-select">
                <option value="0">Auto (Default System SIM)</option>
                <option value="1">SIM 1</option>
                <option value="2">SIM 2</option>
              </select>
            </div>
          </div>

          <div style="display:flex;align-items:center;gap:12px;margin-top:10px">
            <button class="app-btn app-btn-primary" onclick="doSend()">Send SMS Now (Instant)</button>
            <span id="sendStatus" style="font-size:13px;font-weight:600"></span>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-sent">
        <div class="app-card">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">Sent Messages History</div>
              <div class="app-card-sub">Outbound SMS history with delivery status (Sent, Delivered, Failed, Queued)</div>
            </div>
            <a href="?export=csv&type=sent" class="app-btn app-btn-secondary">Export CSV</a>
          </div>

          
          <div class="app-form-grid" style="margin-bottom:18px">
            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Filter by Status</label>
              <select id="sentStatusFilter" class="app-select" onchange="filterSentMessages()">
                <option value="all">All Statuses</option>
                <option value="sent">Sent / Delivered</option>
                <option value="failed">Failed</option>
                <option value="queued">Queued / Processing</option>
              </select>
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Filter by SIM</label>
              <select id="sentSimFilter" class="app-select" onchange="filterSentMessages()">
                <option value="all">All SIMs</option>
                <option value="1">SIM 1</option>
                <option value="2">SIM 2</option>
              </select>
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Search Recipient / Content</label>
              <input type="text" id="sentSearchInput" class="app-input" placeholder="Search phone or text..." oninput="filterSentMessages()">
            </div>
          </div>

          <div class="app-table-wrap">
            <table class="app-table" id="sentHistoryTable">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Recipient</th>
                  <th>Message Content</th>
                  <th>Target SIM</th>
                  <th>Status</th>
                  <th>Device ID</th>
                  <th>Time</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($sentMessages as $msg): 
                  $st = strtolower($msg['status']);
                  $badgeCls = ($st === 'sent' || $st === 'delivered') ? 'app-badge-green' : (($st === 'failed') ? 'app-badge-rose' : 'app-badge-amber');
                  $stIcon = ($st === 'sent' || $st === 'delivered') 
                    ? '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>' 
                    : (($st === 'failed') 
                      ? '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>' 
                      : '<svg style="width:12px;height:12px;margin-right:4px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');
                  $stText = ($st === 'sent' || $st === 'delivered') ? 'Sent' : (($st === 'failed') ? 'Failed' : ucfirst($st));
                ?>
                <tr data-status="<?php echo htmlspecialchars($st); ?>" data-sim="<?php echo (int)$msg['sim_slot']; ?>" data-text="<?php echo htmlspecialchars(strtolower($msg['to'] . ' ' . $msg['message'])); ?>">
                  <td class="mono"><?php echo htmlspecialchars($msg['id']); ?></td>
                  <td class="mono" style="font-weight:700;color:#0f172a"><?php echo htmlspecialchars($msg['to']); ?></td>
                  <td style="max-width:300px;word-break:break-word"><?php echo htmlspecialchars($msg['message']); ?></td>
                  <td><span class="app-badge app-badge-teal">SIM <?php echo $msg['sim_slot'] ?: 'Auto'; ?></span></td>
                  <td>
                    <span class="app-badge <?php echo $badgeCls; ?>"><?php echo $stIcon . $stText; ?></span>
                    <?php if (!empty($msg['error'])): ?>
                    <div style="font-size:11px;color:#dc2626;margin-top:2px"><?php echo htmlspecialchars($msg['error']); ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="mono"><?php echo htmlspecialchars(substr($msg['assigned_device']??'Auto',0,12)); ?></td>
                  <td class="mono"><?php echo htmlspecialchars($msg['created_at']); ?></td>
                  <td>
                    <?php if ($st === 'failed'): ?>
                    <button class="app-btn app-btn-secondary" style="padding:4px 10px;font-size:11px" onclick="retrySms('<?php echo htmlspecialchars($msg['id']); ?>')">Retry</button>
                    <?php else: ?>
                    <span style="color:#94a3b8;font-size:11px">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-devices">
        <div class="app-card">
          <?php echo renderDevicesSectionContent($devices, $simsByDevice); ?>
        </div>
      </div>

      
      <!-- API Key Management Section -->
      <div class="section" id="section-apikeys">
        <!-- Top Stats / Overview Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:20px">
          <div class="app-card" style="padding:16px 20px;display:flex;align-items:center;gap:14px">
            <div style="width:44px;height:44px;border-radius:10px;background:rgba(5,125,119,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
            </div>
            <div>
              <div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em">Total API Keys</div>
              <div style="font-size:22px;font-weight:700;color:#0f172a" id="apiKeyCount"><?php echo count($apiKeys); ?></div>
            </div>
          </div>
          <div class="app-card" style="padding:16px 20px;display:flex;align-items:center;gap:14px">
            <div style="width:44px;height:44px;border-radius:10px;background:rgba(16,185,129,0.1);color:#10b981;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
              <div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em">Auth Protocol</div>
              <div style="font-size:15px;font-weight:700;color:#0f172a">HTTP Bearer Token</div>
            </div>
          </div>
          <div class="app-card" style="padding:16px 20px;display:flex;align-items:center;gap:14px">
            <div style="width:44px;height:44px;border-radius:10px;background:rgba(99,102,241,0.1);color:#6366f1;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg style="width:22px;height:22px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
              <div style="font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em">Default Rate Limit</div>
              <div style="font-size:15px;font-weight:700;color:#0f172a">100 reqs / min</div>
            </div>
          </div>
        </div>

        <!-- Create Key Form Card -->
        <div class="app-card" style="margin-bottom:20px">
          <div class="app-card-header" style="margin-bottom:16px">
            <div style="display:flex;align-items:center;gap:12px">
              <div style="width:40px;height:40px;border-radius:10px;background:rgba(5,125,119,0.1);color:var(--primary);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg style="width:20px;height:20px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
              </div>
              <div>
                <div class="app-card-title">Generate API Key</div>
                <div class="app-card-sub">Create secret credentials for external web apps, CRMs, or automation scripts</div>
              </div>
            </div>
          </div>

          <form onsubmit="event.preventDefault(); genApiKey();" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="app-form-group" style="flex:1;min-width:260px;margin-bottom:0">
              <label class="app-label" for="keyName">Token Identifier / System Name</label>
              <input type="text" id="keyName" class="app-input" style="height:42px" placeholder="e.g. Production Laravel App, E-Commerce Integration" required>
            </div>
            <button type="submit" class="app-btn app-btn-primary" id="btnGenKey" style="height:42px;padding:0 20px;display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap">
              <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              Generate Secret Key
            </button>
          </form>

          <!-- Banner displayed when a key is newly generated -->
          <div id="newKeyBanner" style="display:none;margin-top:20px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px">
              <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:#166534;font-size:14px">
                <svg style="width:18px;height:18px;color:#16a34a" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                New Secret API Key Generated!
              </div>
              <span style="font-size:12px;color:#15803d;background:#dcfce7;padding:2px 8px;border-radius:4px;font-weight:600">Save This Now</span>
            </div>
            <p style="font-size:12px;color:#166534;margin-bottom:10px">Please copy your secret key now. For security reasons, you will not be able to view the full token again after refreshing the page.</p>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="text" id="newKeyRawInput" readonly class="app-input mono" style="height:42px;background:#fff;font-weight:700;color:var(--primary);letter-spacing:0.02em;font-size:13px" value="">
              <button type="button" class="app-btn app-btn-primary" onclick="copyNewApiKey(this)" style="height:42px;padding:0 18px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px">
                <svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                Copy Key
              </button>
            </div>
          </div>
        </div>

        <!-- Active Credentials List Card -->
        <div class="app-card">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">Active API Credentials</div>
              <div class="app-card-sub">Manage active key tokens and inspect request history</div>
            </div>
          </div>

          <div class="app-table-wrap">
            <table class="app-table" id="apiKeysTable">
              <thead>
                <tr>
                  <th>Token Name &amp; ID</th>
                  <th>Key Identifier</th>
                  <th>Permissions</th>
                  <th>Total Requests</th>
                  <th>Last Activity</th>
                  <th>Created Date</th>
                  <th style="text-align:right">Action</th>
                </tr>
              </thead>
              <tbody id="apiKeysTbody">
                <?php if (empty($apiKeys)): ?>
                  <tr id="noKeysRow">
                    <td colspan="7" style="text-align:center;padding:32px;color:#94a3b8">
                      <svg style="width:40px;height:40px;margin:0 auto 10px;color:#cbd5e1;display:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 0121 9z"/></svg>
                      No API keys generated yet. Use the form above to generate your first key.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($apiKeys as $k): ?>
                    <tr id="key-row-<?php echo htmlspecialchars($k['key_id']); ?>">
                      <td>
                        <div style="font-weight:600;color:#0f172a"><?php echo htmlspecialchars($k['name']); ?></div>
                        <div style="font-size:11px;color:#94a3b8" class="mono"><?php echo htmlspecialchars($k['key_id']); ?></div>
                      </td>
                      <td>
                        <div style="display:inline-flex;align-items:center;gap:6px">
                          <code class="mono" style="font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#334155;border:1px solid #e2e8f0"><?php echo htmlspecialchars($k['preview'] ?: $k['key_id']); ?></code>
                          <button type="button" class="app-btn app-btn-outline" style="padding:2px 6px;font-size:11px" onclick="copyText('<?php echo htmlspecialchars($k['preview'] ?: $k['key_id']); ?>', this)" title="Copy API Key">
                            <svg style="width:12px;height:12px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                          </button>
                        </div>
                      </td>
                      <td>
                        <span class="app-badge app-badge-green" style="font-size:11px">Send</span>
                        <span class="app-badge app-badge-blue" style="font-size:11px">Status</span>
                      </td>
                      <td>
                        <strong style="color:#0f172a"><?php echo number_format($k['usage_count'] ?? 0); ?></strong>
                        <span style="font-size:11px;color:#64748b"> calls</span>
                      </td>
                      <td style="font-size:12px;color:#64748b">
                        <?php echo !empty($k['last_used']) ? htmlspecialchars($k['last_used']) : '<span style="color:#94a3b8;font-style:italic">Never used</span>'; ?>
                      </td>
                      <td style="font-size:12px;color:#64748b">
                        <?php echo htmlspecialchars(date('M d, Y H:i', strtotime($k['created_at']))); ?>
                      </td>
                      <td style="text-align:right">
                        <button type="button" class="app-btn app-btn-danger" style="padding:4px 10px;font-size:12px;display:inline-flex;align-items:center;gap:4px" onclick="revokeApiKey('<?php echo htmlspecialchars($k['key_id']); ?>', '<?php echo htmlspecialchars(addslashes($k['name'])); ?>')">
                          <svg style="width:14px;height:14px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                          Revoke
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-docs">
        <div class="app-card">
          <div class="app-card-header" style="flex-wrap:wrap;gap:12px">
            <div>
              <div class="app-card-title">REST API Overview &amp; Authentication</div>
              <div class="app-card-sub" style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap">
                <span>Base API Endpoint:</span>
                <span class="mono" style="color:var(--primary);font-weight:700;background:var(--primary-light);padding:4px 10px;border-radius:6px;border:1px solid rgba(5,125,119,0.2)"><?php echo htmlspecialchars($appUrl); ?>/api/v1</span>
              </div>
            </div>
          </div>
          
          <div style="display:flex;flex-direction:column;gap:24px">
            
            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:12px;padding:20px">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                <span class="app-badge app-badge-green" style="font-size:12px">POST</span>
                <strong class="mono" style="font-size:14px;color:#0f172a">/api/v1/send</strong>
                <span style="font-size:12px;color:#64748b;margin-left:auto">Header: <code class="mono" style="color:var(--primary)">Authorization: Bearer &lt;API_KEY&gt;</code></span>
              </div>
              <p style="font-size:13px;color:#334155;margin-bottom:14px">Dispatches single or bulk outbound SMS messages through your connected dual-SIM Android gateways.</p>
              
              <div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase">Request Body (JSON)</div>
              <pre class="mono" style="background:#0b1320;color:#f8fafc;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto;margin-bottom:14px">{
  "to": "017XXXXXXXX, 018XXXXXXXX", // Single or comma-separated numbers
  "message": "Your verification code is 849201", // Required SMS text content
  "sim_slot": 1, // Optional: 0 = Auto/Default, 1 = SIM 1, 2 = SIM 2
  "device_id": "auto" // Optional: Specific Android device ID or "auto"
}</pre>

              <div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase">Response (200 OK)</div>
              <pre class="mono" style="background:#0b1320;color:#a7f3d0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto">{
  "ok": true,
  "msg_id": "msg_66da812f948201",
  "count": 1,
  "device": "dev_pixel8pro",
  "sim_slot": 1
}</pre>
            </div>

            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:12px;padding:20px">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                <span class="app-badge app-badge-teal" style="font-size:12px">GET / POST</span>
                <strong class="mono" style="font-size:14px;color:#0f172a">/api/v1/status</strong>
                <span style="font-size:12px;color:#64748b;margin-left:auto">Header: <code class="mono" style="color:var(--primary)">Authorization: Bearer &lt;API_KEY&gt;</code></span>
              </div>
              <p style="font-size:13px;color:#334155;margin-bottom:14px">Queries the real-time delivery status of any sent SMS message by ID.</p>

              <div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase">Endpoint Request</div>
              <code class="mono" style="background:#e2e8f0;padding:6px 12px;border-radius:6px;font-size:12px;display:inline-block;margin-bottom:14px;color:#0f172a">GET <?php echo htmlspecialchars($appUrl); ?>/api/v1/status?msg_id=msg_66da812f948201</code>

              <div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase">Response (200 OK)</div>
              <pre class="mono" style="background:#0b1320;color:#a7f3d0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto">{
  "ok": true,
  "message_id": "msg_66da812f948201",
  "status": "delivered", // queued | processing | sent | delivered | failed
  "error": null,
  "sent_at": "2026-09-05 16:45:10"
}</pre>
            </div>

            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:12px;padding:20px">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
                <span class="app-badge app-badge-teal" style="font-size:12px">GET</span>
                <strong class="mono" style="font-size:14px;color:#0f172a">/api/v1/receive</strong>
                <span style="font-size:12px;color:#64748b;margin-left:auto">Header: <code class="mono" style="color:var(--primary)">Authorization: Bearer &lt;API_KEY&gt;</code></span>
              </div>
              <p style="font-size:13px;color:#334155;margin-bottom:14px">Fetches incoming customer SMS messages received by your Android gateway phones.</p>

              <div style="font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;text-transform:uppercase">Response (200 OK)</div>
              <pre class="mono" style="background:#0b1320;color:#a7f3d0;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto">{
  "ok": true,
  "messages": [
    {
      "id": 89,
      "sender": "+8801712948201",
      "message": "YES confirmed",
      "sim_slot": 1,
      "received_at": "2026-09-05 17:30:15"
    }
  ]
}</pre>
            </div>

            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:12px;padding:20px">
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:12px">cURL Integration Example</div>
              <pre class="mono" style="background:#0b1320;color:#93c5fd;padding:14px;border-radius:8px;font-size:12px;overflow-x:auto">curl -X POST <?php echo htmlspecialchars($appUrl); ?>/api/v1/send \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "017XXXXXXXX",
    "message": "Hello World from SMSLink API",
    "sim_slot": 1
  }'</pre>
            </div>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-team">
        <div class="app-card" style="margin-bottom:20px;background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%)">
          <div class="app-card-header" style="flex-wrap:wrap;gap:16px">
            <div>
              <div class="app-card-title" style="font-size:18px;display:flex;align-items:center;gap:10px">
                <svg style="width:22px;height:22px;color:var(--primary)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Team Management
              </div>
              <div class="app-card-sub" style="margin-top:4px">Create and manage team member accounts for your organization.</div>
            </div>
            <?php if ($currentUserRole === 'admin'): ?>
            <button class="app-btn app-btn-primary" onclick="openAddMemberModal()">
              <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
              Add team member
            </button>
            <?php endif; ?>
          </div>

          <div style="background:var(--primary-light);border:1px solid rgba(5,125,119,0.25);border-radius:12px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;margin-top:12px">
            <svg style="width:20px;height:20px;color:var(--primary);flex-shrink:0;margin-top:2px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div style="font-size:13px;color:#334155;line-height:1.5">
              <strong>Team Members:</strong> Manage subaccounts and their access permissions. Team members have full gateway functionality except account management, user creation, and billing/settings access. You can also manually set passwords for any member at any time.
            </div>
          </div>
        </div>

        <div class="app-card">
          <div class="app-card-header" style="flex-wrap:wrap;gap:12px">
            <div>
              <div class="app-card-title">Team Members Directory</div>
              <div class="app-card-sub">Active and pending subaccount operators</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
              <input type="text" id="teamFilterInput" class="app-input" placeholder="Filter members..." oninput="filterTeamMembers(this.value)" style="width:220px;height:36px;font-size:12px;padding:6px 12px">
            </div>
          </div>

          <div id="teamNotice" style="display:none;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:13px"></div>

          <div class="app-table-wrap">
            <table class="app-table" id="teamTable">
              <thead>
                <tr>
                  <th>Member</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Joined Date</th>
                  <th style="text-align:right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($teamMembers as $tm): 
                  $isSelf = ((int)$tm['id'] === (int)($_SESSION['tf_user_id'] ?? 0));
                  $isPending = (($tm['status'] ?? 'active') === 'pending');
                ?>
                <tr data-username="<?php echo strtolower(htmlspecialchars($tm['username'])); ?>" data-email="<?php echo strtolower(htmlspecialchars($tm['email'] ?? '')); ?>" id="member-row-<?php echo $tm['id']; ?>">
                  <td>
                    <div style="display:flex;align-items:center;gap:12px">
                      <div class="app-user-avatar" style="width:34px;height:34px;border-radius:50%;overflow:hidden;background:#334155;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">
                        <?php if (!empty($tm['avatar_path'])): ?>
                        <img src="<?php echo htmlspecialchars($tm['avatar_path']); ?>" style="width:100%;height:100%;object-fit:cover">
                        <?php else: ?>
                        <?php echo strtoupper(substr($tm['username'], 0, 1)); ?>
                        <?php endif; ?>
                      </div>
                      <div>
                        <div style="font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px">
                          <?php echo htmlspecialchars($tm['username']); ?>
                          <?php if ($isSelf): ?>
                          <span style="font-size:10px;background:#e2e8f0;color:#475569;padding:1px 6px;border-radius:4px;font-weight:600">You</span>
                          <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#64748b">ID: #<?php echo $tm['id']; ?></div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="mono" style="font-size:13px;color:#334155"><?php echo htmlspecialchars($tm['email'] ?: 'No email set'); ?></span>
                  </td>
                  <td>
                    <?php if (($tm['role'] ?? 'admin') === 'admin'): ?>
                    <span class="app-badge app-badge-green">Admin</span>
                    <?php else: ?>
                    <span class="app-badge app-badge-teal">Team Member</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($isPending): ?>
                    <span class="app-badge app-badge-amber" id="status-badge-<?php echo $tm['id']; ?>">Pending</span>
                    <?php else: ?>
                    <span class="app-badge app-badge-green" id="status-badge-<?php echo $tm['id']; ?>">Active</span>
                    <?php endif; ?>
                  </td>
                  <td class="mono" style="font-size:12px;color:#64748b">
                    <?php echo date('M d, Y', strtotime($tm['created_at'])); ?>
                  </td>
                  <td style="text-align:right">
                    <div style="display:inline-flex;align-items:center;gap:6px">
                      <?php if ($currentUserRole === 'admin'): ?>
                      <button type="button" class="app-btn app-btn-secondary" style="padding:6px 12px;font-size:11px" onclick="openSetPasswordModal(<?php echo $tm['id']; ?>, '<?php echo htmlspecialchars($tm['username'], ENT_QUOTES); ?>')">
                        Set password
                      </button>
                      <?php if (!$isSelf && ($tm['role'] ?? '') !== 'admin'): ?>
                      <button type="button" class="app-btn app-btn-secondary" id="btn-status-<?php echo $tm['id']; ?>" style="padding:6px 10px;font-size:11px" onclick="toggleMemberStatus(<?php echo $tm['id']; ?>)">
                        <?php echo $isPending ? 'Activate' : 'Suspend'; ?>
                      </button>
                      <button type="button" class="app-btn app-btn-danger" style="padding:6px 10px;font-size:11px" onclick="deleteTeamMember(<?php echo $tm['id']; ?>, '<?php echo htmlspecialchars($tm['username'], ENT_QUOTES); ?>')">
                        Delete
                      </button>
                      <?php endif; ?>
                      <?php else: ?>
                      <span style="font-size:11px;color:#94a3b8">-</span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-settings">
        <div class="app-card" style="max-width:680px;margin:0 auto">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">System Settings</div>
              <div class="app-card-sub">Configure server base URL and UI primary colors</div>
            </div>
          </div>
          
          <div id="settingsNotice" style="display:none;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:13px"></div>

          <form id="settingsForm" style="display:flex;flex-direction:column;gap:18px">
            <div class="app-form-group" style="margin-bottom:0">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <label class="app-label" style="margin-bottom:0">System Base URL</label>
                <button type="button" class="app-btn app-btn-secondary" style="padding:4px 10px;font-size:11px" onclick="autoDetectUrl()">Auto-Detect Current URL</button>
              </div>
              <input type="url" id="appUrlInput" name="app_url" class="app-input" value="<?php echo htmlspecialchars($baseUrl); ?>" placeholder="http://localhost/sms" required>
              <div style="font-size:11px;color:#64748b;margin-top:4px">Base URL used for QR Code Android app pairing and API endpoint documentation.</div>
            </div>

            <div class="app-form-grid">
              <div class="app-form-group" style="margin-bottom:0">
                <label class="app-label">Primary Theme Color</label>
                <div style="display:flex;align-items:center;gap:10px">
                  <input type="color" id="themeColorPicker" value="<?php echo htmlspecialchars($themeColor); ?>" style="width:40px;height:40px;border:none;border-radius:8px;cursor:pointer;background:none" onchange="document.getElementById('themeColorInput').value=this.value">
                  <input type="text" id="themeColorInput" name="theme_color" class="app-input" value="<?php echo htmlspecialchars($themeColor); ?>" required oninput="document.getElementById('themeColorPicker').value=this.value">
                </div>
              </div>

              <div class="app-form-group" style="margin-bottom:0">
                <label class="app-label">Theme Hover Color</label>
                <div style="display:flex;align-items:center;gap:10px">
                  <input type="color" id="themeHoverPicker" value="<?php echo htmlspecialchars($themeHover); ?>" style="width:40px;height:40px;border:none;border-radius:8px;cursor:pointer;background:none" onchange="document.getElementById('themeHoverInput').value=this.value">
                  <input type="text" id="themeHoverInput" name="theme_color_hover" class="app-input" value="<?php echo htmlspecialchars($themeHover); ?>" required oninput="document.getElementById('themeHoverPicker').value=this.value">
                </div>
              </div>
            </div>

            <div style="display:flex;gap:12px;margin-top:6px">
              <button type="submit" class="app-btn app-btn-primary">Save Settings</button>
            </div>
          </form>
        </div>
      </div>

      
      <div class="section" id="section-updates">
        <div class="app-card" style="max-width:760px;margin:0 auto">
          <div class="app-card-header" style="flex-wrap:wrap;gap:12px">
            <div>
              <div class="app-card-title" style="display:flex;align-items:center;gap:8px">
                <svg style="width:20px;height:20px;color:var(--primary)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                System &amp; Database Updates
              </div>
              <div class="app-card-sub">Check GitHub repository releases and apply instant code &amp; database updates</div>
            </div>
            <button class="app-btn app-btn-primary" onclick="checkForUpdates()" id="btnCheckUpdates">
              <svg style="width:15px;height:15px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              Check for Updates
            </button>
          </div>

          <div id="updateNotice" style="display:none;padding:14px 18px;border-radius:12px;margin-bottom:20px;font-size:13px;line-height:1.5"></div>

          <div class="app-widget-grid" style="grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));margin-bottom:20px">
            <div class="app-stat-card" style="background:#f8fafc">
              <div class="app-stat-label">Current Version</div>
              <div class="app-stat-val" style="font-size:24px;color:var(--primary)" id="currVerDisplay"><?php echo defined('APP_VERSION') ? APP_VERSION : 'v1.0.0'; ?></div>
              <div class="app-stat-sub" style="color:#64748b">Installed Build</div>
            </div>
            <div class="app-stat-card" style="background:#f8fafc">
              <div class="app-stat-label">Latest Online Version</div>
              <div class="app-stat-val" style="font-size:24px;color:#0f172a" id="latestVerDisplay"><?php echo defined('APP_VERSION') ? APP_VERSION : 'v1.0.0'; ?></div>
              <div class="app-stat-sub" id="latestVerSub">Tap check to verify</div>
            </div>
            <div class="app-stat-card" style="background:#f8fafc">
              <div class="app-stat-label">Database Schema</div>
              <div class="app-stat-val" style="font-size:20px;color:#10b981;font-weight:700">Healthy</div>
              <div class="app-stat-sub" style="color:#10b981">Auto-migrated</div>
            </div>
          </div>

          
          <div id="updateDetailsBox" style="display:none;background:#f8fafc;border:1px solid var(--app-border-color);border-radius:14px;padding:20px;margin-bottom:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
              <div>
                <h4 style="font-size:16px;font-weight:700;color:#0f172a" id="updateReleaseTitle">Release Title</h4>
                <div style="font-size:12px;color:#64748b" id="updateReleaseDate">Published date</div>
              </div>
              <a id="updateGithubLink" href="https://github.com/beingniloy/smslink/releases" target="_blank" rel="noopener noreferrer" class="app-btn app-btn-secondary" style="font-size:12px;padding:6px 12px">View on GitHub ↗</a>
            </div>

            <div style="margin-bottom:14px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#475569;margin-bottom:6px;letter-spacing:0.04em">Release Notes &amp; Changelog</div>
              <div id="updateChangelogText" style="background:#FFF;border:1px solid #e2e8f0;padding:12px;border-radius:8px;font-size:13px;color:#334155;max-height:160px;overflow-y:auto;white-space:pre-wrap;font-family:sans-serif"></div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:10px;border-top:1px solid #e2e8f0">
              <div style="font-size:12px;color:#64748b">Applies database migrations &amp; updates version metadata automatically.</div>
              <button type="button" class="app-btn app-btn-primary" id="btnApplyUpdate" onclick="applySystemUpdate()">
                <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Update System &amp; Database Now
              </button>
            </div>
          </div>

          <div style="padding:14px 16px;background:#f1f5f9;border-radius:10px;font-size:12px;color:#475569">
            Repository: <a href="https://github.com/beingniloy/smslink" target="_blank" rel="noopener" class="mono" style="color:var(--primary);text-decoration:none;font-weight:600">beingniloy/smslink</a>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-about">
        <div class="app-card" style="max-width:760px;margin:0 auto">
          <div style="text-align:center;padding:24px 10px 30px;border-bottom:1px solid var(--app-border-color)">
            <div style="display:flex;align-items:center;justify-content:center;margin:0 0 8px">
              <img src="https://cdn.niloy.io/projects/smslink/logo.png" alt="SMSLink" style="height:48px;max-width:240px;object-fit:contain;display:block" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
              <h2 class="brand-text-fallback" style="display:none;align-items:baseline;justify-content:center;font-size:26px;font-weight:700;color:#0f172a;letter-spacing:-0.03em;margin:0">SMS<span style="color:var(--primary);font-weight:400">Link</span></h2>
            </div>
            <p style="font-size:14px;color:#64748b;margin-top:4px;max-width:480px;margin-left:auto;margin-right:auto">Production-Ready Android Dual-SIM Outbound SMS Gateway &amp; Enterprise Web Management Dashboard</p>
            <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:14px;flex-wrap:wrap">
              <span class="app-badge app-badge-teal"><?php echo defined('APP_VERSION') ? APP_VERSION : 'v1.0.0'; ?></span>
              <span class="app-badge app-badge-green">MIT Open Source</span>
            </div>
          </div>

          
          <div style="padding:28px 0;display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:20px">
            
            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:14px;padding:20px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.04em;margin-bottom:12px">Developer &amp; Author</div>
              <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px">
                <img src="https://cdn.niloy.io/profile/niloy.png" alt="Niloy" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid var(--primary);flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.08)">
                <div>
                  <div style="font-size:16px;font-weight:800;color:#0f172a">Niloy</div>
                  <a href="mailto:hello@niloy.io" style="font-size:12px;color:var(--primary);text-decoration:none;font-weight:500">hello@niloy.io</a>
                </div>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="https://niloy.io/" target="_blank" rel="noopener noreferrer" class="app-btn app-btn-secondary" style="flex:1;padding:8px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px">
                  <svg style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/><path d="M3.6 9h16.8M3.6 15h16.8M12 3a15.3 15.3 0 014 9 15.3 15.3 0 01-4 9 15.3 15.3 0 01-4-9 15.3 15.3 0 014-9z"/></svg>
                  Portfolio
                </a>
                <a href="https://github.com/beingniloy/" target="_blank" rel="noopener noreferrer" class="app-btn app-btn-secondary" style="flex:1;padding:8px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px">
                  <svg style="width:14px;height:14px;flex-shrink:0;fill:currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
                  GitHub
                </a>
              </div>
            </div>

            
            <div style="background:#f8fafc;border:1px solid var(--app-border-color);border-radius:14px;padding:20px">
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.04em;margin-bottom:12px">Open Source Project</div>
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px">SMSLink Repository</div>
              <p style="font-size:12px;color:#64748b;margin-bottom:14px">Available on GitHub under the permissive MIT License for personal and commercial usage.</p>
              <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="https://github.com/beingniloy/smslink" target="_blank" rel="noopener noreferrer" class="app-btn app-btn-secondary" style="flex:1;padding:8px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px">
                  <svg style="width:14px;height:14px;flex-shrink:0;fill:#f59e0b" viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                  Star on GitHub
                </a>
                <a href="https://github.com/beingniloy/smslink/issues" target="_blank" rel="noopener noreferrer" class="app-btn app-btn-secondary" style="flex:1;padding:8px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;gap:6px">
                  <svg style="width:14px;height:14px;flex-shrink:0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                  Issues
                </a>
              </div>
            </div>
          </div>

          
          <div style="background:linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);border:1px solid #fbcfe8;border-radius:16px;padding:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
            <div style="max-width:440px">
              <div style="font-size:16px;font-weight:800;color:#9d174d;display:flex;align-items:center;gap:8px">
                <svg style="width:18px;height:18px;fill:#db2777;flex-shrink:0" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                Sponsor Developer &amp; SMSLink
              </div>
              <p style="font-size:13px;color:#be185d;margin-top:4px">Support ongoing development, maintenance, and open source telephony tooling.</p>
            </div>
            <a href="https://niloy.io/sponsor" target="_blank" rel="noopener noreferrer" class="app-btn" style="background:#db2777;color:#FFF;box-shadow:0 4px 12px rgba(219,39,119,0.3);font-size:13px;padding:10px 20px;text-decoration:none;border-radius:10px;font-weight:700">
              Sponsor ↗
            </a>
          </div>
        </div>
      </div>

      
      <div class="section" id="section-profile">
        <div class="app-card" style="max-width:640px;margin:0 auto">
          <div class="app-card-header">
            <div>
              <div class="app-card-title">Account Profile</div>
              <div class="app-card-sub">Manage account credentials and security settings</div>
            </div>
          </div>
          
          <div id="profileNotice" style="display:none;padding:12px 16px;border-radius:10px;margin-bottom:20px;font-size:13px"></div>

          <form id="profileForm" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:18px">
            <div style="display:flex;align-items:center;gap:20px;padding:16px;background:#f8fafc;border:1px solid var(--app-border-color);border-radius:12px">
              <div style="position:relative;width:72px;height:72px;flex-shrink:0">
                <?php if ($adminAvatar): ?>
                <img id="avatarPreview" src="<?php echo htmlspecialchars($adminAvatar); ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">
                <?php else: ?>
                <div id="avatarPreviewFallback" class="app-user-avatar" style="width:72px;height:72px;border-radius:50%;font-size:24px;background:var(--primary);color:#FFF;display:flex;align-items:center;justify-content:center;font-weight:700"><?php echo strtoupper(substr($adminUser, 0, 1)); ?></div>
                <img id="avatarPreview" src="" style="display:none;width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">
                <?php endif; ?>
              </div>
              <div style="flex:1">
                <label class="app-label" style="margin-bottom:4px">Profile Picture Avatar</label>
                <input type="file" name="avatar_file" id="avatarFileInput" accept="image/*" class="app-input" style="padding:6px;font-size:12px" onchange="previewAvatar(this)">
                <div style="font-size:11px;color:#64748b;margin-top:4px">PNG, JPG, WEBP or GIF (Max 5MB)</div>
              </div>
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Username</label>
              <input type="text" name="username" class="app-input" value="<?php echo htmlspecialchars($adminUser); ?>" required>
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Current Password (Required only if changing password)</label>
              <input type="password" name="current_password" class="app-input" placeholder="••••••••">
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">New Password (Leave blank to keep unchanged)</label>
              <input type="password" name="new_password" class="app-input" placeholder="••••••••">
            </div>

            <div class="app-form-group" style="margin-bottom:0">
              <label class="app-label">Confirm New Password</label>
              <input type="password" name="confirm_password" class="app-input" placeholder="••••••••">
            </div>

            <div style="display:flex;gap:12px;margin-top:6px">
              <button type="submit" class="app-btn app-btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>

    </div>
    </div>
  </main>
</div>


<div class="app-modal-bg" id="qrModal" style="backdrop-filter:blur(8px);background:rgba(15,23,42,0.65)">
  <div class="app-modal-card" style="max-width:440px;border-radius:20px;padding:28px;background:#ffffff;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);border:1px solid rgba(255,255,255,0.2);position:relative">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
      <div style="text-align:left">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
          <div style="width:28px;height:28px;border-radius:8px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center">
            <svg style="width:16px;height:16px" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
          </div>
          <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin:0">Pair Gateway Device</h3>
        </div>
        <p style="font-size:13px;color:#64748b;margin:0">Open SMS Android App &amp; scan the QR Code below</p>
      </div>
      <button type="button" onclick="closeQrModal()" style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;border:none;color:#64748b;font-size:18px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.2s" onmouseover="this.style.background='#e2e8f0';this.style.color='#0f172a'" onmouseout="this.style.background='#f1f5f9';this.style.color='#64748b'">&times;</button>
    </div>
    
    <div id="qrCodeContainer" style="margin:0 auto 16px;min-height:240px;display:flex;align-items:center;justify-content:center">
      <div style="font-size:13px;color:#64748b;text-align:center">Generating QR Code...</div>
    </div>

    <div style="font-size:12px;font-family:'Fira Code',monospace;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;padding:8px 14px;border-radius:10px;margin-bottom:20px;text-align:center" id="pairingCodeLabel"></div>
    <button class="app-btn app-btn-secondary" onclick="closeQrModal()" style="width:100%;border-radius:10px;padding:10px">Close Window</button>
  </div>
</div>


<div class="app-modal-bg" id="addMemberModal">
  <div class="app-modal-card" style="text-align:left;max-width:480px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <h3 style="font-size:18px;font-weight:800;color:#0f172a">Add New Team Member</h3>
      <button type="button" onclick="closeAddMemberModal()" style="background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer">&times;</button>
    </div>
    <div id="addMemberNotice" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px"></div>
    <form id="addMemberForm" style="display:flex;flex-direction:column;gap:14px">
      <div class="app-form-group" style="margin-bottom:0">
        <label class="app-label">Username</label>
        <input type="text" name="username" class="app-input" placeholder="e.g. john_doe" required autofocus>
      </div>
      <div class="app-form-group" style="margin-bottom:0">
        <label class="app-label">Email Address</label>
        <input type="email" name="email" class="app-input" placeholder="john@example.com" required>
      </div>
      <div class="app-form-group" style="margin-bottom:0">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
          <label class="app-label" style="margin-bottom:0">Password</label>
          <button type="button" class="app-btn app-btn-secondary" style="padding:2px 8px;font-size:10px" onclick="generateRandomMemberPassword()">Generate Random</button>
        </div>
        <input type="text" id="memberPasswordInput" name="password" class="app-input" placeholder="Leave blank to auto-generate">
        <div style="font-size:11px;color:#64748b;margin-top:4px">If left blank, a secure random password will be created.</div>
      </div>
      <div class="app-form-group" style="margin-bottom:0">
        <label class="app-label">Initial Status</label>
        <select name="status" class="app-select">
          <option value="active">Active (Instant Login)</option>
          <option value="pending">Pending (Invitation / Confirmation)</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="app-btn app-btn-primary" style="flex:1">Create Member</button>
        <button type="button" class="app-btn app-btn-secondary" onclick="closeAddMemberModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>


<div class="app-modal-bg" id="setPasswordModal">
  <div class="app-modal-card" style="text-align:left;max-width:440px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <h3 style="font-size:18px;font-weight:800;color:#0f172a">Set Member Password</h3>
      <button type="button" onclick="closeSetPasswordModal()" style="background:none;border:none;font-size:18px;color:#94a3b8;cursor:pointer">&times;</button>
    </div>
    <div style="font-size:13px;color:#64748b;margin-bottom:16px">
      Changing password for member: <strong id="setPasswordTargetName" style="color:#0f172a"></strong>
    </div>
    <div id="setPasswordNotice" style="display:none;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px"></div>
    <form id="setPasswordForm" style="display:flex;flex-direction:column;gap:14px">
      <input type="hidden" name="user_id" id="setPasswordUserId">
      <div class="app-form-group" style="margin-bottom:0">
        <label class="app-label">New Password</label>
        <input type="text" name="new_password" id="targetNewPasswordInput" class="app-input" placeholder="Enter new password (min 4 characters)" required minlength="4">
      </div>
      <div style="display:flex;gap:10px;margin-top:6px">
        <button type="submit" class="app-btn app-btn-primary" style="flex:1">Update Password</button>
        <button type="button" class="app-btn app-btn-secondary" onclick="closeSetPasswordModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleMobileSidebar(){
  const sb = document.getElementById('appSidebar');
  const ov = document.getElementById('sidebarOverlay');
  if(sb) sb.classList.toggle('mobile-open');
  if(ov) ov.classList.toggle('mobile-open');
}

function previewAvatar(input){
  if(input.files && input.files[0]){
    const reader = new FileReader();
    reader.onload = function(e){
      const img = document.getElementById('avatarPreview');
      const fallback = document.getElementById('avatarPreviewFallback');
      if(img) {
        img.src = e.target.result;
        img.style.display = 'block';
      }
      if(fallback) fallback.style.display = 'none';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function handleGlobalSearch(query){
  const q = query.toLowerCase().trim();
  const resContainer = document.getElementById('globalSearchResults');

  
  const activeSec = document.querySelector('.section.active');
  if(activeSec){
    const rows = activeSec.querySelectorAll('tbody tr, div[style*="border"]');
    rows.forEach(r => {
      const text = (r.dataset.text || r.textContent).toLowerCase();
      r.style.display = (!q || text.includes(q)) ? '' : 'none';
    });
  }

  
  if (!q) {
    if(resContainer) { resContainer.style.display = 'none'; resContainer.innerHTML = ''; }
    return;
  }

  let matches = [];

  
  const pagesList = [
    { sec: 'status', title: 'Dashboard Page', sub: 'Overview stats, total sent volume & activity logs' },
    { sec: 'send', title: 'Send SMS Page', sub: 'Compose and dispatch outbound messages' },
    { sec: 'sent', title: 'Sent Messages History', sub: 'View status of all outbound SMS' },
    { sec: 'devices', title: 'Devices & QR Pair', sub: 'Android gateway phones & dual SIM slots' },
    { sec: 'apikeys', title: 'API Keys Page', sub: 'Generate and manage API authorization tokens' },
    { sec: 'docs', title: 'API Documentation', sub: 'REST API endpoints, payloads and cURL examples' },
    { sec: 'team', title: 'Team Management', sub: 'Manage team member subaccounts, permissions and passwords' },
    { sec: 'settings', title: 'System Settings', sub: 'Website name, base URL auto-detection, primary theme colors' },
    { sec: 'updates', title: 'System Updates Page', sub: 'Check latest GitHub releases and 1-click database update' },
    { sec: 'about', title: 'About SMSLink', sub: 'Author Niloy, portfolio, repo and sponsorship info' },
    { sec: 'profile', title: 'Account Profile Page', sub: 'Manage admin profile picture avatar & credentials' }
  ];

  pagesList.forEach(p => {
    if (p.title.toLowerCase().includes(q) || p.sub.toLowerCase().includes(q) || p.sec.toLowerCase().includes(q)) {
      matches.push({
        type: 'Page Navigation',
        title: p.title,
        sub: p.sub,
        sec: p.sec,
        el: document.getElementById('section-' + p.sec)
      });
    }
  });

  
  document.querySelectorAll('#sentHistoryTable tbody tr').forEach(tr => {
    const text = tr.textContent;
    if (text.toLowerCase().includes(q)) {
      const recipient = tr.querySelector('td:nth-child(2)')?.textContent || '';
      const msg = tr.querySelector('td:nth-child(3)')?.textContent || '';
      const status = tr.querySelector('td:nth-child(5)')?.textContent || '';
      matches.push({
        type: 'Sent SMS',
        title: recipient + ' - ' + status.trim(),
        sub: msg.substring(0, 60),
        sec: 'sent',
        el: tr
      });
    }
  });

  
  document.querySelectorAll('#section-devices div[style*="border"]').forEach(card => {
    const text = card.textContent;
    if (text.toLowerCase().includes(q)) {
      const name = card.querySelector('strong')?.textContent || 'Device';
      matches.push({
        type: 'Device',
        title: name,
        sub: text.substring(0, 60),
        sec: 'devices',
        el: card
      });
    }
  });

  
  document.querySelectorAll('.section').forEach(sec => {
    const secId = sec.id.replace('section-', '');
    sec.querySelectorAll('.app-card-title, code, pre, label, h3, p').forEach(el => {
      const txt = (el.textContent || '').trim();
      if (txt.length > 3 && txt.toLowerCase().includes(q)) {
        const titleText = txt.substring(0, 60);
        if(!matches.some(m => m.title === titleText)){
          matches.push({
            type: 'Page Content',
            title: titleText,
            sub: (el.parentElement?.textContent || txt).trim().substring(0, 70),
            sec: secId,
            el: el
          });
        }
      }
    });
  });

  if (!resContainer) return;

  if (matches.length === 0) {
    resContainer.innerHTML = '<div style="padding:12px;text-align:center;font-size:12px;color:#94a3b8">No matching records or content found</div>';
    resContainer.style.display = 'block';
    return;
  }

  let html = '';
  matches.slice(0, 8).forEach((m, idx) => {
    html += `<div onclick="selectSearchResult('${m.sec}', ${idx})" style="padding:10px 12px;border-radius:8px;cursor:pointer;transition:background 0.15s;border-bottom:1px solid #f1f5f9" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px">
        <span class="app-badge app-badge-teal" style="font-size:10px">${m.type}</span>
        <span style="font-size:10px;color:#94a3b8">Click to view</span>
      </div>
      <div style="font-size:13px;font-weight:600;color:#0f172a">${escapeHtml(m.title)}</div>
      <div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(m.sub)}</div>
    </div>`;
  });

  window._searchMatches = matches;
  resContainer.innerHTML = html;
  resContainer.style.display = 'block';
}

function selectSearchResult(secId, matchIdx){
  showSection(secId);
  const match = window._searchMatches ? window._searchMatches[matchIdx] : null;
  if(match && match.el){
    match.el.scrollIntoView({behavior: 'smooth', block: 'center'});
    match.el.style.background = '#f0fdf4';
    setTimeout(() => { match.el.style.background = ''; }, 2000);
  }
  const resContainer = document.getElementById('globalSearchResults');
  if(resContainer) resContainer.style.display = 'none';
}

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('click', function(e){
  const searchWrap = document.querySelector('.app-topbar-search-wrap');
  const resContainer = document.getElementById('globalSearchResults');
  if(searchWrap && !searchWrap.contains(e.target) && resContainer){
    resContainer.style.display = 'none';
  }
});

const validSections = ['status', 'send', 'sent', 'devices', 'apikeys', 'docs', 'team', 'settings', 'updates', 'about', 'profile'];

function getDashboardBasePath() {
  let path = window.location.pathname;
  validSections.forEach(sec => {
    const reg = new RegExp('/' + sec + '/?$', 'i');
    path = path.replace(reg, '/');
  });
  path = path.replace(/\/index\.php\/?$/i, '/');
  if (!path.endsWith('/')) path += '/';
  return path;
}

function showSection(id, el, updateHistory = true){
  const userRole = '<?php echo $currentUserRole; ?>';
  if(userRole !== 'admin' && (id === 'team' || id === 'settings')){
    id = 'status';
  }
  if(!validSections.includes(id)) id = 'status';

  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.app-nav-item').forEach(n => n.classList.remove('active'));
  const target = document.getElementById('section-' + id);
  if(target) target.classList.add('active');
  const navItem = el || document.querySelector('.app-nav-item[data-sec="' + id + '"]');
  if(navItem) navItem.classList.add('active');
  const pageTitles = {status:'Dashboard', send:'Send SMS', sent:'Sent Messages History', devices:'Devices & QR Pair', apikeys:'API Keys', docs:'API Documentation', team:'Team Management', settings:'System Settings', updates:'System Updates', about:'About SMSLink', profile:'Account Profile'};
  const currentTitle = pageTitles[id] || 'Dashboard';
  document.getElementById('pageTitle').textContent = currentTitle;
  document.title = '<?php echo htmlspecialchars($appName); ?> - ' + currentTitle;
  
  if (updateHistory) {
    const basePath = getDashboardBasePath();
    const targetUrl = basePath + id;
    if (window.location.pathname !== targetUrl && window.location.pathname !== targetUrl + '/') {
      history.pushState({ sec: id }, '', targetUrl);
    }
    if (window.location.hash) {
      history.replaceState({ sec: id }, '', targetUrl);
    }
  }

  const sb = document.getElementById('appSidebar');
  const ov = document.getElementById('sidebarOverlay');
  if(sb) sb.classList.remove('mobile-open');
  if(ov) ov.classList.remove('mobile-open');

  if (id === 'sent') {
    refreshSentMessagesList();
  } else if (id === 'devices') {
    refreshDeviceList();
  }
}

function initRouter(){
  const hash = location.hash.replace('#', '').trim();
  let targetSec = '';

  if (hash && validSections.includes(hash)) {
    targetSec = hash;
    const basePath = getDashboardBasePath();
    history.replaceState({ sec: hash }, '', basePath + hash);
  } else {
    const path = window.location.pathname.replace(/\/+$/, '');
    const parts = path.split('/');
    const lastPart = parts[parts.length - 1];
    if (validSections.includes(lastPart)) {
      targetSec = lastPart;
    } else {
      targetSec = 'status';
      const basePath = getDashboardBasePath();
      if (window.location.pathname !== basePath + 'status' && window.location.pathname !== basePath + 'status/') {
        history.replaceState({ sec: 'status' }, '', basePath + 'status');
      }
    }
  }

  showSection(targetSec, null, false);
}

window.addEventListener('popstate', function(e){
  if (e.state && e.state.sec && validSections.includes(e.state.sec)) {
    showSection(e.state.sec, null, false);
  } else {
    const path = window.location.pathname.replace(/\/+$/, '');
    const lastPart = path.split('/').pop();
    showSection(validSections.includes(lastPart) ? lastPart : 'status', null, false);
  }
});

window.addEventListener('hashchange', function(){
  const hash = location.hash.replace('#', '').trim();
  if (hash && validSections.includes(hash)) {
    showSection(hash, null, true);
  }
});

function initCharts() {
  const ctxOutbound = document.getElementById('outboundChart')?.getContext('2d');
  if (ctxOutbound) {
    new Chart(ctxOutbound, {
      type: 'line',
      data: {
        labels: <?php echo $chartLabelsJson; ?>,
        datasets: [
          {
            label: 'Sent / Delivered',
            data: <?php echo $chartSentJson; ?>,
            borderColor: '<?php echo $themeColor; ?>',
            backgroundColor: 'rgba(<?php list($r,$g,$b)=sscanf($themeColor,"#%02x%02x%02x"); echo "$r,$g,$b"; ?>, 0.12)',
            fill: true,
            tension: 0.35,
            borderWidth: 2.5,
            pointRadius: 4,
            pointBackgroundColor: '<?php echo $themeColor; ?>',
            pointHoverRadius: 6
          },
          {
            label: 'Failed',
            data: <?php echo $chartFailedJson; ?>,
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.08)',
            fill: true,
            tension: 0.35,
            borderWidth: 2,
            pointRadius: 4,
            pointBackgroundColor: '#ef4444',
            pointHoverRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { boxWidth: 12, font: { family: 'Inter', size: 12, weight: '500' } } },
          tooltip: { mode: 'index', intersect: false }
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { family: 'Inter', size: 11 } } },
          y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0, font: { family: 'Inter', size: 11 } } }
        }
      }
    });
  }

  const ctxStatus = document.getElementById('statusChart')?.getContext('2d');
  if (ctxStatus) {
    new Chart(ctxStatus, {
      type: 'doughnut',
      data: {
        labels: ['Sent / Delivered', 'Pending Queued', 'Failed'],
        datasets: [{
          data: [<?php echo (int)$totalSent; ?>, <?php echo (int)$queuedCount; ?>, <?php echo (int)$failedSent; ?>],
          backgroundColor: ['<?php echo $themeColor; ?>', '#f59e0b', '#ef4444'],
          borderWidth: 2,
          borderColor: '#ffffff'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14, font: { family: 'Inter', size: 12, weight: '500' } } }
        },
        cutout: '72%'
      }
    });
  }
}

window.addEventListener('DOMContentLoaded', () => {
  initRouter();
  initCharts();
  setTimeout(() => { checkForUpdates(true); }, 1500);
});


function autoDetectUrl(){
  let loc = window.location.origin + window.location.pathname;
  loc = loc.replace(/\/dashboard\/?.*$/, '');
  loc = loc.replace(/\/+$/, '');
  document.getElementById('appUrlInput').value = loc;
}

function openAddMemberModal(){
  const m = document.getElementById('addMemberModal');
  const n = document.getElementById('addMemberNotice');
  if(n) n.style.display = 'none';
  document.getElementById('addMemberForm').reset();
  m.style.display = 'flex';
}
function closeAddMemberModal(){
  document.getElementById('addMemberModal').style.display = 'none';
}
function generateRandomMemberPassword(){
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  let pwd = '';
  for(let i=0; i<10; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
  document.getElementById('memberPasswordInput').value = pwd;
}

function openSetPasswordModal(userId, username){
  const m = document.getElementById('setPasswordModal');
  const n = document.getElementById('setPasswordNotice');
  if(n) n.style.display = 'none';
  document.getElementById('setPasswordForm').reset();
  document.getElementById('setPasswordUserId').value = userId;
  document.getElementById('setPasswordTargetName').textContent = username;
  m.style.display = 'flex';
}
function closeSetPasswordModal(){
  document.getElementById('setPasswordModal').style.display = 'none';
}

function filterTeamMembers(query){
  const q = query.toLowerCase().trim();
  const rows = document.querySelectorAll('#teamTable tbody tr');
  rows.forEach(r => {
    const un = r.dataset.username || '';
    const em = r.dataset.email || '';
    r.style.display = (!q || un.includes(q) || em.includes(q)) ? '' : 'none';
  });
}

async function toggleMemberStatus(userId){
  const fd = new FormData();
  fd.append('user_id', userId);
  try {
    const r = await fetch('?action=toggle_member_status', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){
      const badge = document.getElementById('status-badge-' + userId);
      const btn = document.getElementById('btn-status-' + userId);
      if(badge){
        badge.className = 'app-badge ' + (d.status === 'active' ? 'app-badge-green' : 'app-badge-amber');
        badge.textContent = d.status === 'active' ? 'Active' : 'Pending';
      }
      if(btn){
        btn.textContent = d.status === 'active' ? 'Suspend' : 'Activate';
      }
    } else {
      alert(d.error || 'Failed to toggle status');
    }
  } catch(e){
    alert('Network error');
  }
}

async function deleteTeamMember(userId, username){
  if(!confirm('Are you sure you want to delete team member "' + username + '"?')) return;
  const fd = new FormData();
  fd.append('user_id', userId);
  try {
    const r = await fetch('?action=delete_team_member', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){
      const row = document.getElementById('member-row-' + userId);
      if(row) row.remove();
    } else {
      alert(d.error || 'Failed to delete member');
    }
  } catch(e){
    alert('Network error');
  }
}

document.addEventListener('DOMContentLoaded', function(){
  const sForm = document.getElementById('settingsForm');
  if(sForm){
    sForm.addEventListener('submit', function(e){
      e.preventDefault();
      const notice = document.getElementById('settingsNotice');
      const fd = new FormData(this);
      fetch('?action=update_settings', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{
          if(d.ok){
            notice.style.display = 'block';
            notice.style.background = 'var(--primary-light)';
            notice.style.color = 'var(--primary)';
            notice.style.border = '1px solid rgba(5,125,119,0.3)';
            notice.textContent = d.message || 'System settings updated successfully!';
            
            const themeCol = document.getElementById('themeColorInput')?.value;
            const themeHov = document.getElementById('themeHoverInput')?.value;
            if(themeCol) document.documentElement.style.setProperty('--primary', themeCol);
            if(themeHov) document.documentElement.style.setProperty('--primary-hover', themeHov);

            setTimeout(()=>{
              window.location.reload();
            }, 800);
          } else {
            notice.style.display = 'block';
            notice.style.background = '#fef2f2';
            notice.style.color = '#dc2626';
            notice.style.border = '1px solid #fecaca';
            notice.textContent = d.error || 'Failed to update settings';
          }
        })
        .catch(()=>{
          notice.style.display = 'block';
          notice.style.background = '#fef2f2';
          notice.style.color = '#dc2626';
          notice.style.border = '1px solid #fecaca';
          notice.textContent = 'Network error occurred';
        });
    });
  }

  const pForm = document.getElementById('profileForm');
  if(pForm){
    pForm.addEventListener('submit', function(e){
      e.preventDefault();
      const notice = document.getElementById('profileNotice');
      const fd = new FormData(this);
      fetch('?action=update_profile', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{
          if(d.ok){
            notice.style.display = 'block';
            notice.style.background = 'var(--primary-light)';
            notice.style.color = 'var(--primary)';
            notice.style.border = '1px solid rgba(5,125,119,0.3)';
            notice.textContent = d.message || 'Profile updated successfully!';
            setTimeout(()=>location.reload(), 1200);
          } else {
            notice.style.display = 'block';
            notice.style.background = '#fef2f2';
            notice.style.color = '#dc2626';
            notice.style.border = '1px solid #fecaca';
            notice.textContent = d.error || 'Failed to update profile';
          }
        })
        .catch(()=>{
          notice.style.display = 'block';
          notice.style.background = '#fef2f2';
          notice.style.color = '#dc2626';
          notice.style.border = '1px solid #fecaca';
          notice.textContent = 'Network error occurred';
        });
    });
  }

  const addMemForm = document.getElementById('addMemberForm');
  if(addMemForm){
    addMemForm.addEventListener('submit', function(e){
      e.preventDefault();
      const notice = document.getElementById('addMemberNotice');
      const fd = new FormData(this);
      fetch('?action=add_team_member', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{
          if(d.ok){
            notice.style.display = 'block';
            notice.style.background = 'var(--primary-light)';
            notice.style.color = 'var(--primary)';
            notice.style.border = '1px solid rgba(5,125,119,0.3)';
            let msg = d.message || 'Member added!';
            if(d.generated_password){
              msg += ' (Generated password: ' + d.generated_password + ')';
            }
            notice.textContent = msg;
            setTimeout(() => {
              window.location.reload();
            }, 1200);
          } else {
            notice.style.display = 'block';
            notice.style.background = '#fef2f2';
            notice.style.color = '#dc2626';
            notice.style.border = '1px solid #fecaca';
            notice.textContent = d.error || 'Failed to add member';
          }
        })
        .catch(()=>{
          notice.style.display = 'block';
          notice.style.background = '#fef2f2';
          notice.style.color = '#dc2626';
          notice.style.border = '1px solid #fecaca';
          notice.textContent = 'Network error occurred';
        });
    });
  }

  const setPwdForm = document.getElementById('setPasswordForm');
  if(setPwdForm){
    setPwdForm.addEventListener('submit', function(e){
      e.preventDefault();
      const notice = document.getElementById('setPasswordNotice');
      const fd = new FormData(this);
      fetch('?action=set_team_member_password', {method:'POST', body:fd})
        .then(r=>r.json())
        .then(d=>{
          if(d.ok){
            notice.style.display = 'block';
            notice.style.background = 'var(--primary-light)';
            notice.style.color = 'var(--primary)';
            notice.style.border = '1px solid rgba(5,125,119,0.3)';
            notice.textContent = d.message || 'Password updated successfully!';
            setTimeout(() => {
              closeSetPasswordModal();
            }, 1000);
          } else {
            notice.style.display = 'block';
            notice.style.background = '#fef2f2';
            notice.style.color = '#dc2626';
            notice.style.border = '1px solid #fecaca';
            notice.textContent = d.error || 'Failed to update password';
          }
        })
        .catch(()=>{
          notice.style.display = 'block';
          notice.style.background = '#fef2f2';
          notice.style.color = '#dc2626';
          notice.style.border = '1px solid #fecaca';
          notice.textContent = 'Network error occurred';
        });
    });
  }
});

function filterSentMessages(){
  const statusVal = document.getElementById('sentStatusFilter').value.toLowerCase();
  const simVal    = document.getElementById('sentSimFilter').value;
  const searchVal = document.getElementById('sentSearchInput').value.toLowerCase().trim();

  const rows = document.querySelectorAll('#sentHistoryTable tbody tr');
  rows.forEach(row => {
    const st = row.dataset.status;
    const sim = row.dataset.sim;
    const text = row.dataset.text;

    let matchStatus = (statusVal === 'all') || (statusVal === 'sent' && (st === 'sent' || st === 'delivered')) || (st === statusVal);
    let matchSim = (simVal === 'all') || (sim === simVal);
    let matchSearch = (!searchVal) || text.includes(searchVal);

    if (matchStatus && matchSim && matchSearch) {
      row.style.display = '';
    } else {
      row.style.display = 'none';
    }
  });
}

let pairingCheckTimer = null;

async function openQrModal(){
  const modal = document.getElementById('qrModal');
  const container = document.getElementById('qrCodeContainer');
  const label = document.getElementById('pairingCodeLabel');
  modal.style.display = 'flex';
  container.innerHTML = `
    <div style="text-align:center;padding:30px 10px">
      <span class="qr-status-spinner" style="width:24px;height:24px;border-width:3px;margin-bottom:12px"></span>
      <div style="font-size:13px;color:#64748b;font-weight:500">Generating Secure QR Code...</div>
    </div>
  `;
  label.style.display = 'none';
  label.textContent = '';
  
  if (pairingCheckTimer) { clearInterval(pairingCheckTimer); pairingCheckTimer = null; }

  try {
    const r = await fetch('?action=generate_pairing_qr', {method:'POST'});
    const d = await r.json();
    if(d.ok){
      const qrData = encodeURIComponent(d.qr_data);
      const primaryQr = `https://quickchart.io/qr?size=200&text=${qrData}`;
      const fallbackQr = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${qrData}`;
      
      container.innerHTML = `
        <div style="text-align:center">
          <div style="position:relative;display:inline-block;padding:12px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.04);margin-bottom:14px">
            <img src="${primaryQr}" onerror="this.onerror=null;this.src='${fallbackQr}';" width="190" height="190" alt="Pairing QR" style="border-radius:10px;display:block">
          </div>
          <div id="qrStatusBanner" style="font-size:12.5px;font-weight:500;color:#0369a1;background:#f0f9ff;border:1px solid #bae6fd;padding:8px 16px;border-radius:20px;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 1px 3px rgba(0,0,0,0.02)">
            <span class="qr-status-spinner"></span>
            <span>Waiting for phone to scan QR code...</span>
          </div>
        </div>
      `;
      label.style.display = 'block';
      label.textContent = 'PAIRING CODE: ' + d.pairing_code;

      const token = d.token;
      const code = d.pairing_code;

      pairingCheckTimer = setInterval(async () => {
        try {
          const res = await fetch(`?action=check_pairing_status&token=${encodeURIComponent(token)}&pairing_code=${encodeURIComponent(code)}`);
          const statusData = await res.json();
          if (statusData.ok && statusData.paired) {
            clearInterval(pairingCheckTimer);
            pairingCheckTimer = null;

            container.innerHTML = `
              <div class="qr-success-card">
                <div class="qr-success-icon-wrap">
                  <svg class="qr-check-icon" viewBox="0 0 52 52">
                    <circle class="qr-check-circle" cx="26" cy="26" r="23" fill="none"/>
                    <path class="qr-check-path" fill="none" d="M14.1 27.2l7.1 7.2 16.7-16.8"/>
                  </svg>
                </div>
                <div class="qr-success-title">Device Connected Successfully</div>
                <div class="qr-success-sub"><strong>${statusData.device_name || 'Android Gateway'}</strong> is active &amp; online</div>
                <div class="qr-success-pill">
                  <span class="qr-pulse-dot"></span> Online &bull; Closing in 3 seconds
                </div>
              </div>
            `;
            label.style.display = 'none';

            refreshDeviceList();

            setTimeout(() => {
              closeQrModal();
            }, 3000);
          }
        } catch (e) {}
      }, 1500);

    } else {
      container.innerHTML = '<div style="color:#dc2626;font-size:13px;padding:20px;text-align:center">Failed to generate QR code</div>';
    }
  } catch(e) {
    container.innerHTML = '<div style="color:#dc2626;font-size:13px;padding:20px;text-align:center">Network error generating QR code</div>';
  }
}

function closeQrModal(){
  if (pairingCheckTimer) {
    clearInterval(pairingCheckTimer);
    pairingCheckTimer = null;
  }
  document.getElementById('qrModal').style.display = 'none';
}

async function refreshSentMessagesList() {
  try {
    const res = await fetch('?action=get_sent_messages_html');
    const data = await res.json();
    if (data.ok && data.html) {
      const tbody = document.querySelector('#sentHistoryTable tbody');
      if (tbody && tbody.innerHTML !== data.html) {
        tbody.innerHTML = data.html;
        filterSentMessages();
      }
    }
  } catch (e) {}
}

async function refreshDeviceList() {
  try {
    const res = await fetch('?action=get_devices_html');
    const data = await res.json();
    if (data.ok && data.html) {
      const card = document.querySelector('#section-devices .app-card');
      if (card && card.innerHTML !== data.html) {
        card.innerHTML = data.html;
      }
    }
  } catch (e) {}
}

// Auto-poll active section every 3 seconds for real-time updates without page reload
setInterval(() => {
  const sentSec = document.getElementById('section-sent');
  if (sentSec && sentSec.classList.contains('active')) {
    refreshSentMessagesList();
  }

  const devSec = document.getElementById('section-devices');
  if (devSec && devSec.classList.contains('active')) {
    refreshDeviceList();
  }
}, 3000);

async function deleteDevice(deviceId, deviceName) {
  if (!confirm(`Are you sure you want to delete device "${deviceName}"?`)) return;

  try {
    const res = await fetch('?action=delete_device', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ device_id: deviceId })
    });
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch (e) {}

    if (data && data.ok) {
      await refreshDeviceList();
    } else {
      alert((data && data.error) ? data.error : (text || 'Failed to delete device'));
    }
  } catch (e) {
    alert('Network error deleting device: ' + e.message);
  }
}

async function doSend(){
  const numbers = document.getElementById('sendNumbers').value.trim();
  const message = document.getElementById('sendMessage').value.trim();
  const device  = document.getElementById('sendDevice').value;
  const simSlot = document.getElementById('sendSimSlot').value;
  const statusEl = document.getElementById('sendStatus');
  if(!numbers || !message){ alert('Please enter phone numbers and message content.'); return; }
  statusEl.textContent = 'Queuing message...'; statusEl.style.color = '#b45309';
  const fd = new FormData();
  fd.append('numbers', numbers);
  fd.append('message', message);
  fd.append('device', device);
  fd.append('sim_slot', simSlot);
  try {
    const r = await fetch('?action=send_sms', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok){
      statusEl.textContent = 'Queued successfully!'; statusEl.style.color = '#057d77';
      document.getElementById('sendNumbers').value = ''; document.getElementById('sendMessage').value = '';
    } else {
      statusEl.textContent = d.error || 'Failed'; statusEl.style.color = '#ef4444';
    }
  } catch(e) { statusEl.textContent = 'Connection error'; statusEl.style.color = '#ef4444'; }
}

async function retrySms(id){
  const fd = new FormData(); fd.append('msg_id', id);
  const r = await fetch('?action=retry_sms', {method:'POST', body:fd});
  const d = await r.json();
  if(d.ok) location.reload();
}

async function genApiKey(){
  const inputEl = document.getElementById('keyName');
  const name = inputEl ? inputEl.value.trim() : '';
  const btn = document.getElementById('btnGenKey');
  if(!name) return;

  if (btn) {
    btn.disabled = true;
    btn.innerHTML = `<svg style="width:16px;height:16px;animation:spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke-opacity="1"></path></svg> Generating...`;
  }

  try {
    const fd = new FormData(); fd.append('key_name', name);
    const r = await fetch('?action=gen_api_key', {method:'POST', body:fd});
    const d = await r.json();

    if (btn) {
      btn.disabled = false;
      btn.innerHTML = `<svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg> Generate Secret Key`;
    }

    if(d.ok){
      if(inputEl) inputEl.value = '';

      const banner = document.getElementById('newKeyBanner');
      const rawInput = document.getElementById('newKeyRawInput');
      if (rawInput && banner) {
        rawInput.value = d.key;
        banner.style.display = 'block';
        banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }

      const noKeys = document.getElementById('noKeysRow');
      if (noKeys) noKeys.remove();

      const tbody = document.getElementById('apiKeysTbody');
      if (tbody) {
        const tr = document.createElement('tr');
        tr.id = 'key-row-' + d.key_id;
        tr.innerHTML = `
          <td>
            <div style="font-weight:600;color:#0f172a">${escapeHtml(d.name)}</div>
            <div style="font-size:11px;color:#94a3b8" class="mono">${escapeHtml(d.key_id)}</div>
          </td>
          <td>
            <div style="display:inline-flex;align-items:center;gap:6px">
              <code class="mono" style="font-size:12px;background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#334155;border:1px solid #e2e8f0">${escapeHtml(d.key || d.preview || d.key_id)}</code>
              <button type="button" class="app-btn app-btn-outline" style="padding:2px 6px;font-size:11px" onclick="copyText('${escapeHtml(d.key || d.preview || d.key_id)}', this)" title="Copy API Key">
                <svg style="width:12px;height:12px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
              </button>
            </div>
          </td>
          <td>
            <span class="app-badge app-badge-green" style="font-size:11px">Send</span>
            <span class="app-badge app-badge-blue" style="font-size:11px">Status</span>
          </td>
          <td>
            <strong style="color:#0f172a">0</strong>
            <span style="font-size:11px;color:#64748b"> calls</span>
          </td>
          <td style="font-size:12px;color:#64748b">
            <span style="color:#94a3b8;font-style:italic">Never used</span>
          </td>
          <td style="font-size:12px;color:#64748b">
            ${escapeHtml(d.created_at)}
          </td>
          <td style="text-align:right">
            <button type="button" class="app-btn app-btn-danger" style="padding:4px 10px;font-size:12px;display:inline-flex;align-items:center;gap:4px" onclick="revokeApiKey('${escapeHtml(d.key_id)}', '${escapeJsString(d.name)}')">
              <svg style="width:14px;height:14px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
              Revoke
            </button>
          </td>
        `;
        tbody.insertBefore(tr, tbody.firstChild);
      }

      const countEl = document.getElementById('apiKeyCount');
      if (countEl) {
        const cur = parseInt(countEl.textContent || '0', 10);
        countEl.textContent = cur + 1;
      }
    } else {
      alert(d.error || 'Failed to generate API Key');
    }
  } catch(e) {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = `<svg style="width:16px;height:16px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg> Generate Secret Key`;
    }
    alert('Network error while generating API Key.');
  }
}

async function revokeApiKey(keyId, keyName) {
  if(!confirm(`Are you sure you want to revoke the API key "${keyName}"?\n\nAny application or integration using this key will immediately lose access.`)) {
    return;
  }
  try {
    const fd = new FormData(); fd.append('key_id', keyId);
    const r = await fetch('?action=delete_api_key', {method:'POST', body:fd});
    const d = await r.json();
    if(d.ok) {
      const row = document.getElementById('key-row-' + keyId);
      if(row) row.remove();
      const countEl = document.getElementById('apiKeyCount');
      if (countEl) {
        const cur = parseInt(countEl.textContent || '0', 10);
        if (cur > 0) countEl.textContent = cur - 1;
      }
    } else {
      alert(d.error || 'Failed to revoke API key');
    }
  } catch(e) {
    alert('Network error revoking API key');
  }
}

function copyNewApiKey(btn) {
  const input = document.getElementById('newKeyRawInput');
  if(!input) return;
  copyText(input.value, btn);
}

function copyText(text, btnElement) {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text);
  } else {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    textArea.style.position = "fixed";
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try { document.execCommand('copy'); } catch (err) {}
    document.body.removeChild(textArea);
  }

  if (btnElement) {
    const orig = btnElement.innerHTML;
    btnElement.innerHTML = `<svg style="width:14px;height:14px;color:#10b981" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"></path></svg> Copied!`;
    setTimeout(() => { btnElement.innerHTML = orig; }, 2000);
  }
}

function escapeHtml(str) {
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
function escapeJsString(str) {
  return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

async function checkForUpdates(silent = false) {
  const badge = document.getElementById('updateBadge');
  const card = document.getElementById('updateAvailableCard');
  const checkBtn = document.getElementById('btnCheckUpdates');
  const sideVerBadge = document.getElementById('sidebarUpdateAvailableBadge');
  const sideMenuBadge = document.getElementById('sidebarUpdateBadge');
  
  if (checkBtn && !silent) {
    checkBtn.disabled = true;
    checkBtn.innerHTML = `<svg style="width:16px;height:16px;animation:spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10" stroke-opacity="1"></path></svg> Checking...`;
  }
  
  try {
    const res = await fetch('?action=check_updates');
    const data = await res.json();
    if (checkBtn && !silent) {
      checkBtn.disabled = false;
      checkBtn.innerHTML = `<svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg> Check for Updates`;
    }
    
    if (data.ok) {
      if (data.has_update) {
        if (sideVerBadge) {
          sideVerBadge.style.display = 'inline-block';
          sideVerBadge.textContent = 'UPDATE ' + data.latest_version;
        }
        if (sideMenuBadge) {
          sideMenuBadge.style.display = 'inline-block';
          sideMenuBadge.textContent = data.latest_version;
        }
        if (badge) {
          badge.textContent = 'Update Available: ' + data.latest_version;
          badge.className = 'app-badge app-badge-amber';
        }
        if (card) {
          card.style.display = 'block';
          const titleEl = document.getElementById('newVersionTitle');
          const notesEl = document.getElementById('newVersionNotes');
          if (titleEl) titleEl.textContent = 'Release: ' + (data.release_name || data.latest_version);
          if (notesEl) notesEl.textContent = data.release_notes || 'No changelog provided.';
          const dlBtn = document.getElementById('btnDownloadRelease');
          if (dlBtn && data.download_url) {
            dlBtn.href = data.download_url;
            dlBtn.style.display = 'inline-flex';
          }
        }
      } else {
        if (badge) {
          badge.textContent = 'Up to Date (' + data.current_version + ')';
          badge.className = 'app-badge app-badge-teal';
        }
        if (card) card.style.display = 'none';
        if (sideVerBadge) sideVerBadge.style.display = 'none';
        if (sideMenuBadge) sideMenuBadge.style.display = 'none';
        if (!silent) {
          alert('You are running the latest version of SMSLink (' + data.current_version + ')!');
        }
      }
    } else {
      if (!silent) alert('Failed to check updates: ' + (data.error || 'Unknown error'));
    }
  } catch (err) {
    if (checkBtn && !silent) {
      checkBtn.disabled = false;
      checkBtn.innerHTML = `<svg style="width:16px;height:16px" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg> Check for Updates`;
    }
    if (!silent) alert('Connection error while querying updates');
  }
}

async function applySystemUpdate() {
  if (!confirm('This will apply database schema migrations and update system structures. Continue?')) {
    return;
  }
  const btn = document.getElementById('btnApplyUpdate');
  const statusEl = document.getElementById('applyUpdateStatus');
  if (btn) btn.disabled = true;
  if (statusEl) {
    statusEl.style.display = 'block';
    statusEl.textContent = 'Migrating database tables & applying updates...';
    statusEl.style.color = 'var(--app-primary)';
  }
  
  try {
    const res = await fetch('?action=apply_update', { method: 'POST' });
    const data = await res.json();
    if (data.ok) {
      if (statusEl) {
        statusEl.textContent = data.message || 'System updated successfully!';
        statusEl.style.color = '#10b981';
      }
      setTimeout(() => {
        location.reload();
      }, 1500);
    } else {
      if (statusEl) {
        statusEl.textContent = 'Update failed: ' + (data.error || 'Unknown error');
        statusEl.style.color = '#ef4444';
      }
      if (btn) btn.disabled = false;
    }
  } catch (err) {
    if (statusEl) {
      statusEl.textContent = 'Network error while applying update.';
      statusEl.style.color = '#ef4444';
    }
    if (btn) btn.disabled = false;
  }
}
</script>
<?php endif; ?>
</body>
</html>
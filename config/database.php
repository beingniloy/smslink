<?php
function getDbCredentials() {
    $credFile = __DIR__ . '/db_credentials.php';
    if (file_exists($credFile)) {
        $creds = include $credFile;
        if (is_array($creds)) return $creds;
    }
    return [
        'host' => '127.0.0.1',
        'dbname' => 'sms',
        'user' => 'root',
        'pass' => ''
    ];
}

function getDbConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $c = getDbCredentials();
    $dbHost = $c['host'] ?: '127.0.0.1';
    $dbName = $c['dbname'] ?: 'sms';
    $dbUser = $c['user'] ?: 'root';
    $dbPass = $c['pass'] ?? '';

    try {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        try {
            $tmpPdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass);
            $tmpPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            
            $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $ex) {
            throw new Exception("Database Connection Error: " . $ex->getMessage());
        }
    }

    ensureTablesExist($pdo);
    return $pdo;
}

function ensureTablesExist($pdo) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $sql = "
    CREATE TABLE IF NOT EXISTS `users` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `username` VARCHAR(64) NOT NULL UNIQUE,
      `password_hash` VARCHAR(255) NOT NULL,
      `email` VARCHAR(128) NULL,
      `avatar_path` VARCHAR(255) NULL,
      `role` VARCHAR(20) DEFAULT 'admin',
      `status` VARCHAR(20) DEFAULT 'active',
      `last_login` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `devices` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `device_id` VARCHAR(128) NOT NULL UNIQUE,
      `device_uuid` VARCHAR(128) NULL,
      `device_name` VARCHAR(128) NOT NULL,
      `model` VARCHAR(128) NULL,
      `android_version` VARCHAR(32) NULL,
      `phone_number` VARCHAR(32) NULL,
      `status` ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
      `api_token` VARCHAR(255) NULL,
      `pairing_code` VARCHAR(64) NULL,
      `sms_sent_count` INT UNSIGNED DEFAULT 0,
      `last_seen` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `device_sims` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `sim_id` VARCHAR(64) NOT NULL,
      `device_id` VARCHAR(128) NOT NULL,
      `slot_index` TINYINT UNSIGNED NOT NULL DEFAULT 1,
      `subscription_id` INT NOT NULL DEFAULT 1,
      `carrier_name` VARCHAR(64) NULL,
      `display_name` VARCHAR(64) NULL,
      `phone_number` VARCHAR(32) NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `last_seen` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_dev_id` (`device_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `sms_messages` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `message_id` VARCHAR(64) NOT NULL,
      `recipient` VARCHAR(32) NOT NULL,
      `message_body` TEXT NOT NULL,
      `sim_slot` TINYINT UNSIGNED NOT NULL DEFAULT 0,
      `subscription_id` INT NULL,
      `status` ENUM('queued', 'processing', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'queued',
      `assigned_device_id` VARCHAR(128) NULL,
      `source` VARCHAR(32) DEFAULT 'api',
      `api_key_id` VARCHAR(64) NULL,
      `error_message` TEXT NULL,
      `sent_at` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX `idx_status` (`status`),
      INDEX `idx_device_status` (`assigned_device_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `incoming_messages` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `device_id` VARCHAR(128) NOT NULL,
      `sender` VARCHAR(32) NOT NULL,
      `message_body` TEXT NOT NULL,
      `sim_slot` TINYINT UNSIGNED NOT NULL DEFAULT 0,
      `subscription_id` INT NULL,
      `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `api_tokens` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `key_id` VARCHAR(64) NOT NULL UNIQUE,
      `name` VARCHAR(128) NOT NULL,
      `token_hash` VARCHAR(255) NOT NULL,
      `preview` VARCHAR(32) NOT NULL,
      `permissions` TEXT NULL,
      `rate_limit` INT UNSIGNED DEFAULT 100,
      `usage_count` BIGINT UNSIGNED DEFAULT 0,
      `last_used` DATETIME NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `device_logs` (
      `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `log_type` VARCHAR(32) NOT NULL,
      `detail` TEXT NOT NULL,
      `meta_data` JSON NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `templates` (
      `id` VARCHAR(64) PRIMARY KEY,
      `name` VARCHAR(128) NOT NULL,
      `message` TEXT NOT NULL,
      `variables` JSON NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `scheduled_sms` (
      `id` VARCHAR(64) PRIMARY KEY,
      `numbers` JSON NOT NULL,
      `message` TEXT NOT NULL,
      `send_at` DATETIME NOT NULL,
      `status` ENUM('pending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
      `sim_slot` TINYINT UNSIGNED NOT NULL DEFAULT 0,
      `device_id` VARCHAR(128) NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

    CREATE TABLE IF NOT EXISTS `system_settings` (
      `setting_key` VARCHAR(64) PRIMARY KEY,
      `setting_value` TEXT NULL,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $pdo->exec($sql);
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `avatar_path` VARCHAR(255) NULL AFTER `email`;");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'active' AFTER `role`;");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE `api_tokens` MODIFY `preview` VARCHAR(255) NOT NULL;");
    } catch (Exception $e) {}
    try {
        $stmtTrunc = $pdo->query("SELECT id, preview FROM api_tokens WHERE preview LIKE '%...%'");
        $truncKeys = $stmtTrunc->fetchAll(PDO::FETCH_ASSOC);
        foreach ($truncKeys as $tk) {
            $parts = explode('...', $tk['preview']);
            $prefix = $parts[0] ?? 'sk_live_';
            $suffix = $parts[1] ?? '';
            $middleLen = 48 - strlen($prefix) - strlen($suffix);
            if ($middleLen < 8) $middleLen = 32;
            $middle = substr(bin2hex(random_bytes(ceil($middleLen / 2))), 0, $middleLen);
            $fullKey = $prefix . $middle . $suffix;
            $hash = hash('sha256', $fullKey);
            $pdo->prepare("UPDATE api_tokens SET preview = ?, token_hash = ? WHERE id = ?")->execute([$fullKey, $hash, $tk['id']]);
        }
    } catch (Exception $e) {}

    // Insert and sync canonical settings
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
        if ($count == 0) {
            $autoUrl = getAutoDetectedBaseUrl();
            $stmtInit = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('app_name', 'SMSLink'), ('app_url', ?), ('theme_color', '#057d77'), ('theme_color_hover', '#04635e')");
            $stmtInit->execute([$autoUrl]);
        } else {
            $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('app_name', 'SMSLink') ON DUPLICATE KEY UPDATE setting_value = 'SMSLink'")->execute();
        }
    } catch (Exception $e) {}
}

function getAutoDetectedBaseUrl() {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    $dir = dirname($scriptName);
    $dir = str_replace('\\', '/', $dir);
    $dir = rtrim(preg_replace('#/(install|setup|dashboard|api|v1|device|sms|message|sim).*$#i', '', $dir), '/');
    if ($dir === '/' || $dir === '.') $dir = '';

    return $scheme . '://' . $host . $dir;
}

function getSystemSettings($pdo) {
    static $settings = null;
    if ($settings !== null) return $settings;

    $apkVer = defined('APP_APK_VERSION') ? APP_APK_VERSION : 'v1.0.0';
    $apkUrl = defined('APP_APK_URL') ? APP_APK_URL : 'https://github.com/beingniloy/smslink/releases/download/' . $apkVer . '/SMSLink-' . $apkVer . '.apk';

    $defaults = [
        'app_name' => 'SMSLink',
        'app_url' => getAutoDetectedBaseUrl(),
        'theme_color' => '#057d77',
        'theme_color_hover' => '#04635e',
        'app_apk_version' => $apkVer,
        'app_apk_url' => $apkUrl
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($rows)) {
            $settings = array_merge($defaults, array_filter($rows));
            if (empty($settings['app_url'])) {
                $settings['app_url'] = getAutoDetectedBaseUrl();
            }
            if (empty($settings['app_apk_url'])) {
                $settings['app_apk_url'] = $apkUrl;
            }
            return $settings;
        }
    } catch (Exception $e) {}

    $settings = $defaults;
    return $settings;
}

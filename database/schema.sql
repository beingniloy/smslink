-- ===================================================
-- SMSLink - MySQL Database Schema
-- Open Source SMS Gateway Software
-- ===================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(128) NULL,
  `avatar_path` VARCHAR(255) NULL,
  `role` VARCHAR(20) DEFAULT 'admin',
  `status` VARCHAR(20) DEFAULT 'active',
  `last_login` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `sms_sent_count` INT UNSIGNED DEFAULT 0,
  `last_seen` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_sim_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id` VARCHAR(64) NOT NULL,
  `recipient` VARCHAR(32) NOT NULL,
  `message_body` TEXT NOT NULL,
  `sim_slot` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=Auto, 1=SIM 1, 2=SIM 2',
  `subscription_id` INT NULL,
  `status` ENUM('queued', 'processing', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'queued',
  `assigned_device_id` VARCHAR(128) NULL,
  `source` VARCHAR(32) DEFAULT 'api',
  `api_key_id` VARCHAR(64) NULL,
  `error_message` TEXT NULL,
  `sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`),
  INDEX `idx_assigned_device` (`assigned_device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incoming_messages` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `device_id` VARCHAR(128) NOT NULL,
  `sender` VARCHAR(32) NOT NULL,
  `message_body` TEXT NOT NULL,
  `sim_slot` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `subscription_id` INT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key_id` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(128) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `preview` VARCHAR(255) NOT NULL,
  `permissions` TEXT NULL,
  `rate_limit` INT UNSIGNED DEFAULT 100,
  `usage_count` BIGINT UNSIGNED DEFAULT 0,
  `last_used` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `device_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `log_type` VARCHAR(32) NOT NULL,
  `detail` TEXT NOT NULL,
  `meta_data` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- QID Management System Database Schema
-- Database Name: qid_management_db

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `qid_records`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `backups_log`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `pending_scans`;
DROP TABLE IF EXISTS `app_tokens`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. QID Records
CREATE TABLE `qid_records` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(150) NOT NULL,
  `qid_number` VARCHAR(30) NOT NULL,
  `phone_number` VARCHAR(50) DEFAULT NULL,
  `company_name` VARCHAR(150) DEFAULT NULL,
  `job_title` VARCHAR(100) DEFAULT NULL,
  `nationality` VARCHAR(100) DEFAULT NULL,
  `expiry_date` DATE NOT NULL,
  `charge_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `actual_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Active', 'Expired', 'Renewed', 'Cancelled') NOT NULL DEFAULT 'Active',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_qid` (`qid_number`),
  INDEX `idx_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Payments Installments
CREATE TABLE `payments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `qid_record_id` INT(11) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` DATE NOT NULL,
  `payment_time` TIME NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Cash',
  `receipt_no` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_record_id` (`qid_record_id`),
  INDEX `idx_payment_date` (`payment_date`),
  CONSTRAINT `fk_payments_qid` FOREIGN KEY (`qid_record_id`) REFERENCES `qid_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Settings
CREATE TABLE `settings` (
  `setting_key` VARCHAR(60) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'QID Management System'),
('currency', 'QR'),
('expiry_alert_days', '30'),
('gdrive_enabled', '0'),
('gdrive_folder_id', ''),
('gdrive_client_email', ''),
('gdrive_private_key', ''),
('auto_backup_on_entry', '1'),
('company_name', 'Qatar Business Solutions'),
('company_phone', '+974 5500 0000'),
('last_backup_time', '');

-- 4. Backups Log
CREATE TABLE `backups_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `filename` VARCHAR(255) NOT NULL,
  `file_size` INT(11) NOT NULL DEFAULT 0,
  `backup_type` VARCHAR(50) NOT NULL DEFAULT 'manual',
  `status` VARCHAR(50) NOT NULL DEFAULT 'success',
  `gdrive_file_id` VARCHAR(255) DEFAULT NULL,
  `message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Users / Administrators
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Admin Account (admin / admin123)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`) VALUES
('admin', '$2y$10$W6as1e8VijmoTjJyEm1tHug7Q/1CXLNvF4QDC8AkPC4rFV1sg7QhW', 'Administrator', 'admin');

-- 6. Mobile App Persistent Auth Tokens
CREATE TABLE `app_tokens` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `device_name` VARCHAR(100) DEFAULT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_token` (`token`),
  INDEX `idx_user` (`user_id`),
  CONSTRAINT `fk_app_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Real-Time Pending Scans Queue (Per-User Synced)
CREATE TABLE `pending_scans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `qid_number` VARCHAR(30) NOT NULL,
  `scan_data` TEXT DEFAULT NULL,
  `is_consumed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_scan` (`user_id`, `is_consumed`, `id`),
  CONSTRAINT `fk_pending_scans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

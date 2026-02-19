-- VBS Registration System - MySQL schema

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Registrations (parent + payment)
CREATE TABLE IF NOT EXISTS `registrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_first_name` varchar(100) NOT NULL,
  `parent_last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text,
  `home_church` varchar(255) DEFAULT NULL,
  `alternative_pickup_name` varchar(100) DEFAULT NULL,
  `alternative_pickup_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `consent_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `digital_signature` varchar(200) DEFAULT NULL,
  `consent_agreed_at` datetime DEFAULT NULL,
  `photo_consent` varchar(10) DEFAULT NULL,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `total_amount_cents` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups (for assigning kids; names editable in Assign Groups UI)
CREATE TABLE IF NOT EXISTS `groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kids per registration
CREATE TABLE IF NOT EXISTS `registration_kids` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `registration_id` int unsigned NOT NULL,
  `group_id` int unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `age` tinyint unsigned DEFAULT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `last_grade_completed` varchar(20) DEFAULT NULL,
  `t_shirt_size` varchar(20) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_relationship` varchar(50) DEFAULT NULL,
  `medical_allergy_info` text,
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_registration_id` (`registration_id`),
  KEY `idx_group_id` (`group_id`),
  CONSTRAINT `fk_registration_kids_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_registration_kids_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key-value settings (pricing, discounts, etc.)
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(64) NOT NULL,
  `value` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT INTO `settings` (`key`, `value`) VALUES
  ('price_per_kid_cents', '5000'),
  ('currency', 'usd'),
  ('early_bird_start_date', ''),
  ('early_bird_end_date', ''),
  ('early_bird_price_per_kid_cents', '0'),
  ('multi_kid_price_per_kid_cents', '0'),
  ('multi_kid_min_count', '2'),
  ('max_kids_per_registration', '10'),
  ('groups_max_children', '8'),
  ('groups_count', '8'),
  ('consent_form_url', '#'),
  ('registration_open', '1'),
  ('consent_content', '')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Admin user for login (admin / password)
INSERT INTO `settings` (`key`, `value`) VALUES ('admin_password_hash', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE `key` = `key`;

SET FOREIGN_KEY_CHECKS = 1;

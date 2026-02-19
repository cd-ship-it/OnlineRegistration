-- Add groups table and kid-to-group assignment for Assign Groups feature

SET NAMES utf8mb4;

-- Groups (names editable in Assign Groups UI)
CREATE TABLE IF NOT EXISTS `groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `sort_order` smallint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add group_id to registration_kids (nullable; NULL = unassigned)
ALTER TABLE `registration_kids`
  ADD COLUMN `group_id` int unsigned DEFAULT NULL AFTER `registration_id`,
  ADD KEY `idx_group_id` (`group_id`),
  ADD CONSTRAINT `fk_registration_kids_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE SET NULL;

-- Settings for group assignment (configurable max per group and number of groups)
INSERT INTO `settings` (`key`, `value`) VALUES
  ('groups_max_children', '8'),
  ('groups_count', '8')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Seed default rows for Event Details settings (event_title, dates, times)
-- The settings table is key-value; ON DUPLICATE KEY UPDATE is a no-op so
-- running this migration more than once is safe.

SET NAMES utf8mb4;

INSERT INTO `settings` (`key`, `value`) VALUES
  ('event_title',      ''),
  ('event_start_date', ''),
  ('event_end_date',   ''),
  ('event_start_time', ''),
  ('event_end_time',   '')
ON DUPLICATE KEY UPDATE `key` = `key`;

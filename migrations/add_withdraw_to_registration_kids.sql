-- Withdrawn children are hidden from Assign Groups / group counts but still visible on registration detail.
ALTER TABLE `registration_kids`
  ADD COLUMN `withdraw` CHAR(1) NOT NULL DEFAULT '0' AFTER `sort_order`;

-- Preserve approved-leave context when a recalled employee clocks in.

SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
       AND COLUMN_NAME = 'on_approved_leave') = 0,
  'ALTER TABLE `attendance` ADD COLUMN `on_approved_leave` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
       AND COLUMN_NAME = 'approved_leave_type') = 0,
  'ALTER TABLE `attendance` ADD COLUMN `approved_leave_type` VARCHAR(100) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

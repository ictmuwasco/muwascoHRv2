-- 030b_workplan_objectives_auto_increment.sql
-- ====================================================================
-- The `workplan_objectives` table originally had an `id` column WITHOUT
-- auto_increment, so every insert had to supply an explicit id. This
-- converts it to AUTO_INCREMENT so the standard INSERT (no explicit id)
-- used by the new cascading workplan endpoints works normally.
--
-- SAFE: no data is modified; only the table definition changes, and the
-- AUTO_INCREMENT counter is seeded past the current maximum id so the
-- next insert is always unique.
--
-- IDEMPOTENT: the check makes this safe to re-run.
-- ====================================================================

SET @is_ai := (
    SELECT IFNULL(EXTRA, '') LIKE '%auto_increment%'
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'workplan_objectives'
      AND COLUMN_NAME  = 'id'
);
SET @sql := IF(@is_ai = 0,
    'ALTER TABLE workplan_objectives MODIFY id INT(11) NOT NULL AUTO_INCREMENT',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Leave Reports performance indexes
-- Justified by the report query patterns in the Leave Report module:
--   * status + start/end date range on the chosen date basis
--   * employee-scoped lookups
-- Existence-checked so re-running is safe and no duplicate indexes are created.
-- Place: backend/database/migrations/032_leave_report_indexes.sql

SET @db = DATABASE();

-- Composite index for status + date-range filtering (KPI + trend + records).
SET @s1 = (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = @db AND table_name = 'leave_applications'
             AND index_name = 'idx_leave_status_dates');
SET @sql1 = IF(@s1 = 0,
  'ALTER TABLE leave_applications ADD INDEX idx_leave_status_dates (status, start_date, end_date)',
  'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Composite index for employee-scoped scheduling/queries.
SET @s2 = (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = @db AND table_name = 'leave_applications'
             AND index_name = 'idx_leave_emp_date');
SET @sql2 = IF(@s2 = 0,
  'ALTER TABLE leave_applications ADD INDEX idx_leave_emp_date (employee_id, start_date)',
  'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
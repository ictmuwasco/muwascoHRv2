-- 020_attendance_attendance_date_column.sql
-- ------------------------------------------------------------------
-- Attendance idempotency: one clock-in per employee per attendance day.
--
-- Adds a STORED generated column `attendance_date` derived from
-- `clock_in` (DATE(clock_in) in the organisation's timezone, Africa/Nairobi),
-- plus a UNIQUE constraint on (employee_id, attendance_date).
--
-- This is the database-level backstop that guarantees clock-in
-- idempotency — even under concurrent double-clicks or replayed API
-- requests, the second INSERT violates the unique key and is rejected.
--
-- Application layer also performs an atomic SELECT-within-transaction
-- check, but the constraint is the authoritative guarantee.
--
-- Apply (one time):  mysql -u root -p muwasco < this file
-- Run via the project's existing ad-hoc migration convention (cf. 006).
-- ------------------------------------------------------------------

-- Idempotent guard: only add column + constraint if not present
SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'attendance_date');
SET @has_uk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND CONSTRAINT_NAME = 'uk_attendance_employee_date');
SET @need = IF(@has_col = 0 OR @has_uk = 0, 1, 0);
SET @sql = IF(@need = 1,
    'ALTER TABLE attendance
       ADD COLUMN IF NOT EXISTS attendance_date DATE
         AS (DATE(clock_in)) STORED,
     ADD CONSTRAINT IF NOT EXISTS uk_attendance_employee_date
       UNIQUE KEY uk_attendance_employee_date (employee_id, attendance_date)',
    'SELECT 1 AS no_op_attendance_date_already_exists');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Attendance Reports performance indexes
-- Justified by the reporting query patterns in the AttendanceReport module:
-- every analytics/records query filters `attendance.attendance_date BETWEEN ? AND ?`
-- (optionally + employee_id). The existing UNIQUE(employee_id, attendance_date)
-- key cannot serve date-leading range scans, and no standalone attendance_date
-- index exists, so range reports currently degrade to full table scans as the
-- attendance table grows. One composite index covers the range + employee
-- lookups; leave_applications is already covered by migration 032.
-- attendance_date is a STORED generated column, so it is indexable.
-- Existence-checked so re-running is safe and no duplicate indexes are created.
-- Place: backend/database/migrations/033_attendance_report_indexes.sql
-- Apply: php backend/database/run_migration_033.php

SET @db = DATABASE();

-- Composite index for attendance-date range reporting scans.
SET @s1 = (SELECT COUNT(*) FROM information_schema.statistics
           WHERE table_schema = @db AND table_name = 'attendance'
             AND index_name = 'idx_attendance_date_emp');
SET @sql1 = IF(@s1 = 0,
  'ALTER TABLE attendance ADD INDEX idx_attendance_date_emp (attendance_date, employee_id)',
  'SELECT 1');
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

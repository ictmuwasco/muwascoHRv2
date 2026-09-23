-- Attendance Optimization Migration
-- Adds indexes to speed up Clock In/Out queries and dashboard attendance lookups.
-- Run: php backend/database/run_migration.php 006_attendance_optimization.sql
--
-- IDEMPOTENT: every index below is also declared by 0000_baseline_schema.sql,
-- so each step is guarded against information_schema. Safe to run on a fresh
-- baseline database (CI) as well as on a legacy database predating the baseline.

-- Index for finding active sessions (clocked in, not clocked out) per employee
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
               AND INDEX_NAME = 'idx_attendance_employee_active');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_attendance_employee_active ON attendance (employee_id, clock_out)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for today's attendance lookups (employee + date range)
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
               AND INDEX_NAME = 'idx_attendance_employee_date');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_attendance_employee_date ON attendance (employee_id, clock_in)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for dashboard "present today" queries
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
               AND INDEX_NAME = 'idx_attendance_clock_in_date');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_attendance_clock_in_date ON attendance (clock_in)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for office-based attendance reporting
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
               AND INDEX_NAME = 'idx_attendance_office');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_attendance_office ON attendance (office_id, clock_in)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
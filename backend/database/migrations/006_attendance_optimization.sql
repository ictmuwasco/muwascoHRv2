-- Attendance Optimization Migration
-- Adds indexes to speed up Clock In/Out queries and dashboard attendance lookups.
-- Run: php backend/database/run_migration.php 006_attendance_optimization.sql

-- Index for finding active sessions (clocked in, not clocked out) per employee
CREATE INDEX idx_attendance_employee_active
    ON attendance (employee_id, clock_out);

-- Index for today's attendance lookups (employee + date range)
CREATE INDEX idx_attendance_employee_date
    ON attendance (employee_id, clock_in);

-- Index for dashboard "present today" queries
CREATE INDEX idx_attendance_clock_in_date
    ON attendance (clock_in);

-- Index for office-based attendance reporting
CREATE INDEX idx_attendance_office
    ON attendance (office_id, clock_in);
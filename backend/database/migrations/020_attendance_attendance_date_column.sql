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

-- 1. Generated column (derived from clock_in; NULL when clock_in is NULL).
--    Multiple NULLs are allowed by a UNIQUE index, so pre-existing rows
--    with no clock_in are not blocked.
ALTER TABLE attendance
  ADD COLUMN attendance_date DATE
    AS (DATE(clock_in)) STORED;

-- 2. Unique constraint: prevents two attendance rows for the same
--    employee on the same attendance day.
ALTER TABLE attendance
  ADD CONSTRAINT uk_attendance_employee_date
  UNIQUE KEY uk_attendance_employee_date (employee_id, attendance_date);

-- ===========================================================================
-- 101_evidence_based_indexes.sql
--
-- Indexes justified by measured EXPLAIN output, NOT by "index everything".
-- Each entry records the query, the measured plan before, and the reason.
--
-- ENGINE: MariaDB 10.4. Idempotent via information_schema guard +
-- PREPARE/EXECUTE (the pattern already used by 084_performance_indexes.sql),
-- so the migration is safe to re-run.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. leave_applications(leave_type_id)
--
-- BEFORE (measured):
--   EXPLAIN SELECT COUNT(*) FROM leave_applications WHERE leave_type_id=3;
--   type=ALL  possible_keys=NULL  key=NULL  rows=729  Extra="Using where"
--   -> full table scan. The column had NO index at all.
--
-- EVIDENCE: leave_type_id appears 122 times across backend/app and is a JOIN
-- key in LeaveController, LeaveRepository (x2), ReportsController (x2),
-- DashboardController and Models\LeaveRequest. The report and leave-type
-- breakdown endpoints filter/group by it on every request.
-- At 729 rows the scan is cheap today, but the table is the fastest-growing
-- HR table and the filter is on a reporting path, so it is indexed now
-- rather than after the first production timeout.
-- ---------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_applications'
      AND INDEX_NAME = 'idx_leave_type_id'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE leave_applications ADD INDEX idx_leave_type_id (leave_type_id)',
    'SELECT ''idx_leave_type_id exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. audit_logs(user_id, created_at)
--
-- BEFORE (measured):
--   EXPLAIN SELECT * FROM audit_logs WHERE user_id=5 ORDER BY created_at DESC
--          LIMIT 50;
--   type=ref  key=idx_user_id  rows=1  Extra="Using where; Using filesort"
--   -> index locates the user, but the ORDER BY created_at is then sorted
--      separately ("Using filesort") on every request.
--
-- EVIDENCE: AuditLogController serves the audit-trail screen ("activity for
-- this user, newest first") and the CSV export. `created_at` is indexed
-- alone (idx_created_at), which does not help once user_id is also pinned.
-- This composite index satisfies BOTH the equality and the ordering, removing
-- the filesort. audit_logs is an append-only table (1,011 rows and growing),
-- so the extra index is write-cheap.
-- ---------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
      AND INDEX_NAME = 'idx_audit_user_created'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE audit_logs ADD INDEX idx_audit_user_created (user_id, created_at)',
    'SELECT ''idx_audit_user_created exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ===========================================================================
-- DELIBERATELY NOT ADDED (documented so they are not "fixed" later by
-- guesswork):
--
-- * attendance: 9 indexes already. idx_attendance_date_emp(attendance_date,
--   employee_id) is a leftmost-prefix duplicate of
--   idx_attendance_date_emp_status(attendance_date, employee_id, status),
--   and uk_attendance_employee_date(employee_id, attendance_date) overlaps
--   idx_attendance_employee_date(employee_id, clock_in). Redundancy is
--   suspected but NOT removed here: which index the optimiser prefers
--   depends on real cardinality and workload, so removal needs production
--   EXPLAIN evidence first.
-- * leave_applications(status, start_date): idx_leave_status_dates already
--   exists and the measured plan uses it (ref, 611 rows). Selectivity is
--   poor because most rows are 'approved', so a new index would not help.
-- * notifications(user_id, is_read): idx_notifications_user_unread already
--   exists; the measured plan returns 1 row.
-- * security_logs: table removed in migration 099, along with the three
--   indexes migration 084 had added to it.
-- ===========================================================================
-- End of 101_evidence_based_indexes.sql
-- ===========================================================================

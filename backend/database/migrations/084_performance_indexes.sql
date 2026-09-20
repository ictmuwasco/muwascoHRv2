-- ============================================================================
-- 084_performance_indexes.sql
-- Phase: Performance — missing indexes + notifications PK integrity
--
-- Why (measured on this database, 2026-09-18):
--   * notifications: 2,623 rows with ZERO indexes AND no primary key; the
--     column `id` is a plain NOT NULL int that every writer leaves at 0
--     (MAX(id) = 0). Any read therefore full-scans + filesorts
--     ("type: ALL / Using filesort", 2,662 rows), which showed up as
--     GET /api/notifications taking 2,016-8,094 ms in System Monitoring.
--   * attendance: 12,882 rows. The dashboard aggregates filtered on
--     DATE(clock_in) = ?, which is non-sargable, so MariaDB reported
--     "type: ALL, rows: 13144" for every chart query. The table already has
--     a STORED GENERATED column `attendance_date` (date, index MUL) derived
--     from clock_in - filtering on it turns the scan into
--     "type: range, rows: 1" (measured 3.21 ms -> 0.33 ms).
--   * security_logs: 66,132 rows / 21.5 MB with ZERO indexes (audit S5 also
--     flags unbounded growth). Additive indexes only; retention is a
--     separate concern handled by the error/security retention jobs.
--
-- Style: idempotent + information_schema-guarded so it is safe to re-run and
-- portable across MariaDB and MySQL 8 (MySQL has no CREATE INDEX IF NOT
-- EXISTS). Mirrors the guard pattern used by migrations 036 / 083.
--
-- Place: backend/database/migrations/084_performance_indexes.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. notifications — repair `id` so it can be the clustered primary key.
--
--    Every existing row has id = 0, so the values carry no meaning and nothing
--    can reference them. Renumber them to unique sequential integers, then add
--    AUTO_INCREMENT + PRIMARY KEY. Both steps are guarded so re-running this
--    migration is a no-op.
-- ----------------------------------------------------------------------------
SET @has_pk := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND INDEX_NAME = 'PRIMARY'
);

-- Renumber duplicated/zeroed ids. The assignment trick is deliberately used
-- WITHOUT ORDER BY: MySQL 8 no longer supports UPDATE ... ORDER BY, and only
-- uniqueness matters here.
SET @needs_renumber := (
    SELECT IF(COUNT(*) <> COUNT(DISTINCT id), 1, 0) FROM notifications
);

SET @sql := IF(@has_pk = 0 AND @needs_renumber = 1,
    'SET @rn := 0',
    'SELECT ''notifications.id already unique'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@has_pk = 0 AND @needs_renumber = 1,
    'UPDATE notifications SET id = (@rn := @rn + 1)',
    'SELECT ''notifications.id already unique'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Promote id to AUTO_INCREMENT PRIMARY KEY (also gives InnoDB a clustered key).
SET @sql := IF(@has_pk = 0,
    'ALTER TABLE notifications MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (`id`)',
    'SELECT ''notifications already has a primary key'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. notifications — covering indexes for the two hot read paths:
--      SELECT * FROM notifications WHERE user_id = ? AND is_read = 0
--                      ORDER BY created_at DESC LIMIT ?
--      SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
--    Leading on user_id then created_at lets InnoDB satisfy both the filter
--    and the ORDER BY from the index (no filesort).
-- ----------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
      AND INDEX_NAME = 'idx_notifications_user_created'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_notifications_user_created (user_id, created_at)',
    'SELECT ''idx_notifications_user_created exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'
      AND INDEX_NAME = 'idx_notifications_user_unread'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE notifications ADD INDEX idx_notifications_user_unread (user_id, is_read, created_at)',
    'SELECT ''idx_notifications_user_unread exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3. attendance — composite indexes matching the dashboard predicates.
--    Queries filter by attendance_date (STORED GENERATED from clock_in) and
--    then by status / is_late, so the two-column composites let the engine
--    narrow to "today" before evaluating the rest.
-- ----------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
      AND INDEX_NAME = 'idx_attendance_date_status'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE attendance ADD INDEX idx_attendance_date_status (attendance_date, status)',
    'SELECT ''idx_attendance_date_status exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
      AND INDEX_NAME = 'idx_attendance_date_emp_status'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE attendance ADD INDEX idx_attendance_date_emp_status (attendance_date, employee_id, status)',
    'SELECT ''idx_attendance_date_emp_status exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
      AND INDEX_NAME = 'idx_attendance_date_late'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE attendance ADD INDEX idx_attendance_date_late (attendance_date, is_late)',
    'SELECT ''idx_attendance_date_late exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. leave_applications — widen the existing (status, start_date, end_date)
--    index to carry employee_id so the "on leave today" COUNT(DISTINCT
--    employee_id) aggregate is answered from the index alone.
-- ----------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_applications'
      AND INDEX_NAME = 'idx_leave_status_dates_emp'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE leave_applications ADD INDEX idx_leave_status_dates_emp (status, start_date, end_date, employee_id)',
    'SELECT ''idx_leave_status_dates_emp exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 5. security_logs — 66k rows / 21.5 MB currently has no index at all.
--    Index the access patterns the security dashboards use: "activity for a
--    user, newest first" and "events of a type over a window".
-- ----------------------------------------------------------------------------
SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_logs'
      AND INDEX_NAME = 'idx_security_logs_user_time'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE security_logs ADD INDEX idx_security_logs_user_time (user_id, `timestamp`)',
    'SELECT ''idx_security_logs_user_time exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_logs'
      AND INDEX_NAME = 'idx_security_logs_event_time'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE security_logs ADD INDEX idx_security_logs_event_time (event_type, `timestamp`)',
    'SELECT ''idx_security_logs_event_time exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_logs'
      AND INDEX_NAME = 'idx_security_logs_time'
);
SET @sql := IF(@exists = 0,
    'ALTER TABLE security_logs ADD INDEX idx_security_logs_time (`timestamp`)',
    'SELECT ''idx_security_logs_time exists'' AS step');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

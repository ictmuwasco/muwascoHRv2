-- ===========================================================================
-- 104_attendance_device_lock.sql
--
-- Server-side prevention of multiple employees clocking in from one device.
--
-- BACKGROUND
--   attendance.device_fingerprint already exists but was NEVER written by any
--   code path (129 of 14,826 rows hold a value, all legacy), and it has no
--   index. ip_address is captured but effectively unused. Nothing prevented
--   employee B from clocking in on the device employee A just used.
--
-- POLICY: time-windowed, 24 hours (configurable via
--   ATTENDANCE_DEVICE_LOCK_HOURS environment variable).
--   A device is locked to ONE employee for a rolling window measured from that
--   employee's most recent clock-in on it. After the window expires the device
--   frees up for the next shift, so shared kiosk devices remain usable.
--
-- This migration is PURELY ADDITIVE: it creates one index. It does not touch
-- existing attendance data and cannot lose records.
--
-- The index is what makes the lock lookup cheap. The enforcement query filters
-- on device_fingerprint + clock_in, and without this index MySQL would full-scan
-- all 14,826 attendance rows on EVERY clock-in attempt.
--
-- ENGINE: MariaDB 10.4 (identical to CI and production).
-- IDEMPOTENT: guarded by information_schema.statistics.
-- ROLLBACK: ALTER TABLE attendance DROP INDEX idx_attendance_device_clockin;
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. Supporting index for the device-lock lookup.
--    (device_fingerprint, clock_in) serves the window scan directly and is
--    left-to-right prefix compatible with device_fingerprint alone.
-- ---------------------------------------------------------------------------
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name   = 'attendance'
      AND index_name   = 'idx_attendance_device_clockin'
);

SET @idx_sql := IF(
    @idx_exists > 0,
    'SELECT 1',
    'ALTER TABLE attendance
        ADD INDEX idx_attendance_device_clockin (device_fingerprint, clock_in)'
);

PREPARE stmt FROM @idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Lock window (24h default).
--
-- The window is read from the ATTENDANCE_DEVICE_LOCK_HOURS environment
-- variable, NOT from a settings table: this project has no `settings` table
-- and config elsewhere (e.g. ATTENDANCE_ALLOW_UNVERIFIED_LOCATION) is env-based
-- via the env() helper in backend/bootstrap.php. Add to .env to override:
--
--   ATTENDANCE_DEVICE_LOCK_HOURS=24   # 0 disables the lock entirely
--
-- No SQL is required for this step; it is documented here for traceability.
-- ---------------------------------------------------------------------------

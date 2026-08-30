-- 026_attendance_audit_fields.sql
-- ==================================================================
-- Extends `audit_logs` so the centralized AuditService can capture the
-- full "who did what, when, where, from which device/channel and what
-- changed" picture for every attendance action:
--   * Affected employee (may differ from the actor on admin edits)
--   * Office where the action took place + GPS coordinates
--   * Precise location provenance (GPS / OFFICE / IP / UNKNOWN ...)
--   * Device / channel / browser / OS for "how it was performed"
--   * Correlation request id for end-to-end tracing
-- IDEMPOTENT: columns are added through a procedure that inspects
-- information_schema, so it is safe to re-run.
-- Apply (one time):  mysql -u root -p muwasco < this file
-- ==================================================================

DROP PROCEDURE IF EXISTS audit_add_col_if_missing;

DELIMITER //

CREATE PROCEDURE audit_add_col_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
        IN p_def   VARCHAR(255)
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND COLUMN_NAME  = p_column;

    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //

DELIMITER ;

-- === Affected employee (target of the attendance action) ================
CALL audit_add_col_if_missing('audit_logs', 'employee_id',
    'INT UNSIGNED NULL COMMENT ''Affected employee (employees.id)'' AFTER user_id');

-- === Office identity ===============
CALL audit_add_col_if_missing('audit_logs', 'office_id',
    'INT UNSIGNED NULL COMMENT ''Office id recorded against the action'' AFTER employee_id');
CALL audit_add_col_if_missing('audit_logs', 'office_name',
    'VARCHAR(255) NULL COMMENT ''Office name at time of event'' AFTER office_id');

-- === GPS coordinates (ONLY when actually available from the device) ======
CALL audit_add_col_if_missing('audit_logs', 'latitude',
    'DECIMAL(10,8) NULL COMMENT ''GPS latitude (only when device provided a fix)'' AFTER office_name');
CALL audit_add_col_if_missing('audit_logs', 'longitude',
    'DECIMAL(11,8) NULL COMMENT ''GPS longitude (only when device provided a fix)'' AFTER latitude');
CALL audit_add_col_if_missing('audit_logs', 'location_accuracy',
    'INT UNSIGNED NULL COMMENT ''GPS accuracy in metres (NULL when no fix)'' AFTER longitude');
CALL audit_add_col_if_missing('audit_logs', 'location_source',
    'VARCHAR(20) NULL COMMENT ''GPS|OFFICE|USER_SELECTED|UNVERIFIED|IP|UNKNOWN'' AFTER location_accuracy');

-- === Request correlation id ===============
CALL audit_add_col_if_missing('audit_logs', 'request_id',
    'VARCHAR(64) NULL COMMENT ''Correlation/request id for end-to-end tracing'' AFTER location_source');

-- === Channel / device provenance (how the action was performed) ========
CALL audit_add_col_if_missing('audit_logs', 'channel',
    'VARCHAR(50) NULL COMMENT ''WEB|MOBILE_WEB|DESKTOP|ADMIN_PORTAL|API|SYSTEM|BACKGROUND_JOB'' AFTER request_id');
CALL audit_add_col_if_missing('audit_logs', 'device_type',
    'VARCHAR(50) NULL COMMENT ''mobile|tablet|desktop|bot|unknown'' AFTER channel');
CALL audit_add_col_if_missing('audit_logs', 'browser',
    'VARCHAR(100) NULL COMMENT ''Best-effort browser family parsed from user agent'' AFTER device_type');
CALL audit_add_col_if_missing('audit_logs', 'operating_system',
    'VARCHAR(100) NULL COMMENT ''Best-effort OS parsed from user agent'' AFTER browser');

DROP PROCEDURE IF EXISTS audit_add_col_if_missing;

-- === Indexes for performant attendance audit queries (all idempotent) =
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_employee_id');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_employee_id (employee_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_office_id');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_office_id (office_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_location_source');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_location_source (location_source)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_channel');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_channel (channel)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_request_id');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_request_id (request_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
               AND INDEX_NAME = 'idx_audit_target_type_id');
SET @sql := IF(@idx = 0, 'ALTER TABLE audit_logs ADD INDEX idx_audit_target_type_id (target_type, target_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


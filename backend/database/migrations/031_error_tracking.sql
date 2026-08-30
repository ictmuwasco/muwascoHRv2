-- ============================================================================
-- Migration 031: Error Tracking & Observability
-- ----------------------------------------------------------------------------
-- Adds the application error tracking / incident management / performance
-- monitoring layer alongside the existing audit system (audit_logs is NOT
-- touched apart from adding a nullable request_id correlation column).
--
-- Tables:
--   error_groups        - Deduplicated incidents keyed by fingerprint
--   application_errors  - Individual error occurrences (server + client)
--   error_group_users   - Distinct affected users per group (exact counting)
--   performance_events  - Slow request / slow cron measurements
--   audit_logs          - + request_id column (correlation with errors)
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Error Groups (one row per unique fingerprint)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `error_groups` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `fingerprint`        VARCHAR(191) NOT NULL COMMENT 'Human readable grouping key e.g. attendance.runtime.database_timeout',
    `fingerprint_hash`   CHAR(64) NOT NULL COMMENT 'SHA-256 of canonical fingerprint parts (unique lookup key)',
    `title`              VARCHAR(255) NOT NULL COMMENT 'Short display title',
    `module`             VARCHAR(100) NOT NULL DEFAULT 'System' COMMENT 'HR module (matches AuditService modules)',
    `category`           VARCHAR(50) NOT NULL DEFAULT 'SYSTEM_ERROR',
    `severity`           VARCHAR(20) NOT NULL DEFAULT 'MEDIUM' COMMENT 'DEBUG|INFO|LOW|MEDIUM|HIGH|CRITICAL',
    `status`             VARCHAR(20) NOT NULL DEFAULT 'NEW' COMMENT 'NEW|ACKNOWLEDGED|INVESTIGATING|FIXED|VERIFIED|RESOLVED|IGNORED',
    `environment`        VARCHAR(20) NOT NULL DEFAULT 'production',
    `exception_class`    VARCHAR(191) DEFAULT NULL,
    `sample_message`     TEXT DEFAULT NULL,
    `sample_endpoint`    VARCHAR(255) DEFAULT NULL,
    `sample_http_method` VARCHAR(10)  DEFAULT NULL,
    `sample_file`        VARCHAR(255) DEFAULT NULL,
    `sample_line`        INT UNSIGNED DEFAULT NULL,
    `occurrence_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `affected_user_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `first_seen_at`      DATETIME NOT NULL,
    `last_seen_at`       DATETIME NOT NULL,
    `last_notified_at`   DATETIME DEFAULT NULL COMMENT 'Last alert emission (cooldown control)',
    `assigned_to`        INT UNSIGNED DEFAULT NULL COMMENT 'users.id of assigned developer/admin',
    `resolved_at`        DATETIME DEFAULT NULL,
    `resolved_by`        INT UNSIGNED DEFAULT NULL,
    `resolution_notes`   TEXT DEFAULT NULL,
    `fixed_version`      VARCHAR(50) DEFAULT NULL COMMENT 'Application version that fixed the issue',
    `tags`               JSON DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_fingerprint_hash` (`fingerprint_hash`),
    KEY `idx_fingerprint` (`fingerprint`),
    KEY `idx_severity_status` (`severity`, `status`),
    KEY `idx_module` (`module`),
    KEY `idx_last_seen` (`last_seen_at`),
    KEY `idx_first_seen` (`first_seen_at`),
    KEY `idx_assigned_to` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. Application Errors (individual occurrences)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `application_errors` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `error_uuid`         CHAR(36) NOT NULL,
    `error_group_id`     BIGINT UNSIGNED NOT NULL,
    `fingerprint`        VARCHAR(191) NOT NULL,
    `fingerprint_hash`   CHAR(64) NOT NULL,
    `request_id`         VARCHAR(64) DEFAULT NULL COMMENT 'Correlation id shared with audit_logs + response headers',
    `frontend_error_id`  VARCHAR(64) DEFAULT NULL COMMENT 'Browser-side generated id for client errors',
    `source`             VARCHAR(10) NOT NULL DEFAULT 'server' COMMENT 'server|client',
    `environment`        VARCHAR(20) NOT NULL DEFAULT 'production',
    `application_version` VARCHAR(50) DEFAULT NULL,
    `git_commit`         VARCHAR(40) DEFAULT NULL,
    `severity`           VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
    `status`             VARCHAR(20) NOT NULL DEFAULT 'NEW',
    `category`           VARCHAR(50) NOT NULL DEFAULT 'SYSTEM_ERROR',
    `exception_class`    VARCHAR(191) DEFAULT NULL,
    `error_code`         VARCHAR(100) DEFAULT NULL,
    `message`            TEXT DEFAULT NULL,
    `file`               VARCHAR(255) DEFAULT NULL,
    `line`               INT UNSIGNED DEFAULT NULL,
    `stack_trace`        MEDIUMTEXT DEFAULT NULL COMMENT 'RESTRICTED - requires system_errors:view_sensitive',
    `http_method`        VARCHAR(10) DEFAULT NULL,
    `endpoint`           VARCHAR(255) DEFAULT NULL,
    `route_name`         VARCHAR(100) DEFAULT NULL,
    `status_code`        SMALLINT UNSIGNED DEFAULT NULL,

    `user_id`            INT UNSIGNED DEFAULT NULL,
    `employee_id`        INT UNSIGNED DEFAULT NULL,
    `office_id`          INT UNSIGNED DEFAULT NULL,
    `department_id`      INT UNSIGNED DEFAULT NULL,
    `ip_address`         VARCHAR(45) DEFAULT NULL,
    `user_agent`         TEXT DEFAULT NULL,
    `request_payload`    MEDIUMTEXT DEFAULT NULL COMMENT 'Sanitized JSON - RESTRICTED',
    `request_query`      MEDIUMTEXT DEFAULT NULL COMMENT 'Sanitized JSON - RESTRICTED',
    `request_headers`    TEXT DEFAULT NULL COMMENT 'Sanitized subset - RESTRICTED',
    `response_metadata`  JSON DEFAULT NULL,
    -- Client-side context
    `url`                VARCHAR(500) DEFAULT NULL,
    `component`          VARCHAR(255) DEFAULT NULL,
    `browser`            VARCHAR(100) DEFAULT NULL,
    `browser_version`    VARCHAR(50) DEFAULT NULL,
    `operating_system`   VARCHAR(100) DEFAULT NULL,
    `device_type`        VARCHAR(50) DEFAULT NULL,
    `screen_size`        VARCHAR(20) DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_error_uuid` (`error_uuid`),
    KEY `idx_request_id` (`request_id`),
    KEY `idx_frontend_error_id` (`frontend_error_id`),
    KEY `idx_group_created` (`error_group_id`, `created_at`),
    KEY `idx_fingerprint_created` (`fingerprint_hash`, `created_at`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_severity` (`severity`),
    KEY `idx_source_created` (`source`, `created_at`),
    KEY `idx_endpoint` (`endpoint`(191)),
    KEY `idx_user_created` (`user_id`, `created_at`),
    KEY `idx_employee` (`employee_id`),
    KEY `idx_department` (`department_id`),
    KEY `idx_status_code` (`status_code`),
    CONSTRAINT `fk_apperr_group` FOREIGN KEY (`error_group_id`) REFERENCES `error_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Affected users per group (exact distinct counting, O(1) maintenance)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `error_group_users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `error_group_id` BIGINT UNSIGNED NOT NULL,
    `user_id`       INT UNSIGNED NOT NULL,
    `first_seen_at` DATETIME NOT NULL,
    UNIQUE KEY `uk_group_user` (`error_group_id`, `user_id`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_errgroupusers_group` FOREIGN KEY (`error_group_id`) REFERENCES `error_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. Performance Events (slow requests / slow cron runs)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `performance_events` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `request_id`         VARCHAR(64) DEFAULT NULL,
    `endpoint`           VARCHAR(255) DEFAULT NULL,
    `http_method`        VARCHAR(10) DEFAULT NULL,
    `duration_ms`        INT UNSIGNED NOT NULL,
    `threshold_level`    VARCHAR(20) NOT NULL DEFAULT 'warning' COMMENT 'warning|slow|critical',
    `status_code`        SMALLINT UNSIGNED DEFAULT NULL,
    `user_id`            INT UNSIGNED DEFAULT NULL,
    `memory_kb`          INT UNSIGNED DEFAULT NULL,
    `environment`        VARCHAR(20) DEFAULT NULL,
    `application_version` VARCHAR(50) DEFAULT NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_perf_created` (`created_at`),
    KEY `idx_perf_level_created` (`threshold_level`, `created_at`),
    KEY `idx_perf_request` (`request_id`),
    KEY `idx_perf_endpoint` (`endpoint`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. Correlate audit trail with errors: request_id column on audit_logs
--    (guarded ALTER - MySQL lacks ADD COLUMN IF NOT EXISTS)
-- ---------------------------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME   = 'audit_logs'
                     AND COLUMN_NAME  = 'request_id');
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE ``audit_logs`` ADD COLUMN ``request_id`` VARCHAR(64) DEFAULT NULL AFTER ``user_role_snapshot``, ADD KEY ``idx_audit_request_id`` (``request_id``)',
    'SELECT ''audit_logs.request_id already present''');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. RBAC seeds - system_errors module
--    super_admin has implicit full access (RBAC bypass); seeded explicitly so
--    the permission catalog/UI shows it. hr_manager gets dashboard access but
--    NOT view_sensitive (no stack traces / payloads).
-- ---------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
    ('super_admin', 'system_errors', 'view',           1),
    ('super_admin', 'system_errors', 'manage',         1),
    ('super_admin', 'system_errors', 'assign',         1),
    ('super_admin', 'system_errors', 'resolve',        1),
    ('super_admin', 'system_errors', 'view_sensitive', 1),
    ('hr_manager',  'system_errors', 'view',           1),
    ('hr_manager',  'system_errors', 'manage',         1),
    ('hr_manager',  'system_errors', 'resolve',        1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

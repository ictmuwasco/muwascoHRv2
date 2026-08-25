-- Audit Logs Table (centralized audit trail)
-- Records important user actions across the HR application.
-- Sensitive data (passwords, JWT tokens, raw credentials, API keys) is NEVER stored.

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`            INT UNSIGNED DEFAULT NULL COMMENT 'Authenticated actor user id',
    `user_name_snapshot` VARCHAR(255) DEFAULT NULL COMMENT 'Display name of the actor at time of event',
    `user_role_snapshot` VARCHAR(100) DEFAULT NULL COMMENT 'Role of the actor at time of event',
    `action`             VARCHAR(100) NOT NULL COMMENT 'Standardized action (LOGIN, CREATE, UPDATE, ...)',
    `module`             VARCHAR(100) NOT NULL COMMENT 'Module affected (Employees, Leave, Authentication, ...)',
    `description`        TEXT DEFAULT NULL COMMENT 'Human readable description',
    `target_type`        VARCHAR(100) DEFAULT NULL COMMENT 'Type of target record (Employee, LeaveRequest, ...)',
    `target_id`          BIGINT UNSIGNED DEFAULT NULL COMMENT 'Primary key of the target record',
    `target_name`        VARCHAR(255) DEFAULT NULL COMMENT 'Display name of the target record',
    `ip_address`         VARCHAR(45) DEFAULT NULL COMMENT 'Request IP (captured on the backend)',
    `user_agent`         TEXT DEFAULT NULL COMMENT 'Request user agent',
    `location`           VARCHAR(255) DEFAULT NULL COMMENT 'Best-effort IP-derived location',
    `old_values`         JSON DEFAULT NULL COMMENT 'Snapshot before the change',
    `new_values`         JSON DEFAULT NULL COMMENT 'Snapshot after the change',
    `metadata`           JSON DEFAULT NULL COMMENT 'Additional non-sensitive metadata',
    `status`             VARCHAR(50)  NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS | FAILED',
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY `idx_user_id`    (`user_id`),
    KEY `idx_action`     (`action`),
    KEY `idx_module`     (`module`),
    KEY `idx_module_action` (`module`, `action`),
    KEY `idx_target_type` (`target_type`),
    KEY `idx_target_id`  (`target_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_status`     (`status`),
    KEY `idx_user_action` (`user_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

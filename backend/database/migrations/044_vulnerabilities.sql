-- ============================================================================
-- 044_vulnerabilities.sql
-- Phase: Security Operations — Vulnerability Integration Pipeline (Phase 5)
--
-- Introduces a first-class vulnerability domain integrated with the existing
-- 043 event/telemetry, incident-correlation, rules-engine, and AI-analysis
-- layers. Two nullable correlation columns (vulnerability_id) are appended to
-- the existing security_events / security_incidents tables (additive, never
-- destructive). All DDL is idempotent.
--
-- Security: no credentials/tokens/PII stored; vuln title/description/remediation
-- are admin-only and NEVER forwarded raw to the AI provider (only sanitized
-- structural fields are). FKs use ON DELETE SET NULL.
--
-- Place: backend/database/migrations/044_vulnerabilities.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. vulnerabilities — canonical vulnerability record (8-state lifecycle).
--    OPEN -> ACKNOWLEDGED -> IN_PROGRESS -> MITIGATED -> RESOLVED
--    RESOLVED requires remediation + verification_notes (enforced in
--    VulnerabilityService::updateStatus). DETECTED is implied by first_detected_at;
--    the flow is tracked via `status` + the vulnerability_timeline table.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vulnerabilities` (
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `uuid`                   CHAR(36)        NOT NULL UNIQUE,
    `title`                  VARCHAR(255)    NOT NULL COMMENT 'Short summary (no PII)',
    `description`            TEXT            DEFAULT NULL COMMENT 'Admin-facing; never sent raw to AI',
    `category`               VARCHAR(64)     NOT NULL,
    `type`                   VARCHAR(64)     NOT NULL,
    `cwe_id`                 VARCHAR(32)     DEFAULT NULL,
    `owasp_category`         VARCHAR(64)     DEFAULT NULL,
    `severity`               ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    `risk_score`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `cvss_score`             DECIMAL(4,2)    DEFAULT NULL,
    `status`                 ENUM('OPEN','ACKNOWLEDGED','IN_PROGRESS','MITIGATED','RESOLVED','FALSE_POSITIVE','ACCEPTED_RISK','REOPENED') NOT NULL DEFAULT 'OPEN',
    `source`                 ENUM('manual','ai_analysis','rule_engine','event') NOT NULL DEFAULT 'rule_engine',
    `ai_classification`      VARCHAR(50)     DEFAULT NULL,
    `ai_confidence`          DECIMAL(5,4)    DEFAULT NULL,
    `ai_analysis`            TEXT          DEFAULT NULL,
    `affected_resource_type` VARCHAR(50)     DEFAULT NULL,
    `affected_resource_id`   VARCHAR(50)     DEFAULT NULL,
    `affected_endpoint`      VARCHAR(255)    DEFAULT NULL,
    `affected_route`         VARCHAR(255)    DEFAULT NULL,
    `affected_method`        VARCHAR(10)     DEFAULT NULL,
    `first_detected_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `verified_at`            DATETIME        DEFAULT NULL,
    `resolved_at`            DATETIME        DEFAULT NULL,
    `resolved_by`            BIGINT UNSIGNED DEFAULT NULL,
    `resolved_notes`         TEXT          DEFAULT NULL,
    `remediation`            TEXT          DEFAULT NULL,
    `verification_notes`     TEXT          DEFAULT NULL,
    `assigned_to`            BIGINT UNSIGNED DEFAULT NULL,
    `metadata`               JSON          DEFAULT NULL,
    `created_by`             BIGINT UNSIGNED DEFAULT NULL,
    `created_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_vuln_status`    (`status`),
    INDEX `idx_vuln_severity`  (`severity`),
    INDEX `idx_vuln_category`   (`category`),
    INDEX `idx_vuln_endpoint`   (`affected_endpoint`),
    INDEX `idx_vuln_resource`   (`affected_resource_type`, `affected_resource_id`),
    INDEX `idx_vuln_first_seen` (`first_detected_at`),
    INDEX `idx_vuln_last_seen`  (`last_seen_at`),
    INDEX `idx_vuln_assigned`   (`assigned_to`),
    INDEX `idx_vuln_risk`       (`risk_score`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    COMMENT='Tracked security vulnerabilities with lifecycle and AI analysis';

-- ----------------------------------------------------------------------------
-- 2. vulnerability_events — pivot (vulns <-> security_events).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vulnerability_events` (
    `vulnerability_id` BIGINT UNSIGNED NOT NULL,
    `event_id`         BIGINT UNSIGNED NOT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`vulnerability_id`, `event_id`),
    CONSTRAINT `fk_ve_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ve_event` FOREIGN KEY (`event_id`) REFERENCES `security_events`(`id`) ON DELETE CASCADE,
    INDEX `idx_ve_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Many-to-many link between vulnerabilities and security events';

-- ----------------------------------------------------------------------------
-- 3. vulnerability_incidents — pivot (vulns <-> security_incidents).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vulnerability_incidents` (
    `vulnerability_id` BIGINT UNSIGNED NOT NULL,
    `incident_id`      BIGINT UNSIGNED NOT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`vulnerability_id`, `incident_id`),
    CONSTRAINT `fk_vi_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vi_incident` FOREIGN KEY (`incident_id`) REFERENCES `security_incidents`(`id`) ON DELETE CASCADE,
    INDEX `idx_vi_incident` (`incident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Many-to-many link between vulnerabilities and security incidents';

-- ----------------------------------------------------------------------------
-- 4. vulnerability_timeline — immutable forensic lifecycle log.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vulnerability_timeline` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `vulnerability_id` BIGINT UNSIGNED NOT NULL,
    `actor_user_id`    BIGINT UNSIGNED DEFAULT NULL,
    `actor_name`       VARCHAR(255)      DEFAULT NULL,
    `status_from`      VARCHAR(50)       DEFAULT NULL,
    `status_to`        VARCHAR(50)       NOT NULL,
    `notes`            TEXT            DEFAULT NULL,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_vt_vulnerability` FOREIGN KEY (`vulnerability_id`) REFERENCES `vulnerabilities`(`id`) ON DELETE CASCADE,
    INDEX `idx_vt_vuln` (`vulnerability_id`),
    INDEX `idx_vt_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Immutable audit timeline of vulnerability lifecycle changes';

-- ----------------------------------------------------------------------------
-- 5. Correlation columns on the existing 043 tables (additive, non-breaking).
--    Each alteration is guarded so the migration is safe to re-run.
-- ----------------------------------------------------------------------------

-- security_events.vulnerability_id
SET @col_se = (SELECT COLUMN_NAME FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events'
                 AND COLUMN_NAME = 'vulnerability_id' LIMIT 1);
SET @ddl_se = IF(@col_se IS NULL,
    'ALTER TABLE security_events ADD COLUMN vulnerability_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt_se FROM @ddl_se; EXECUTE stmt_se; DEALLOCATE PREPARE stmt_se;

SET @fk_se = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events'
                AND CONSTRAINT_NAME = 'fk_se_vulnerability' LIMIT 1);
SET @ddl_fk_se = IF(@fk_se IS NULL,
    'ALTER TABLE security_events ADD CONSTRAINT fk_se_vulnerability FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt_fk_se FROM @ddl_fk_se; EXECUTE stmt_fk_se; DEALLOCATE PREPARE stmt_fk_se;

SET @idx_se = (SELECT INDEX_NAME FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events'
                 AND INDEX_NAME = 'idx_se_vulnerability' LIMIT 1);
SET @ddl_idx_se = IF(@idx_se IS NULL,
    'ALTER TABLE security_events ADD INDEX idx_se_vulnerability (vulnerability_id)',
    'SELECT 1');
PREPARE stmt_idx_se FROM @ddl_idx_se; EXECUTE stmt_idx_se; DEALLOCATE PREPARE stmt_idx_se;

-- security_incidents.vulnerability_id
SET @col_si = (SELECT COLUMN_NAME FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_incidents'
                 AND COLUMN_NAME = 'vulnerability_id' LIMIT 1);
SET @ddl_si = IF(@col_si IS NULL,
    'ALTER TABLE security_incidents ADD COLUMN vulnerability_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE stmt_si FROM @ddl_si; EXECUTE stmt_si; DEALLOCATE PREPARE stmt_si;

SET @fk_si = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_incidents'
                AND CONSTRAINT_NAME = 'fk_si_vulnerability' LIMIT 1);
SET @ddl_fk_si = IF(@fk_si IS NULL,
    'ALTER TABLE security_incidents ADD CONSTRAINT fk_si_vulnerability FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt_fk_si FROM @ddl_fk_si; EXECUTE stmt_fk_si; DEALLOCATE PREPARE stmt_fk_si;

SET @idx_si = (SELECT INDEX_NAME FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_incidents'
                 AND INDEX_NAME = 'idx_inc_vulnerability' LIMIT 1);
SET @ddl_idx_si = IF(@idx_si IS NULL,
    'ALTER TABLE security_incidents ADD INDEX idx_inc_vulnerability (vulnerability_id)',
    'SELECT 1');
PREPARE stmt_idx_si FROM @ddl_idx_si; EXECUTE stmt_idx_si; DEALLOCATE PREPARE stmt_idx_si;

-- ============================================================================
-- End of 044_vulnerabilities.sql
-- ============================================================================

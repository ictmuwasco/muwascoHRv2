-- ============================================================================
-- 043_security_operations.sql
-- Phase: Security Operations & AI-Assisted Threat Detection (Phase 5)
--
-- Introduces the security monitoring infrastructure:
--   security_events      — centralized security event collection (PII-safe)
--   security_incidents   — correlated security incidents
--   security_incident_events — many-to-many pivot (incidents <-> events)
--
-- SECURITY PRINCIPLES
--   * No passwords, tokens, API keys, session secrets, authorization headers,
--     cookies or complete sensitive request bodies are ever stored.
--   * Sensitive context values are redacted by SecurityEventService before insertion.
--   * Events are raw telemetry; incidents are correlated groups of events.
--   * The AI layer reads sanitized event metadata only — never raw PII.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS throughout.
--
-- Place: backend/database/migrations/043_security_operations.sql
-- ============================================================================
-- ----------------------------------------------------------------------------
-- 1. security_events — centralized security event telemetry
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_events` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_type`      VARCHAR(50)  NOT NULL COMMENT 'FAILED_LOGIN, BRUTE_FORCE, UNAUTHORIZED_OBJECT_ACCESS, IDOR_ATTEMPT, IDOR_ENUMERATION, PRIVILEGE_ESCALATION, etc.',
    `severity`        ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW',
    `risk_score`      TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 deterministic risk score',
    `user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'Authenticated user ID',
    `ip_address`      VARCHAR(45)  DEFAULT NULL,
    `user_agent`      VARCHAR(250) DEFAULT NULL,
    `session_id`      VARCHAR(128) DEFAULT NULL,
    `request_id`      VARCHAR(64)  DEFAULT NULL COMMENT 'X-Request-ID correlation',
    `http_method`     VARCHAR(10)  DEFAULT NULL,
    `route`           VARCHAR(255) DEFAULT NULL,
    `resource_type`   VARCHAR(50)  DEFAULT NULL COMMENT 'employee, leave, attendance, meeting, user',
    `resource_id`     VARCHAR(50)  DEFAULT NULL,
    `response_status` SMALLINT    DEFAULT NULL,
    `action_taken`    VARCHAR(50)  DEFAULT NULL COMMENT 'BLOCKED, DENIED, RATE_LIMITED, LOGGED, ALERTED',
    `description`     TEXT         DEFAULT NULL,
    `metadata`        JSON         DEFAULT NULL COMMENT 'Additional structured metadata (PII-sanitized)',
    `detected_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`     DATETIME     DEFAULT NULL,
    `resolved_by`     INT UNSIGNED DEFAULT NULL,
    INDEX `idx_sev_event_type` (`event_type`),
    INDEX `idx_sev_severity` (`severity`),
    INDEX `idx_sev_user_id` (`user_id`),
    INDEX `idx_sev_detected_at` (`detected_at`),
    INDEX `idx_sev_risk_score` (`risk_score`),
    INDEX `idx_sev_resource` (`resource_type`, `resource_id`),
    INDEX `idx_sev_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Centralized security event telemetry (PII-safe)';

-- ----------------------------------------------------------------------------
-- 2. security_incidents — correlated security incidents
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_incidents` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `incident_uuid`         CHAR(36)     NOT NULL UNIQUE COMMENT 'UUIDv4 for the incident',
    `severity`              ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'LOW',
    `risk_score`            TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100 aggregated risk score',
    `status`                ENUM('NEW','INVESTIGATING','CONTAINED','RESOLVED','FALSE_POSITIVE') NOT NULL DEFAULT 'NEW',
    `source`                VARCHAR(50)  NOT NULL COMMENT 'rule_engine, ai_analysis, manual',
    `summary`               TEXT         DEFAULT NULL COMMENT 'Administrator-friendly summary',
    `ai_classification`     VARCHAR(50)  DEFAULT NULL COMMENT 'NORMAL, SUSPICIOUS, LIKELY_ATTACK, HIGH_CONFIDENCE_ATTACK, CRITICAL',
    `ai_confidence`         DECIMAL(5,4) DEFAULT NULL COMMENT '0.0000-1.0000',
    `ai_reasoning`          TEXT         DEFAULT NULL COMMENT 'AI reasoning summary',
    `ai_risk_score`         TINYINT      DEFAULT NULL COMMENT 'AI-recommended risk score',
    `ai_recommended_action` VARCHAR(100) DEFAULT NULL,
    `first_seen`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`           DATETIME     DEFAULT NULL,
    `resolved_by`           INT UNSIGNED DEFAULT NULL,
    `resolution_notes`      TEXT         DEFAULT NULL,
    `related_event_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_inc_status` (`status`),
    INDEX `idx_inc_severity` (`severity`),
    INDEX `idx_inc_risk_score` (`risk_score`),
    INDEX `idx_inc_first_seen` (`first_seen`),
    INDEX `idx_inc_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 3. security_incident_events — pivot table (incidents <-> events)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_incident_events` (
    `incident_id`   BIGINT UNSIGNED NOT NULL,
    `event_id`      BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`incident_id`, `event_id`),
    CONSTRAINT `fk_ice_incident` FOREIGN KEY (`incident_id`) REFERENCES `security_incidents`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ice_event` FOREIGN KEY (`event_id`) REFERENCES `security_events`(`id`) ON DELETE CASCADE,
    INDEX `idx_ice_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Many-to-many link between security incidents and events';

-- ============================================================================
-- End of 043_security_operations.sql
-- ============================================================================



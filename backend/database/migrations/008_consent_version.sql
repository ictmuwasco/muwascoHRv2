-- Consent Versioning
-- Adds consent_version column to user_consents for versioned consent tracking.
-- Place: backend/database/migrations/008_consent_version.sql
--
-- IDEMPOTENT: the column and unique key are already declared by
-- 0000_baseline_schema.sql, so each step is guarded against information_schema
-- (safe on a fresh baseline database as well as on a legacy database).

-- Add consent_version column to user_consents (default '1.0') when missing
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_consents'
               AND COLUMN_NAME = 'consent_version');
SET @sql := IF(@col = 0,
    'ALTER TABLE `user_consents` ADD COLUMN `consent_version` VARCHAR(10) NOT NULL DEFAULT ''1.0'' AFTER `national_id`',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Prevent duplicate consent records for the same user and version
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_consents'
               AND INDEX_NAME = 'uk_user_consent_version');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `user_consents` ADD UNIQUE KEY `uk_user_consent_version` (`user_id`, `consent_version`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill existing consent records to the current version
UPDATE `user_consents`
  SET `consent_version` = '1.0'
  WHERE `consent_version` IS NULL OR `consent_version` = '';
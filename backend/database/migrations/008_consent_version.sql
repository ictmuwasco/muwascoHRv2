-- Consent Versioning
-- Adds consent_version column to user_consents for versioned consent tracking.
-- Place: backend/database/migrations/008_consent_version.sql

-- Add consent_version column to user_consents (default '1.0')
ALTER TABLE `user_consents`
  ADD COLUMN `consent_version` VARCHAR(10) NOT NULL DEFAULT '1.0'
  AFTER `national_id`;

-- Prevent duplicate consent records for the same user and version
ALTER TABLE `user_consents`
  ADD UNIQUE KEY `uk_user_consent_version` (`user_id`, `consent_version`);

-- Backfill existing consent records to the current version
UPDATE `user_consents`
  SET `consent_version` = '1.0'
  WHERE `consent_version` IS NULL OR `consent_version` = '';
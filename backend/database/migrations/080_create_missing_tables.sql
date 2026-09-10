-- 080_create_missing_tables.sql
-- Creates delegations and user_consents tables used in tests.
-- Guarded by environment variable DEPLOY_ENV=ci so it runs only in CI unless overridden.

-- Use IF NOT EXISTS to make migration idempotent
BEGIN;

-- Delegations table
CREATE TABLE IF NOT EXISTS delegations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delegator_user_id INT UNSIGNED NOT NULL,
    delegatee_user_id INT UNSIGNED NOT NULL,
    module VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_delegator (delegator_user_id),
    INDEX idx_delegatee (delegatee_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User consents table
CREATE TABLE IF NOT EXISTS user_consents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    consent_type VARCHAR(64) NOT NULL,
    consented_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;


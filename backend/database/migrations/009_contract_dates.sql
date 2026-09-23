-- Contract Dates Migration
-- Adds contract_start_date and contract_end_date to employees table
-- Run: php backend/database/run_migration.php 009_contract_dates.sql
--
-- IDEMPOTENT: columns/index are already declared by 0000_baseline_schema.sql,
-- so each step is guarded against information_schema.

-- Add contract_start_date when missing
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
               AND COLUMN_NAME = 'contract_start_date');
SET @sql := IF(@col = 0,
    'ALTER TABLE employees ADD COLUMN contract_start_date DATE NULL DEFAULT NULL COMMENT ''Start date for contract employees''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add contract_end_date when missing
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
               AND COLUMN_NAME = 'contract_end_date');
SET @sql := IF(@col = 0,
    'ALTER TABLE employees ADD COLUMN contract_end_date DATE NULL DEFAULT NULL COMMENT ''End date for contract employees''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index for contract expiry queries
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
               AND INDEX_NAME = 'idx_employees_contract_dates');
SET @sql := IF(@idx = 0, 'CREATE INDEX idx_employees_contract_dates ON employees (contract_end_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
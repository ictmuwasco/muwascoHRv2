-- Contract Dates Migration
-- Adds contract_start_date and contract_end_date to employees table
-- Run: php backend/database/run_migration.php 009_contract_dates.sql

-- Add contract date columns
ALTER TABLE employees
    ADD COLUMN contract_start_date DATE NULL DEFAULT NULL
        COMMENT 'Start date for contract employees',
    ADD COLUMN contract_end_date DATE NULL DEFAULT NULL
        COMMENT 'End date for contract employees';

-- Index for contract expiry queries
CREATE INDEX idx_employees_contract_dates
    ON employees (contract_end_date);
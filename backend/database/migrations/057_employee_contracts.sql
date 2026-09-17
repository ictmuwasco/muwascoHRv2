-- Employee Contracts History Migration
-- Creates a table to track all contract periods for employees
-- This allows recording contracts through renewals until permanent employment
-- Run: php backend/database/run_migration.php 057_employee_contracts.sql

CREATE TABLE IF NOT EXISTS employee_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'FK to employees table',
    contract_number INT NOT NULL COMMENT 'Sequential contract number for this employee (1, 2, 3...)',
    contract_name VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional contract name/title',
    start_date DATE NOT NULL COMMENT 'Contract start date',
    end_date DATE NULL DEFAULT NULL COMMENT 'Contract end date (NULL if ongoing)',
    employment_type VARCHAR(20) NOT NULL DEFAULT 'contract' COMMENT 'Type of employment: contract, permanent',
    employee_type VARCHAR(20) NULL DEFAULT NULL COMMENT 'Employee type: officer, staff, etc.',
    designation VARCHAR(255) NULL DEFAULT NULL COMMENT 'Job title at time of contract',
    department_id INT NULL DEFAULT NULL COMMENT 'Department at time of contract',
    section_id INT NULL DEFAULT NULL COMMENT 'Section at time of contract',
    renewed_from_contract_id INT NULL DEFAULT NULL COMMENT 'If renewed, which previous contract this extends',
    renewal_date DATETIME NULL DEFAULT NULL COMMENT 'When this contract was renewed',
    created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    INDEX idx_employee_contracts_employee (employee_id),
    INDEX idx_employee_contracts_dates (start_date, end_date),
    INDEX idx_employee_contracts_employment_type (employment_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks all contract periods for employees through renewals';

-- Add a column to track total contract count on employees table.
-- MySQL does not support ADD COLUMN IF NOT EXISTS, so this migration must
-- only be run once (the migrations table tracks it). Existing deployments
-- that already have the column should skip by modifying the row manually
-- or running the migration runner which tracks applied migrations.
ALTER TABLE employees 
    ADD COLUMN total_contracts INT NULL DEFAULT 0 COMMENT 'Total number of contracts assigned to this employee';
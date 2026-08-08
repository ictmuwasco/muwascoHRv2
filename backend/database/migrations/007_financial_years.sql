-- Financial Years Table
-- Stores financial year periods (July 1 - June 30)
-- Place: backend/database/migrations/007_financial_years.sql

CREATE TABLE IF NOT EXISTS financial_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(20) NOT NULL COMMENT 'e.g., 2024/25',
    start_date DATE NOT NULL COMMENT 'Usually July 1',
    end_date DATE NOT NULL COMMENT 'Usually June 30',
    total_days INT NOT NULL COMMENT 'Total days in the financial year',
    is_active TINYINT(1) DEFAULT 0 COMMENT '1 = active, 0 = inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Unique constraint on year_name
    UNIQUE KEY uk_year_name (year_name),
    
    -- Index for date range queries
    INDEX idx_dates (start_date, end_date),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employee Leave Balances Table
-- Stores leave allocations per employee per financial year
CREATE TABLE IF NOT EXISTS employee_leave_balances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    leave_type_id INT NOT NULL,
    financial_year_id INT NOT NULL,
    allocated_days DECIMAL(5,2) NOT NULL COMMENT 'Days allocated for this FY',
    brought_forward_days DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Days brought forward from previous FY',
    used_days DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Days already used',
    accumulated_days DECIMAL(5,2) NOT NULL COMMENT 'Total accumulated (allocated + brought_forward)',
    remaining_days DECIMAL(5,2) NOT NULL COMMENT 'Days remaining (accumulated - used)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Foreign keys
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
    FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE CASCADE,
    
    -- Unique constraint: one balance record per employee/leave_type/financial_year
    UNIQUE KEY uk_employee_leave_fy (employee_id, leave_type_id, financial_year_id),
    
    -- Indexes for faster lookups
    INDEX idx_employee (employee_id),
    INDEX idx_financial_year (financial_year_year_id),
    INDEX idx_leave_type (leave_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
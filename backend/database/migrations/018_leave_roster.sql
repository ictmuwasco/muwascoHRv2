-- Leave Roster Table
-- Stores planned annual leave per employee per financial year
-- Place: backend/database/migrations/018_leave_roster.sql

-- Create leave_roster table if it does not already exist (fully-formed with all keys)
CREATE TABLE IF NOT EXISTS leave_roster (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    financial_year_id INT NOT NULL,
    scheduled_month VARCHAR(10) NULL COMMENT 'e.g., July',
    scheduled_year INT NULL COMMENT 'e.g., 2025',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_employee_financial_year (employee_id, financial_year_id),
    KEY idx_financial_year (financial_year_id),
    KEY idx_employee (employee_id),
    KEY idx_scheduled_month (scheduled_month),
    KEY idx_scheduled_year (scheduled_year),
    CONSTRAINT fk_leave_roster_financial_year FOREIGN KEY (financial_year_id) REFERENCES financial_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill financial_year_id for legacy records (no-op if column already populated)
UPDATE leave_roster lr
JOIN financial_years fy
    ON fy.start_date <= CONCAT(lr.scheduled_year, '-12-31')
    AND fy.end_date >= CONCAT(lr.scheduled_year, '-01-01')
SET lr.financial_year_id = fy.id
WHERE lr.financial_year_id IS NULL OR lr.financial_year_id = 0;
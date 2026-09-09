-- Add delegate columns to leave_applications (idempotent - MariaDB 10.4 compatible)
-- Check if columns exist before adding

-- Add delegate_emp_id if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_applications'
      AND column_name = 'delegate_emp_id'
);
SET @add_col_sql = IF(@col_exists = 0,
    'ALTER TABLE leave_applications ADD COLUMN delegate_emp_id INT NULL AFTER dept_head_emp_id',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @add_col_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add delegate_role if it doesn't exist
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'leave_applications'
      AND column_name = 'delegate_role'
);
SET @add_col_sql = IF(@col_exists = 0,
    'ALTER TABLE leave_applications ADD COLUMN delegate_role VARCHAR(50) NULL AFTER delegate_emp_id',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @add_col_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key for delegate employee only if it does not already exist.
-- (MySQL has no IF NOT EXISTS for constraints, so we guard via information_schema.)
SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE()
      AND table_name = 'leave_applications'
      AND constraint_name = 'fk_leave_delegate_emp'
);
SET @fk_sql = IF(@constraint_exists = 0,
    'ALTER TABLE leave_applications ADD CONSTRAINT fk_leave_delegate_emp FOREIGN KEY (delegate_emp_id) REFERENCES employees(id) ON DELETE SET NULL',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

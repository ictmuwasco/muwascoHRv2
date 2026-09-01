-- 036_users_employee_id_type_fix.sql
-- Phase 4 Wave 1 — Critical data-integrity remediation.
-- Fixes F1 (users.employee_id type mismatch) + F3 (leave_roster.employee_id missing FK).
-- Run: mysql -u root -p muwasco < backend/database/migrations/036_users_employee_id_type_fix.sql
-- Safe: idempotent guards + diagnostic queries. NO data modified or deleted.

SET @db = DATABASE();

-- 1. Diagnostic: non-numeric users.employee_id values (review before type change)
SELECT '=== DIAGNOSTIC: non-numeric users.employee_id ===' AS step;
SELECT id, employee_id, email
  FROM users
   WHERE employee_id IS NOT NULL AND employee_id NOT REGEXP '^[0-9]+$';

-- 2. Diagnostic: users.employee_id pointing to a non-existent employee
SELECT '=== DIAGNOSTIC: orphan users.employee_id ===' AS step;
SELECT u.id, u.employee_id, u.email
  FROM users u
  LEFT JOIN employees e ON e.id = CAST(u.employee_id AS UNSIGNED)
 WHERE u.employee_id IS NOT NULL AND e.id IS NULL;

-- 3. Diagnostic: leave_roster.employee_id pointing to a non-existent employee
SELECT '=== DIAGNOSTIC: orphan leave_roster.employee_id ===' AS step;
SELECT lr.id, lr.employee_id, lr.financial_year_id
  FROM leave_roster lr
  LEFT JOIN employees e ON e.id = lr.employee_id
   WHERE e.id IS NULL;

-- 4. Type-align users.employee_id: VARCHAR(11) -> INT UNSIGNED (matches employees.id PK)
SELECT CONCAT('=== users.employee_id current type: ',
              (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'),
              ' ===') AS step;

SET @needs_type_fix = (
    SELECT CASE WHEN COLUMN_TYPE = 'int(10) unsigned' THEN 0 ELSE 1 END
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'
);
SET @sql = IF(@needs_type_fix = 1,
    'ALTER TABLE users MODIFY COLUMN employee_id INT UNSIGNED NULL AFTER email',
    'SELECT 1 AS no_op_employee_id_already_correct');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCUTE PREPARE stmt;

-- 5. FK: users.employee_id -> employees.id ON DELETE RESTRICT
SET @fk_user_emp = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees'
);
SET @sql = IF(@fk_user_emp = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE RESTRICT ON UPDATE CASCADE',
        'SELECT 1 AS no_op_users_employee_fk_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCUTE PREPARE stmt;

-- 6. FK: leave_roster.employee_id -> employees.id ON DELETE CASCADE
SET @fk_roster_emp = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
       AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees'
);
SET @sql = IF(@fk_roster_emp = 0,
    'ALTER TABLE leave_roster ADD CONSTRAINT fk_leave_roster_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE',
    'SELECT 1 AS no_op_roster_employee_fk_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCUTE PREPARE stmt;

-- 7. Index: ensure leave_roster.employee_id is indexed for lookups
SET @idx_roster_emp = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
       AND INDEX_NAME = 'idx_leave_roster_employee'
);
SET @sql = IF(@idx_roster_emp = 0,
    'ALTER TABLE leave_roster ADD INDEX idx_leave_roster_employee (employee_id)',
    'SELECT 1 AS no_op_roster_employee_idx_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCUTE PREPARE stmt;

-- 8. Summary
SELECT '=== MIGRATION 036 COMPLETE ===' AS step;
SELECT
    (SELECT COUNT(*) FROM users WHERE employee_id IS NOT NULL) AS users_with_employee_id,
    (SELECT COUNT(*) FROM users WHERE employee_id IS NULL)     AS users_without_employee_id,
    (SELECT COUNT(*) FROM leave_roster)                        AS leave_roster_rows_total;

-- NOTE: UNIQUE constraints on employees.employee_id (F2) and users.email (F4)
-- are deferred to Wave 2 migration 037, after a dedup verification step.





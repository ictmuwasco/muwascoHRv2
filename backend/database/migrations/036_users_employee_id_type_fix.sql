-- 036_users_employee_id_type_fix.sql
-- Phase 4 Wave 1 — Critical data-integrity remediation.
-- Fixes F1 + F2 + F3 against the LIVE muwasco schema:
--   F1: users.employee_id is VARCHAR(11) but employees.employee_id is
--       VARCHAR(50). Type must match for a FOREIGN KEY to be enforceable.
--       Fix: widen users.employee_id to VARCHAR(50) and add the FK on the
--       business number (employees.employee_id). All 193 user rows keep
--       their existing business-number links (verified: 0 orphans, 0 rows
--       over 11 chars, non-numeric codes like MOW12/CMT001 preserved).
--   F2: employees.employee_id (business number) had NO unique index.
--       Fix: add UNIQUE KEY uk_employees_employee_id. The FK below REQUIRES
--       this index anyway. Verified data: 0 duplicates, 0 NULLs -> safe.
--   F3: leave_roster.employee_id had NO foreign-key constraint referencing
--       employees.id. Fix: add fk_leave_roster_employee ON DELETE CASCADE.
--       leave_roster already has idx_employee, so no extra index is needed.
-- Safety: idempotent information_schema guards + diagnostic SELECTs.
--         NO data is modified, converted, or deleted by this migration.
-- Run:   mysql -u root -p muwasco < backend/database/migrations/036_users_employee_id_type_fix.sql
-- Or:    php backend/database/run_migration_036.php

SET @db = DATABASE();

-- ----------------------------------------------------------------
-- 1. Diagnostic: users.employee_id with no matching employees.employee_id
--    (business-number join). Expected: 0 rows.
-- ----------------------------------------------------------------
SELECT '=== DIAGNOSTIC: orphan users.employee_id (join on business number) ===' AS step;
SELECT u.id, u.employee_id, u.email
  FROM users u
  LEFT JOIN employees e ON e.employee_id = u.employee_id
 WHERE u.employee_id IS NOT NULL
   AND e.id IS NULL;

-- ----------------------------------------------------------------
-- 2. Diagnostic: employees.employee_id duplicates (would break UNIQUE + FK).
--    Expected: 0 rows.
-- ----------------------------------------------------------------
SELECT '=== DIAGNOSTIC: duplicate employees.employee_id ===' AS step;
SELECT employee_id, COUNT(*) AS dup_count
  FROM employees
 WHERE employee_id IS NOT NULL
 GROUP BY employee_id
 HAVING dup_count > 1;

-- ----------------------------------------------------------------
-- 3. Diagnostic: leave_roster.employee_id with no matching employees.id.
--    Expected: 0 rows.
-- ----------------------------------------------------------------
SELECT '=== DIAGNOSTIC: orphan leave_roster.employee_id (join on employees.id) ===' AS step;
SELECT lr.id, lr.employee_id, lr.financial_year_id
  FROM leave_roster lr
  LEFT JOIN employees e ON e.id = lr.employee_id
 WHERE lr.employee_id IS NOT NULL
   AND e.id IS NULL;

-- ----------------------------------------------------------------
-- 4. F1a: Align users.employee_id VARCHAR(11) -> VARCHAR(50) to match
--    employees.employee_id exactly (enables the FK on the business number).
-- ----------------------------------------------------------------
SELECT CONCAT('=== users.employee_id current type: ',
              (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'),
              ' ===') AS step;

SET @needs_widen = (
    SELECT CASE WHEN COLUMN_TYPE = 'varchar(50)' THEN 0 ELSE 1 END
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'employee_id'
);
SET @sql = IF(@needs_widen = 1,
    'ALTER TABLE users MODIFY COLUMN employee_id VARCHAR(50) NULL AFTER email',
    'SELECT 1 AS no_op_users_employee_id_already_varchar50');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 5. F2: Add UNIQUE KEY on employees.employee_id (business number).
--    Required by the FK added in step 6; data verified clean (0 dupes).
-- ----------------------------------------------------------------
SET @uk_emp_id = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
       AND INDEX_NAME   = 'uk_employees_employee_id'
);
SET @sql = IF(@uk_emp_id = 0,
    'ALTER TABLE employees ADD UNIQUE KEY uk_employees_employee_id (employee_id)',
    'SELECT 1 AS no_op_employees_employee_id_uk_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 6. F1b: FK users.employee_id -> employees.employee_id
--    ON DELETE RESTRICT ON UPDATE CASCADE.
--    (A user must always resolve to a real employee business number;
--     HR deactivates the user via is_active instead of deleting.)
-- ----------------------------------------------------------------
SET @fk_user_emp = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME  = 'employee_id'
       AND REFERENCED_TABLE_NAME = 'employees'
);
SET @sql = IF(@fk_user_emp = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT 1 AS no_op_users_employee_fk_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 7. F3: FK leave_roster.employee_id -> employees.id ON DELETE CASCADE.
--    leave_roster.employee_id already uses index idx_employee, so no
--    extra index is needed for this constraint.
-- ----------------------------------------------------------------
SET @fk_roster_emp = (
    SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
       AND COLUMN_NAME  = 'employee_id'
       AND REFERENCED_TABLE_NAME = 'employees'
);
SET @sql = IF(@fk_roster_emp = 0,
    'ALTER TABLE leave_roster ADD CONSTRAINT fk_leave_roster_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE',
    'SELECT 1 AS no_op_roster_employee_fk_exists');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 8. Summary
-- ----------------------------------------------------------------
SELECT '=== MIGRATION 036 COMPLETE ===' AS step;
SELECT
    (SELECT COUNT(*) FROM users WHERE employee_id IS NOT NULL) AS users_with_employee_id,
    (SELECT COUNT(*) FROM employees WHERE employee_id IS NULL) AS employees_without_business_id,
    (SELECT COUNT(*) FROM leave_roster)                        AS leave_roster_rows_total;

-- ====================================================================
-- NOTE: users.email UNIQUE (F4) and users.employee_id UNIQUE are left to
--       Wave 2 (migration 037) because users.employee_id currently has
--       duplicates (employee_id 129 -> 3 users) that need app-level
--       resolution first. Docs: docs/PHASE4_REPORT.md
-- ====================================================================
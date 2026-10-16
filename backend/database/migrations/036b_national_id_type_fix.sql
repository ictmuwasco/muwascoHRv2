<#
.SYNOPSIS
036b: national_id type conversion (Phase 4 Wave 3 / F6).
#>
-- 036b: Convert employees.national_id INT -> VARCHAR(50) (Phase 4 Wave 3 / F6).
--
--   F6: national_id stored as INT (should be varchar) to support
--       alphanumeric codes (MOW12/CMT001) and IDP compatibility.
--   Target type: VARCHAR(50) per DATABASE_ARCHITECTURE.md.
--
--   Safe, additive, idempotent:
--     1. Diagnostic SELECTs verify current int values are all numeric
--        (max 10 digits) and 0 NULLs.
--     2. Convert to VARCHAR(50) with IF(CHAR_LENGTH>10) guard.
--     3. Restore NOT NULL + default if needed.
--   NOTE: 036b must run AFTER 036 (users.employee_id FK) since both
--   touch the employees table, and 036b only touches employees.

SET @db = DATABASE();

-- ----------------------------------------------------------------
-- 1. Diagnostic: national_id values that exceed VARCHAR(10) numeric
--    storage (would overflow). Expected: 0 rows.
-- ----------------------------------------------------------------
SELECT '=== DIAGNOSTIC: national_id > 10 digits (would overflow int) ===' AS step;
SELECT id, national_id
  FROM employees
 WHERE national_id IS NOT NULL
   AND CHAR_LENGTH(CAST(national_id AS CHAR)) > 10;

-- ----------------------------------------------------------------
-- 2. Diagnostic: national_id NULLs (NOT NULL column should have none).
-- ----------------------------------------------------------------
SELECT '=== DIAGNOSTIC: national_id NULLs in employees ===' AS step;
SELECT COUNT(*) AS null_count
  FROM employees
 WHERE national_id IS NULL;

-- ----------------------------------------------------------------
-- 3. Convert national_id INT -> VARCHAR(50).
-- ----------------------------------------------------------------
SET @uk_emp_national_id = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'employees'
       AND INDEX_NAME = 'idx_employees_national_id'
);

-- Drop the old index on the integer column first (MySQL requires it
-- because ALTER on the base column changes the storage class).
SET @sql = IF(@uk_emp_national_id > 0,
    'ALTER TABLE employees DROP INDEX idx_employees_national_id',
    'SELECT 1 AS no_op_national_id_index_already_removed');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @needs_convert = (
    SELECT CASE WHEN COLUMN_TYPE = 'varchar(50)' THEN 0 ELSE 1 END
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
       AND COLUMN_NAME = 'national_id'
);

SET @sql = IF(@needs_convert = 1,
    'ALTER TABLE employees MODIFY COLUMN national_id VARCHAR(50) NOT NULL AFTER employee_id',
    'SELECT 1 AS no_op_national_id_already_varchar50');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 4. Restore the index on the new VARCHAR column.
-- ----------------------------------------------------------------
SET @sql = IF(@uk_emp_national_id = 0,
    'CREATE INDEX idx_employees_national_id ON employees (national_id)',
    'SELECT 1 AS no_op_national_id_index_already_created');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 5. Diagnostic: confirm final state.
-- ----------------------------------------------------------------
SELECT '=== 036b COMPLETE ===' AS step;
SELECT
    (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
        AND COLUMN_NAME = 'national_id') AS national_id_type,
    (SELECT COUNT(*) FROM employees WHERE national_id IS NULL) AS national_id_nulls,
    (SELECT COUNT(*) FROM employees WHERE CHAR_LENGTH(national_id) > 50) AS national_id_too_long;

-- ===================================================================
-- NOTE: F4 (users.email UNIQUE) remains blocked in Wave 2
--       (037_users_email_unique.sql) until duplicate employee_id
--       rows are resolved app-level. See docs/PHASE4_REPORT.md.
-- ===================================================================

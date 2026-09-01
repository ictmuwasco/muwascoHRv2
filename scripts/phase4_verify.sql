-- phase4_verify.sql
-- Phase 4 — Read-only verification queries.
-- Run: mysql -u root -p muwasco < scripts/phase4_verify.sql
-- All queries are SELECT — safe to execute against production.
-- NOTE: live DB is MariaDB (MySQL-compatible information_schema).

-- K1: Verify users.employee_id is now VARCHAR(50) matching employees.employee_id
SELECT 'K1: users.employee_id type (expect varchar(50))' AS check_name;
SELECT COLUMN_NAME, COLUMN_TYPE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME  = 'employee_id';

-- K1b: Verify employees.employee_id type (expect varchar(50))
SELECT 'K1b: employees.employee_id type (expect varchar(50))' AS check_name;
SELECT COLUMN_NAME, COLUMN_TYPE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
   AND COLUMN_NAME  = 'employee_id';

-- K2: Verify UNIQUE KEY uk_employees_employee_id exists on employees
SELECT 'K2: UNIQUE uk_employees_employee_id exists on employees' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'
   AND INDEX_NAME = 'uk_employees_employee_id'
   AND NON_UNIQUE = 0;

-- K3: Verify users.employee_id -> employees.employee_id FK exists
SELECT 'K3: FK fk_users_employee exists (business number link)' AS check_name;
SELECT COUNT(*) AS fk_count
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees';

-- K4: Verify leave_roster.employee_id -> employees.id FK exists
SELECT 'K4: FK fk_leave_roster_employee exists on leave_roster.employee_id' AS check_name;
SELECT COUNT(*) AS fk_count
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
   AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees';

-- K4b: Verify leave_roster still has its employee index (idx_employee)
SELECT 'K4b: idx_employee exists on leave_roster' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
   AND INDEX_NAME = 'idx_employee';

-- K5: Scan for duplicate employee_id values (Wave 2 cleanup target)
SELECT 'K5: Duplicate employee_id values (Wave 2 cleanup target)' AS check_name;
SELECT employee_id, COUNT(*) AS dup_count
  FROM employees WHERE employee_id IS NOT NULL
 GROUP BY employee_id HAVING dup_count > 1;

-- K6: Scan for duplicate emails (Wave 2 cleanup target)
SELECT 'K6: Duplicate email values (Wave 2 cleanup target)' AS check_name;
SELECT email, COUNT(*) AS dup_count
  FROM users WHERE email IS NOT NULL
 GROUP BY email HAVING dup_count > 1;

-- K6b: Scan for duplicate users.employee_id (Wave 2 cleanup target)
SELECT 'K6b: Duplicate users.employee_id values (Wave 2 cleanup target)' AS check_name;
SELECT employee_id, COUNT(*) AS dup_count
  FROM users WHERE employee_id IS NOT NULL
 GROUP BY employee_id HAVING dup_count > 1;

-- K7: Verify audit_logs indexes
SELECT 'K7: audit_logs indexes for performance' AS check_name;
SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
 GROUP BY INDEX_NAME ORDER BY INDEX_NAME;

-- K8: Verify role_permissions unique constraint
SELECT 'K8: role_permissions UNIQUE(role, module, action)' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'role_permissions'
   AND INDEX_NAME = 'uk_role_module_action'
   AND NON_UNIQUE = 0;

-- K9: Verify user_page_permissions unique constraint
SELECT 'K9: user_page_permissions UNIQUE(user_id, module, action)' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_page_permissions'
   AND INDEX_NAME = 'uq_user_module_action'
   AND NON_UNIQUE = 0;

-- K10: Verify attendance idempotency constraint
SELECT 'K10: attendance UNIQUE(employee_id, attendance_date)' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
   AND INDEX_NAME = 'uk_attendance_employee_date'
   AND NON_UNIQUE = 0;

-- K11: Summary counts
SELECT 'K11: Summary counts' AS check_name;
SELECT
    (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL) AS total_fks,
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0) AS total_unique_keys,
    (SELECT COUNT(*) FROM users)                           AS user_count,
    (SELECT COUNT(*) FROM employees)                       AS employee_count,
    (SELECT COUNT(*) FROM attendance)                      AS attendance_count,
    (SELECT COUNT(*) FROM leave_applications)              AS leave_app_count,
    (SELECT COUNT(*) FROM leave_roster)                    AS leave_roster_count,
    (SELECT COUNT(*) FROM meetings)                        AS meetings_count,
    (SELECT COUNT(*) FROM meeting_minutes)                 AS meeting_minutes_count,
    (SELECT COUNT(*) FROM audit_logs)                      AS audit_log_count;

SELECT '=== VERIFICATION COMPLETE ===' AS step;





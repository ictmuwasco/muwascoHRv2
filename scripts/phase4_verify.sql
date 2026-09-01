-- phase4_verify.sql
-- Phase 4 — Read-only verification queries.
-- Run: mysql -u root -p muwasco < scripts/phase4_verify.sql
-- All queries are SELECT — safe to execute against production.

-- K1: Verify users.employee_id is now INT UNSIGNED
SELECT 'K1: users.employee_id type (expect int(10) unsigned)' AS check_name;
SELECT COLUMN_NAME, COLUMN_TYPE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME  = 'employee_id';

-- K2: Verify users.employee_id -> employees.id FK exists
SELECT 'K2: FK fk_users_employee exists on users.employee_id' AS check_name;
SELECT COUNT(*) AS fk_count
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees';

-- K3: Verify leave_roster.employee_id -> employees.id FK exists
SELECT 'K3: FK fk_leave_roster_employee exists on leave_roster.employee_id' AS check_name;
SELECT COUNT(*) AS fk_count
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
   AND COLUMN_NAME = 'employee_id' AND REFERENCED_TABLE_NAME = 'employees';

-- K4: Verify idx_leave_roster_employee index exists
SELECT 'K4: idx_leave_roster_employee exists' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leave_roster'
      AND INDEX_NAME = 'idx_leave_roster_employee';

-- K5: Scan for duplicate employee_id values (pre-Wave-2)
SELECT 'K5: Duplicate employee_id values (Wave 2 cleanup target)' AS check_name;
SELECT employee_id, COUNT(*) AS dup_count
  FROM employees WHERE employee_id IS NOT NULL
 GROUP BY employee_id HAVING dup_count > 1;

-- K6: Scan for duplicate emails (pre-Wave-2)
SELECT 'K6: Duplicate email values (Wave 2 cleanup target)' AS check_name;
SELECT email, COUNT(*) AS dup_count
  FROM users WHERE email IS NOT NULL
 GROUP BY email HAVING dup_count > 1;

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
   AND INDEX_NAME = 'uk_role_permissions_role_module_action';

-- K9: Verify user_page_permissions unique constraint
SELECT 'K9: user_page_permissions UNIQUE(user_id, module, action)' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_page_permissions'
   AND INDEX_NAME = 'uk_user_page_permissions_user_module_action';

-- K10: Verify attendance idempotency constraint
SELECT 'K10: attendance UNIQUE(employee_id, attendance_date)' AS check_name;
SELECT COUNT(*) AS idx_count
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance'
   AND CONSTRAINT_NAME LIKE '%uniq%';

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





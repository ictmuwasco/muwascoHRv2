-- ===========================================================================
-- 103_additive_query_indexes.sql
--
-- PURELY ADDITIVE. Creates indexes only. Drops nothing, mutates no data,
-- removes no column. This migration cannot lose data.
--
-- NUMBERING: this file is 103, not 100, because migration 100
-- (100_repair_ai_knowledge_base.sql) already exists and is applied.
--
-- ENGINE: MariaDB 10.4 (identical to CI and production).
-- IDEMPOTENT: each block is guarded by information_schema.statistics, so
-- re-running is a no-op.
-- ROLLBACK: ALTER TABLE <table> DROP INDEX <index_name>
--
-- WHY THESE INDEXES
--
-- Each access path below was measured with EXPLAIN BEFORE this migration and
-- was a full table scan (type=ALL, key=NULL):
--
--   users WHERE email = somevalue
--       -> ALL, key=NULL, 194 rows
--   employees WHERE department_id=1 AND employee_status=active
--       -> ALL, key=NULL, 157 rows
--   employees WHERE national_id=1
--       -> ALL, key=NULL, 157 rows
--   employee_leave_balances WHERE employee_id=1 AND financial_year_id=30
--                             AND leave_type_id=1
--       -> ALL, key=NULL, 2692 rows
--
-- None are speculative: each column is a real WHERE/JOIN predicate. The
-- referencing call site is named per index below.
-- ===========================================================================

-- 1. users(email)
--    Login path: the hottest single-column lookup in the application, and it
--    was completely unindexed (users had only PRIMARY and the employee_id FK
--    index).
--    NOT UNIQUE on purpose: the data audit found one duplicate group,
--    robertthuku924@gmail.com with 3 rows, so a UNIQUE constraint would fail
--    to apply. A non-unique index still removes the scan.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
       AND INDEX_NAME='idx_users_email')=0,
  'ALTER TABLE `users` ADD INDEX `idx_users_email` (`email`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. users(login_identifier)
--    Alternate staff-number login key used by AuthService when a user types an
--    identifier instead of an email. 165 of 166 rows are currently NULL, so
--    the index stays small. There are 0 duplicate non-null values, so UNIQUE
--    would be viable, but the business rule is unconfirmed and nullable
--    UNIQUE semantics differ between MySQL and MariaDB, so index only.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
       AND INDEX_NAME='idx_users_login_identifier')=0,
  'ALTER TABLE `users` ADD INDEX `idx_users_login_identifier` (`login_identifier`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. users(is_active, role)
--    Composite for the combined predicate used by
--    DelegationService.php:2250 and FinancialYearService.php:430, which both
--    filter on role and is_active together. PolicyService.php:684 filters
--    is_active alone, which this composite also serves. Two single-column
--    indexes would cost two extra writes per user update and serve the
--    combined predicate worse than one composite.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
       AND INDEX_NAME='idx_users_active_role')=0,
  'ALTER TABLE `users` ADD INDEX `idx_users_active_role` (`is_active`, `role`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. employees(department_id)
--    Org-scope filter used across the leave, appraisal and workplan modules.
--    AppraisalWorkflowService.php:848 and :892 select on employee_status and
--    department_id and employee_type. LeaveRepository.php:283 resolves
--    employees by department_id. ReportsController.php:302 joins employees to
--    departments and sections. The table previously carried only PRIMARY(id),
--    UNIQUE uk_employees_employee_id and idx_employees_contract_dates, so
--    there was nothing at all on the org tree.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_department')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_department` (`department_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 5. employees(section_id)
--    Same org-tree pattern one level down. WorkplanService.php:252 filters
--    subsections by section_id, and the section-scoped workplan and
--    attendance views filter employees by section_id.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_section')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_section` (`section_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 6. employees(subsection_id)
--    Deepest org level, used by the sectional workplan and objective views.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_subsection')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_subsection` (`subsection_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 7. employees(office_id)
--    Attendance and reporting are office-scoped: the employee list filters on
--    office_id and ReportsController joins it.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_office')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_office` (`office_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 8. employees(employee_status, department_id)
--    Composite matching the real predicate shape in
--    AppraisalWorkflowService.php:848 and :892. employee_status has very low
--    cardinality so it is the correct LEADING column, with department_id
--    second. This is what removes the scan for the "who heads this
--    department" lookup that runs on every appraisal cycle and every
--    duty-cover delegation.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_status_dept')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_status_dept` (`employee_status`, `department_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 9. employees(email)
--    Employee search and HR directory contact lookup.
--    NOT UNIQUE: no business rule states employee emails are unique (the
--    schema has no such constraint today) and employees.email is nullable.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_email')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_email` (`email`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 10. employees(national_id)
--     The duplicate guard on employee create and import,
--     EmployeeRepository.php:460, counts rows by national_id. This runs on
--     every create attempt. Data audit: 0 duplicates today, so UNIQUE would
--     be valid, but national_id is declared INT and changing it to VARCHAR
--     is separate, higher-risk work, so this indexes what exists today.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employees'
       AND INDEX_NAME='idx_employees_national_id')=0,
  'ALTER TABLE `employees` ADD INDEX `idx_employees_national_id` (`national_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 11. employee_leave_balances(employee_id, financial_year_id, leave_type_id)
--     The leave balance screen and the balance-deduction path both look up
--     one employee balance for one leave type in one financial year. The
--     table held 3,072 rows and nothing but the primary key, so every
--     balance read scanned the entire table.
--     The predicate is all-equality so any prefix order works; this order is
--     chosen to match how a balance is always addressed.
--     Data audit: 0 duplicate groups on this triple, so it is a valid UNIQUE
--     candidate, but it is deliberately not added here (see closing note).
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_leave_balances'
       AND INDEX_NAME='idx_elb_emp_fy_type')=0,
  'ALTER TABLE `employee_leave_balances` ADD INDEX `idx_elb_emp_fy_type` (`employee_id`, `financial_year_id`, `leave_type_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 12. employee_leave_balances(financial_year_id)
--     Financial-year rollover and per-FY reporting aggregate the whole table
--     by FY; a leading FY index serves that without a scan.
SET @s := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='employee_leave_balances'
       AND INDEX_NAME='idx_elb_fy')=0,
  'ALTER TABLE `employee_leave_balances` ADD INDEX `idx_elb_fy` (`financial_year_id`)', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ===========================================================================
-- DELIBERATELY NOT DONE IN THIS MIGRATION
--
-- UNIQUE constraints. The pre-flight duplicate checks were run and are
-- recorded here so the result is auditable:
--   users.email              -> 1 duplicate group (3 rows sharing one address)
--   users.login_identifier   -> 0 duplicates (UNIQUE viable, not needed yet)
--   employees.national_id    -> 0 duplicates (UNIQUE viable, type change first)
--   employee_leave_balances  -> 0 duplicates (UNIQUE viable, needs sign-off)
-- One FAILS, so no UNIQUE is applied here. Adding UNIQUE is a separate
-- migration, after the duplicate accounts are reconciled.
--
-- Index removal. audit_logs has single-column indexes that are leftmost
-- prefix covered by composites, and attendance has overlapping date and
-- employee indexes. Removing them needs production EXPLAIN evidence per
-- query path, so it is deferred to its own migration.
--
-- Column removal, data mutation, DELETE. None in this file.
-- ===========================================================================
-- End of 103_additive_query_indexes.sql
-- ===========================================================================


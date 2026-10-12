-- ============================================================================
-- 109_backfill_users_role_from_employee_type.sql
--
-- WHY
--   users.role is the only value RBAC resolves
--   (AuthorizationService::resolveUserRole). It is derived from
--   employees.employee_type when an account is created, but employee edits
--   historically never wrote it back, so accounts whose employee_type was
--   changed - or whose role column was never populated (legacy rows,
--   widened enum default, junk values such as the retired "hr" key) - ended
--   up with an empty or unknown role. An empty/unknown role denies every
--   permission, and the SPA then shows "Access denied, required
--   dashboard:view" on every page.
--
-- WHAT
--   For every users row whose role is NULL, empty, or outside the catalog,
--   re-derive it from the linked employee_type using exactly the same
--   mapping as EmployeeService::mapEmployeeTypeToRole:
--     super_admin/managing_director/bod_chairman/hr_manager/dept_head/
--     section_head/sub_section_head/manager/officer -> themselves
--     anything else (including NULL employee_type) -> employee
--
--   Statement 1 uses the authoritative link (users.employee_id FK ->
--   employees.employee_id). Statement 2 covers legacy accounts whose
--   employee_id link was never written but whose email matches exactly one
--   employee.
--
--   Rows whose role is already a valid catalog key - including the legacy
--   "admin" alias that RBAC normalizes to super_admin - are NOT touched, so
--   deliberately assigned roles survive. Users with no employee link keep
--   deny-by-default and must be assigned manually in Settings.
--
-- IDEMPOTENT: every statement only matches broken roles, so re-running is a
-- no-op. No column is dropped, no row is deleted.
-- NUMBERING: 107 is email_channel_default_on, 108 is policy_parse_status.
--
-- NOTE: a "--" comment line must not contain a semicolon or an odd number of
-- apostrophes - the migration runner splits statements naively.
-- ============================================================================

-- Statement 1: authoritative FK link (users.employee_id = employees.employee_id).
UPDATE `users` u
JOIN `employees` e
  ON e.`employee_id` = u.`employee_id`
 AND e.`employee_id` IS NOT NULL
SET u.`role` = CASE
        WHEN e.`employee_type` = 'super_admin'       THEN 'super_admin'
        WHEN e.`employee_type` = 'managing_director' THEN 'managing_director'
        WHEN e.`employee_type` = 'bod_chairman'      THEN 'bod_chairman'
        WHEN e.`employee_type` = 'hr_manager'        THEN 'hr_manager'
        WHEN e.`employee_type` = 'dept_head'         THEN 'dept_head'
        WHEN e.`employee_type` = 'section_head'      THEN 'section_head'
        WHEN e.`employee_type` = 'sub_section_head'  THEN 'sub_section_head'
        WHEN e.`employee_type` = 'manager'           THEN 'manager'
        WHEN e.`employee_type` = 'officer'           THEN 'officer'
        ELSE 'employee'
    END,
    u.`updated_at` = CURRENT_TIMESTAMP
WHERE u.`role` IS NULL
   OR u.`role` = ''
   OR u.`role` NOT IN (
        'super_admin', 'hr_manager', 'dept_head', 'section_head',
        'sub_section_head', 'manager', 'officer', 'employee',
        'managing_director', 'bod_chairman', 'admin'
   );

-- Statement 2: legacy accounts with no employee_id link, matched by a UNIQUE
-- employee email (the employee-side uniqueness guard makes email 1:1, and the
-- COUNT guard additionally skips any legacy duplicate emails).
UPDATE `users` u
JOIN `employees` e
  ON e.`email` = u.`email`
SET u.`role` = CASE
        WHEN e.`employee_type` = 'super_admin'       THEN 'super_admin'
        WHEN e.`employee_type` = 'managing_director' THEN 'managing_director'
        WHEN e.`employee_type` = 'bod_chairman'      THEN 'bod_chairman'
        WHEN e.`employee_type` = 'hr_manager'        THEN 'hr_manager'
        WHEN e.`employee_type` = 'dept_head'         THEN 'dept_head'
        WHEN e.`employee_type` = 'section_head'      THEN 'section_head'
        WHEN e.`employee_type` = 'sub_section_head'  THEN 'sub_section_head'
        WHEN e.`employee_type` = 'manager'           THEN 'manager'
        WHEN e.`employee_type` = 'officer'           THEN 'officer'
        ELSE 'employee'
    END,
    u.`updated_at` = CURRENT_TIMESTAMP
WHERE u.`employee_id` IS NULL
  AND u.`email` IS NOT NULL
  AND u.`email` <> ''
  AND (u.`role` IS NULL
       OR u.`role` = ''
       OR u.`role` NOT IN (
            'super_admin', 'hr_manager', 'dept_head', 'section_head',
            'sub_section_head', 'manager', 'officer', 'employee',
            'managing_director', 'bod_chairman', 'admin'
       ))
  AND (SELECT COUNT(*) FROM `employees` e2 WHERE e2.`email` = u.`email`) = 1;
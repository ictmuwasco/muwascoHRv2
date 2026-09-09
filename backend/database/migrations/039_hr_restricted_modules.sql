-- ============================================================================
-- 039_hr_restricted_modules.sql
-- Phase: HR-restricted modules reconciliation (HR Admin group, Attendance
-- Dashboard, Reports)
--
-- Business rule: the "HR Admin" sidebar group (Financial Year, Appraisal
-- Cycles, Consent Management, Holidays), the Attendance Dashboard
-- (/attendance/dashboard) and the Reports module are HR-restricted. Default
-- holders: hr_manager, managing_director, super_admin.
--
--   dept_head / section_head / sub_section_head lose:
--     - attendance:manage               (Attendance Dashboard)
--     - financial_year:view/create/edit (HR Admin group trigger)
--   and KEEP attendance:view (unit-scoped records page), leave:manage
--   (scoped Leave Management), performance:view (standalone Appraisal page)
--   and performance:manage (appraisal create/submit/approve workflow).
--
--   managing_director is (re-)granted reports:view + reports:export — the MD
--   is an oversight consumer of Reports; migration 038 had revoked them.
--
--   Appraisal Cycles gets the dedicated performance:cycles page permission
--   (same pattern as leave:roster / meetings:dashboard): the HR Admin page
--   and its mutation APIs require it. GET /appraisal-cycles stays
--   authenticated-only — the heads' workplan quarter pickers consume the
--   minimal read-only payload (AppraisalCycleController::index).
--
-- Finally, active per-user ALLOW overrides for the restricted permissions are
-- deactivated for users outside hr_manager/managing_director/super_admin
-- (rows are kept with active = 0 for the audit trail).
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)
-- against uk_role_module_action. Revocations are expressed as explicit
-- is_granted = 0 rows so the permission management UI shows
-- "role default: denied" and an authorized override can still re-grant
-- per user. No schema changes.
--
-- Place: backend/database/migrations/039_hr_restricted_modules.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Reports — HR-restricted with the Managing Director as oversight consumer.
--    MD: ALLOW (view + export). Heads: explicit role-default denials.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('managing_director', 'reports', 'view',   1),
('managing_director', 'reports', 'export', 1),
('sub_section_head',  'reports', 'view',   0),
('section_head',      'reports', 'view',   0),
('dept_head',         'reports', 'view',   0),
('dept_head',         'reports', 'export', 0),
('section_head',      'reports', 'export', 0),
('sub_section_head',  'reports', 'export', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 2. Attendance Dashboard — attendance:manage is HR/MD/super_admin only.
--    Heads keep attendance:view (their unit-scoped Attendance Records page).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('dept_head',        'attendance', 'manage', 0),
('section_head',     'attendance', 'manage', 0),
('sub_section_head', 'attendance', 'manage', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 3. HR Admin group triggers — financial_year / consent / holidays revoked
--    from the heads (explicit 0 rows for UI legibility + override anchoring).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('dept_head',        'financial_year', 'view',   0),
('dept_head',        'financial_year', 'create', 0),
('dept_head',        'financial_year', 'edit',   0),
('section_head',     'financial_year', 'view',   0),
('section_head',     'financial_year', 'create', 0),
('sub_section_head', 'financial_year', 'view',   0),
('sub_section_head', 'financial_year', 'create', 0),
('dept_head',        'consent',        'view',   0),
('section_head',     'consent',        'view',   0),
('sub_section_head', 'consent',        'view',   0),
('dept_head',        'holidays',       'view',   0),
('section_head',     'holidays',       'view',   0),
('sub_section_head', 'holidays',       'view',   0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 4. Appraisal Cycles page — dedicated performance:cycles permission.
--    ALLOW: super_admin (row kept for UI legibility), hr_manager,
--    managing_director. DENY: heads (workplan pickers use the
--    authenticated-only GET /appraisal-cycles minimal payload).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',      'performance', 'cycles', 1),
('hr_manager',       'performance', 'cycles', 1),
('managing_director','performance', 'cycles', 1),
('sub_section_head', 'performance', 'cycles', 0),
('section_head',     'performance', 'cycles', 0),
('dept_head',        'performance', 'cycles', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 5. Per-user override reconciliation — deactivate active ALLOW overrides for
--    the restricted permissions held by users outside the three allowed
--    roles. Overrides beat role defaults (AuthorizationService hierarchy),
--    so any such row would leak an HR-restricted page to a head. Rows are
--    kept (active = 0) for the audit trail.
-- ----------------------------------------------------------------------------
UPDATE user_page_permissions upp
JOIN users u ON u.id = upp.user_id
SET upp.active = 0,
    upp.notes  = CONCAT(COALESCE(upp.notes, ''), ' [deactivated by migration 039: HR-restricted module baseline]')
WHERE upp.active = 1
  AND upp.permission_type = 'allow'
  AND u.role NOT IN ('hr_manager', 'managing_director', 'super_admin')
  AND (
       (upp.module = 'reports'        AND upp.action IN ('view', 'export'))
    OR (upp.module = 'attendance'     AND upp.action = 'manage')
    OR (upp.module = 'financial_year' AND upp.action IN ('view', 'create', 'edit'))
    OR (upp.module = 'performance'    AND upp.action = 'cycles')
    OR (upp.module = 'consent'        AND upp.action = 'view')
    OR (upp.module = 'holidays'       AND upp.action = 'view')
  );

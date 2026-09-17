-- ============================================================================
-- 082_md_hr_manager_parity.sql
-- Phase: Managing Director privilege elevation (HR-manager parity)
--
-- Business rule (user request): the Managing Director must see everything the
-- HR Manager sees, EXCEPT the "HR Admin" sidebar group (Financial Year,
-- Appraisal Cycles, Consent Management, Holidays).
--
-- Grants to managing_director (all idempotent against uk_role_module_action):
--   - attendance:view/manage            (Records + Attendance Dashboard)
--   - departments:view/create/edit     (Departments page)
--   - employees:view/create/edit/delete (Employees directory + forms)
--   - hr_policies:manage/publish       (Settings → HR Policies admin tab,
--                                       same as hr_manager)
--   - leave:view/apply/manage/approve/reject/invalidate
--                                       (Leave Management group + Roster gate
--                                       NOT included — leave:roster stays
--                                       hr_manager/super_admin)
--   - meetings:create/edit/delete/invite/manage/view_attendance/confirm
--                                       (full meetings admin, MD already holds
--                                       view/export)
--   - performance:view/manage          (standalone Appraisal page — NOT
--                                       performance:cycles which is the HR
--                                       Admin → Appraisal Cycles page)
--   - profile:edit                     (MD already holds profile:view)
--   - reports:export                   (completes reports:view already held)
--
-- Explicit is_granted = 0 rows (role-default deny documentation + override
-- anchors, same pattern as migrations 038 §8 / 045):
--   - HR Admin tab drivers so the group can never silently reappear:
--       financial_year:view/create/edit, consent:view/manage,
--       holidays:view/create/edit/delete, performance:cycles
--   - Super-admin-only administration (Phase 2 / migration 045 policy):
--       users:view/create/edit, admin:view/manage,
--       permission_overrides:view/manage
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted).
-- No schema changes.
--
-- Place: backend/database/migrations/082_md_hr_manager_parity.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Grants — hr_manager parity minus the HR Admin sidebar group.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('managing_director', 'attendance',  'view',    1),
('managing_director', 'attendance',  'manage',  1),
('managing_director', 'departments', 'view',    1),
('managing_director', 'departments', 'create',  1),
('managing_director', 'departments', 'edit',    1),
('managing_director', 'employees',   'view',    1),
('managing_director', 'employees',   'create',  1),
('managing_director', 'employees',   'edit',    1),
('managing_director', 'employees',   'delete',  1),
('managing_director', 'hr_policies', 'manage',  1),
('managing_director', 'hr_policies', 'publish', 1),
('managing_director', 'leave',       'view',    1),
('managing_director', 'leave',       'apply',   1),
('managing_director', 'leave',       'manage',  1),
('managing_director', 'leave',       'approve', 1),
('managing_director', 'leave',       'reject',  1),
('managing_director', 'leave',       'invalidate', 1),
('managing_director', 'meetings',    'create',  1),
('managing_director', 'meetings',    'edit',    1),
('managing_director', 'meetings',    'delete',  1),
('managing_director', 'meetings',    'invite',  1),
('managing_director', 'meetings',    'manage',  1),
('managing_director', 'meetings',    'view_attendance', 1),
('managing_director', 'meetings',    'confirm', 1),
('managing_director', 'performance', 'view',    1),
('managing_director', 'performance', 'manage',  1),
('managing_director', 'profile',     'edit',    1),
('managing_director', 'reports',     'export',  1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 2. Explicit role-default denials — HR Admin tab drivers (sidebar group stays
--    hidden: the group renders only when at least one driver is granted) and
--    super-admin-only administration modules.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('managing_director', 'financial_year',      'view',   0),
('managing_director', 'financial_year',      'create', 0),
('managing_director', 'financial_year',      'edit',   0),
('managing_director', 'consent',             'view',   0),
('managing_director', 'consent',             'manage', 0),
('managing_director', 'holidays',            'view',   0),
('managing_director', 'holidays',            'create', 0),
('managing_director', 'holidays',            'edit',   0),
('managing_director', 'holidays',            'delete', 0),
('managing_director', 'performance',         'cycles', 0),
('managing_director', 'users',               'view',   0),
('managing_director', 'users',               'create', 0),
('managing_director', 'users',               'edit',   0),
('managing_director', 'admin',               'view',   0),
('managing_director', 'admin',               'manage', 0),
('managing_director', 'permission_overrides','view',   0),
('managing_director', 'permission_overrides','manage', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ============================================================================
-- 038_role_permission_matrix.sql
-- Phase: Role, Page & Permission Restriction System Enhancement
--
-- Deterministic reconciliation of role_permissions to the documented default
-- role access matrix (docs/AUTHORIZATION.md):
--
--   Officer / Employee     own pages and own records only (Dashboard, Profile,
--                          Attendance [own], My Meetings, Leave Application,
--                          Leave Profile [own])
--   Sub-section Head       officer access + existing unit-scoped management
--                          extras (data scope enforced server-side by OrgScope)
--   Section Head           hierarchical access + section leave management
--   Department Head        hierarchical access + department leave management
--   HR Manager             all HR modules EXCEPT Settings + permission
--                          administration (permission_overrides + users revoked)
--   Managing Director      everything EXCEPT Settings + permission
--                          administration (deterministically granted here)
--   Super Admin            everything (engine policy; rows kept for UI completeness)
--   settings:notifications self-service tab (own push/SMS preferences) seeded
--                          to ALL roles
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted)
-- against uk_role_module_action. Revocations are expressed as explicit
-- is_granted = 0 rows so the permission management UI can show
-- "role default: denied" instead of an absent row. No schema changes.
--
-- Place: backend/database/migrations/038_role_permission_matrix.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Settings module — page shell + administrative tabs: super_admin ONLY.
--    (super_admin bypasses RBAC in the engine; rows exist for UI legibility.)
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin', 'settings', 'view',          1),
('super_admin', 'settings', 'profile',       1),
('super_admin', 'settings', 'notifications', 1),
('super_admin', 'settings', 'security',      1),
('super_admin', 'settings', 'audit',         1),
('super_admin', 'settings', 'users',         1),
('super_admin', 'settings', 'permissions',   1),
('super_admin', 'settings', 'monitoring',    1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 2. Self-service Notifications tab (own notification preferences) — ALL roles.
--    The underlying preference APIs are authenticated-only self-service
--    (config/authz_allowlist.php); this row only drives tab/route visibility.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager',        'settings', 'notifications', 1),
('dept_head',         'settings', 'notifications', 1),
('section_head',      'settings', 'notifications', 1),
('sub_section_head',  'settings', 'notifications', 1),
('manager',           'settings', 'notifications', 1),
('officer',           'settings', 'notifications', 1),
('employee',          'settings', 'notifications', 1),
('managing_director', 'settings', 'notifications', 1),
('bod_chairman',      'settings', 'notifications', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 3. Officer / Employee pruning — the default matrix grants ONLY own pages and
--    own records. Explicit 0 rows revoke previously-granted page access
--    (Employees directory, Reports, Financial Year admin, Appraisal and the
--    strategy chain views seeded by migration 027).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('officer',  'employees',            'view',   0),
('officer',  'reports',              'view',   0),
('officer',  'financial_year',       'view',   0),
('officer',  'financial_year',       'create', 0),
('officer',  'performance',          'view',   0),
('officer',  'strategic_plan',       'view',   0),
('officer',  'performance_contract', 'view',   0),
('officer',  'workplan',             'view',   0),
('officer',  'kpi',                  'view',   0),
('officer',  'sectional_objective',  'view',   0),
('employee', 'reports',              'view',   0),
('employee', 'financial_year',       'view',   0),
('employee', 'performance',          'view',   0),
('employee', 'strategic_plan',       'view',   0),
('employee', 'performance_contract', 'view',   0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 4. HR Manager — Settings APIs and permission administration are DENIED by
--    default (§8): permission management and user administration move to
--    super_admin. Explicit 0 rows document the revocation (overridable
--    per user via Settings → Permissions).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager', 'permission_overrides', 'view',   0),
('hr_manager', 'permission_overrides', 'manage', 0),
('hr_manager', 'users', 'view',   0),
('hr_manager', 'users', 'create', 0),
('hr_manager', 'users', 'edit',   0),
('hr_manager', 'users', 'delete', 0),
-- Settings API itself is gated 'admin:view'/'admin:manage' (api.php
-- /settings routes). Revoking closes the HR Manager Settings-API bypass —
-- they can no longer read or mutate system settings through direct calls.
('hr_manager', 'admin', 'view',   0),
('hr_manager', 'admin', 'manage', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 5. Managing Director — everything EXCEPT Settings + permission
--    administration (§10). Grants the full operational catalog; the strategy
--    chain (027), meetings view/export (004) and payroll view (035) already
--    exist and are left untouched. Settings/permission/user/audit/monitoring
--    modules remain UN-granted (absence = role default deny).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('managing_director', 'dashboard',     'view',   1),
-- Phase 10 correction: Employees/Roster/Reports are HR-only modules.
-- MD must NOT receive employees grants here (revoked in section 8).
('managing_director', 'departments',   'view',   1),
('managing_director', 'departments',   'create', 1),
('managing_director', 'departments',   'edit',   1),
('managing_director', 'departments',   'delete', 1),
('managing_director', 'attendance',    'view',   1),
('managing_director', 'attendance',    'manage', 1),
('managing_director', 'leave',         'view',   1),
('managing_director', 'leave',         'apply',  1),
('managing_director', 'leave',         'manage', 1),
('managing_director', 'leave',         'approve',1),
('managing_director', 'leave',         'reject', 1),
('managing_director', 'leave',         'invalidate', 1),
-- Phase 10 correction: reports:view/export intentionally NOT granted
-- (Reports is an HR-only module; revoked in section 8).
('managing_director', 'profile',       'view',   1),
('managing_director', 'profile',       'edit',   1),
('managing_director', 'performance',   'view',   1),
('managing_director', 'performance',   'manage', 1),
('managing_director', 'consent',       'view',   1),
('managing_director', 'consent',       'manage', 1),
('managing_director', 'financial_year','view',   1),
('managing_director', 'financial_year','create', 1),
('managing_director', 'financial_year','edit',   1),
('managing_director', 'holidays',      'view',   1),
('managing_director', 'holidays',      'create', 1),
('managing_director', 'holidays',      'edit',   1),
('managing_director', 'holidays',      'delete', 1),
('managing_director', 'meetings',      'create', 1),
('managing_director', 'meetings',      'edit',   1),
('managing_director', 'meetings',      'delete', 1),
('managing_director', 'meetings',      'invite', 1),
('managing_director', 'meetings',      'manage', 1),
('managing_director', 'meetings',      'view_attendance', 1),
('managing_director', 'meetings',      'confirm', 1),
('managing_director', 'complaints',    'view',   1),
('managing_director', 'payroll',       'manage', 1),
('managing_director', 'notifications', 'view',   1),
('managing_director', 'notifications', 'manage', 1),
-- Meetings org-wide dashboard (§2: only HR/managing director/super admin).
('managing_director', 'meetings',      'dashboard', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 6. Meetings Dashboard (org-wide) — restricted to the three senior roles.
--    /meetings + /meetings/stats render the org-wide MeetingsDashboard;
--    personal invitations remain at /my-meetings via meetings:view (open to
--    every invited role). meetings:dashboard is the dedicated page permission.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',      'meetings', 'dashboard', 1),
('hr_manager',       'meetings', 'dashboard', 1),
('managing_director','meetings', 'dashboard', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 7. Leave Profile page — visible to ALL roles (Section 4: officers, and by
--    extension every self-service role, may view their OWN leave profile).
--    manager and bod_chairman previously lacked leave:view/apply; grant the
--    same self-service leave set every other role has. Data scope is still
--    pinned to the caller's own record server-side (LeaveProfileService).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('manager',      'leave', 'view',  1),
('manager',      'leave', 'apply', 1),
('bod_chairman', 'leave', 'view',  1),
('bod_chairman', 'leave', 'apply', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 8. PHASE 10 CORRECTION - HR-only administrative modules.
--
--    Employees, Roster and Reports are HR-restricted by default:
--      ALLOW: hr_manager (the "HR Admin" persona), super_admin
--      DENY : officer, sub_section_head, section_head, dept_head,
--             managing_director, and every role without an explicit grant
--
--    Heads previously inherited employees/reports grants from the migration
--    004 seeds (MD additionally from section 5); those grants are flipped to
--    explicit is_granted = 0 rows so the permission UI renders
--    "role default: denied" and an authorized override can still re-grant
--    them per user.
--
--    Roster gets the dedicated `leave:roster` page permission introduced in
--    the catalog: it was previously coupled to `leave:manage`, which heads
--    need for scoped LEAVE MANAGEMENT but which must not imply Roster access.
--    The roster API routes and sidebar visibility are repointed to
--    leave:roster (api.php + pagePermissions.jsx + Sidebar.jsx).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
-- Roster: dedicated page permission, HR + super admin only.
('hr_manager',       'leave', 'roster', 1),
('super_admin',      'leave', 'roster', 1),
-- Explicit role-default denials (UI legibility + override anchor).
('sub_section_head', 'leave', 'roster', 0),
('section_head',     'leave', 'roster', 0),
('dept_head',        'leave', 'roster', 0),
('managing_director','leave', 'roster', 0),
-- Employees module: HR-only.
('sub_section_head', 'employees', 'view',   0),
('section_head',     'employees', 'view',   0),
('dept_head',        'employees', 'view',   0),
('dept_head',        'employees', 'edit',   0),
('managing_director','employees', 'view',   0),
('managing_director','employees', 'create', 0),
('managing_director','employees', 'edit',   0),
('managing_director','employees', 'delete', 0),
-- Reports module: HR-only.
('sub_section_head', 'reports', 'view',   0),
('section_head',     'reports', 'view',   0),
('dept_head',        'reports', 'view',   0),
('dept_head',        'reports', 'export', 0),
('managing_director','reports', 'view',   0),
('managing_director','reports', 'export', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);
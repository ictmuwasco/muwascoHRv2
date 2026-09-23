-- ============================================================================
-- 088_settings_tab_visibility.sql
-- Phase: Settings tab visibility — one role model
--
-- Business rule (user request): inside Settings, each role sees only the tabs
-- it is entitled to:
--   super_admin   : EVERY tab (Profile, Notifications, Security, Audit, Users,
--                   Permissions, System Monitor, HR Policies admin)
--   hr_manager    : HR Policies + Notifications ONLY
--   every other role: Notifications ONLY (self-service push/SMS preferences)
--
-- This migration repairs the database DRIFT that let the old behaviour leak:
--   - hr_manager still held the full pre-038 settings module (settings:view/
--     profile/security/audit/users/permissions/monitoring) AND the legacy
--     settings-API bypass rows (admin:view/manage, users:*, permission_
--     overrides:*, audit:*, system_errors:*) that migrations 038 §4 and 045
--     were documented to revoke but were never applied to this database.
--     Hiding a tab does not protect its API (PHASE6 audit, Section 8) — so
--     the backing API permissions are revoked here too.
--   - No role other than hr_manager held settings:notifications (038 §2 was
--     likewise never applied), so the all-roles self-service Notifications
--     tab did not exist. It is (re-)seeded for every role here.
--   - managing_director held hr_policies:manage/publish (migration 082 parity).
--     Per the current requirement only hr_manager (and super_admin) keeps the
--     HR Policies ADMIN tab, so those two rows are flipped to explicit denies.
--     managing_director keeps hr_policies:view/acknowledge (the reader at
--     /hr/policies — a sidebar entry, not a Settings tab).
--
-- Revocations are expressed as explicit is_granted = 0 rows against the UK
-- (role, module, action) so the Permission UI shows "role default: denied"
-- and a per-user ALLOW override (Settings → Permissions, super_admin only)
-- can still re-open a tab for an authorised individual.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted).
-- No schema changes.
--
-- Place: backend/database/migrations/088_settings_tab_visibility.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Settings module — super_admin holds every tab (rows kept for UI
--    legibility; the engine policy allows super_admin regardless).
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
-- 2. hr_manager — HR Policies + Notifications ONLY. Every administrative
--    settings tab is denied (drift repair: these were granted=1 in this DB).
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager', 'settings', 'notifications', 1),
('hr_manager', 'settings', 'view',          0),
('hr_manager', 'settings', 'profile',       0),
('hr_manager', 'settings', 'security',      0),
('hr_manager', 'settings', 'audit',         0),
('hr_manager', 'settings', 'users',         0),
('hr_manager', 'settings', 'permissions',   0),
('hr_manager', 'settings', 'monitoring',    0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 3. Self-service Notifications tab for EVERY other role (038 §2 replay —
--    absent from this database). The preference/push APIs are authenticated-
--    only self-service (config/authz_allowlist.php); the row only drives
--    tab/route visibility.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
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
-- 4. hr_manager legacy settings-API bypass (038 §4 + 045 replay). These rows
--    power the Users / Permissions / Audit / System Monitor / Admin tabs and
--    their backing APIs — all super_admin-only from now on. (system_errors
--    includes assign + view_sensitive, present-but-never-revoked in this DB.)
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager', 'admin',               'view',           0),
('hr_manager', 'admin',               'manage',         0),
('hr_manager', 'users',               'view',           0),
('hr_manager', 'users',               'create',         0),
('hr_manager', 'users',               'edit',           0),
('hr_manager', 'users',               'delete',         0),
('hr_manager', 'permission_overrides','view',           0),
('hr_manager', 'permission_overrides','manage',         0),
('hr_manager', 'audit',               'view',           0),
('hr_manager', 'audit',               'export',         0),
('hr_manager', 'system_errors',       'view',           0),
('hr_manager', 'system_errors',       'manage',         0),
('hr_manager', 'system_errors',       'resolve',        0),
('hr_manager', 'system_errors',       'assign',         0),
('hr_manager', 'system_errors',       'view_sensitive', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ----------------------------------------------------------------------------
-- 5. HR Policies ADMIN tab — hr_manager + super_admin only. managing_director
--    loses the manage/publish parity granted by migration 082 (superseded by
--    this requirement); its reader access (view/acknowledge) is untouched.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('managing_director', 'hr_policies', 'manage',  0),
('managing_director', 'hr_policies', 'publish', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);


-- ============================================================================
-- 045_settings_pages_super_admin_only.sql
-- Phase: Settings pages restricted to super_admin
--
-- Business rule: the Settings pages under frontend/src/pages/settings/
-- (Admin — financial-year management, Audit trail, System Monitor
-- (error-tracking / performance / health), and User management) are
-- SUPER_ADMIN-ONLY surfaces. No other role may open them or read their
-- backing APIs.
--
-- The page shells were already guarded by super_admin-only permissions
-- (settings:audit, settings:users, settings:monitoring, admin:view,
-- audit:view). This migration closes the residual API surface that earlier
-- seeds left open for HR Manager:
--   - audit / audit:export            (migration 004 granted hr_manager)
--   - system_errors view/manage/resolve (migration 031 granted hr_manager;
--       backs /system/errors/*, /system/performance, /system/health used by
--       the System Monitor page)
--   - users:* / admin:* re-asserted (migration 038 declarations kept
--     idempotent so a drifted database converges to the same state).
--
-- Revocations are expressed as explicit is_granted = 0 rows against the UK
-- (role, module, action) so the Permission UI shows "role default: denied"
-- and a per-user ALLOW override (Settings → Permissions) can still re-open a
-- page for an authorized individual. No schema changes.
--
-- Place: backend/database/migrations/045_settings_pages_super_admin_only.sql
-- ============================================================================

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
-- Re-assert migration 038 revocations (idempotent against database drift).
('hr_manager',  'users',           'view',           0),
('hr_manager',  'users',           'create',         0),
('hr_manager',  'users',           'edit',           0),
('hr_manager',  'users',           'delete',         0),
('hr_manager',  'admin',           'view',           0),
('hr_manager',  'admin',           'manage',         0),

-- Audit Log page (/audit, /audit/statistics, /audit/export, /audit/{id}) —
-- super_admin only.
('hr_manager',  'audit',           'view',           0),
('hr_manager',  'audit',           'export',         0),

-- System Monitor page (/system/health, /system/errors/*, /system/performance) —
-- super_admin only.
('hr_manager',  'system_errors',   'view',           0),
('hr_manager',  'system_errors',   'manage',         0),
('hr_manager',  'system_errors',   'resolve',        0),

-- Same treatment for the legacy roles that could otherwise inherit drift.
('officer',              'audit',           'view',   0),
('officer',              'audit',           'export', 0),
('section_head',         'audit',           'view',   0),
('section_head',         'audit',           'export', 0),
('sub_section_head',     'audit',           'view',   0),
('sub_section_head',     'audit',           'export', 0),
('dept_head',            'audit',           'view',   0),
('dept_head',            'audit',           'export', 0),
('managing_director',    'audit',           'view',   0),
('managing_director',    'audit',           'export', 0),
('officer',              'system_errors',   'view',   0),
('officer',              'system_errors',   'manage', 0),
('section_head',         'system_errors',   'view',   0),
('sub_section_head',     'system_errors',   'view',   0),
('dept_head',            'system_errors',   'view',   0),
('managing_director',    'system_errors',   'view',   0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);
-- Phase 2 authorization consolidation — ADDITIVE permission seeds.
--
-- Aligns the role_permissions table with the consolidated permission catalog
-- (backend/config/permissions.php) for modules that were enforced by
-- controllers / route gates but had no catalog + seed definition:
--   complaints, payroll, notifications
-- (strategy/performance modules were already seeded by migration 027.)
--
-- No schema change: idempotent via uk_role_module_action +
-- ON DUPLICATE KEY UPDATE. super_admin bypasses RBAC entirely, so rows are
-- seeded for hr_manager (operational owner) and managing_director (payroll
-- oversight) to preserve current working behaviour.
--
-- Place: backend/database/migrations/035_phase2_authz_consolidation.sql

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
-- Complaints triage (employees file/list their OWN complaints without it)
('super_admin', 'complaints', 'view', 1),
('hr_manager', 'complaints', 'view', 1),

-- Payroll periods / records administration
('super_admin', 'payroll', 'view', 1),
('super_admin', 'payroll', 'manage', 1),
('hr_manager', 'payroll', 'view', 1),
('hr_manager', 'payroll', 'manage', 1),
('managing_director', 'payroll', 'view', 1),

-- Notification administration (delivery stats, audit, test-send)
('super_admin', 'notifications', 'view', 1),
('super_admin', 'notifications', 'manage', 1),
('hr_manager', 'notifications', 'view', 1),
('hr_manager', 'notifications', 'manage', 1)

ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

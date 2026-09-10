-- ============================================================================
-- 046_dashboard_hr_insights.sql
-- Phase: Dashboard HR Insights widget permission
--
-- Business rule: the "HR Insights" widget on the Dashboard is an HR-oversight
-- surface (contract expiry, retirement, pending leave > 1 week, roster for the
-- current month, today's attendance and employees on approved leave). It may
-- only be viewed by HR Manager, Managing Director and Super Admin.
--
-- Seeds the new catalog action dashboard:hr_insights (added to
-- backend/config/permissions.php) for exactly those three roles. Idempotent
-- via the (role, module, action) unique key; every other role implicitly
-- defaults to deny (no row), matching the documented default role matrix
-- (docs/AUTHORIZATION.md) and migration 039's HR-restricted modules pattern.
--
-- Place: backend/database/migrations/046_dashboard_hr_insights.sql
-- ============================================================================

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager',        'dashboard', 'hr_insights', 1),
('managing_director', 'dashboard', 'hr_insights', 1),
('super_admin',       'dashboard', 'hr_insights', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);
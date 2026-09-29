-- ============================================================================
-- 098_restore_delegations_view_grants.sql
-- Phase: Acting Authority register — restore the missing visibility grants
--
-- THE BUG THIS FIXES
-- ------------------
-- Migration 040 seeded the whole `delegations` module (view / create /
-- approve / cancel) for all ten roles, but in this database that seed only
-- ever landed for ONE role: `hr_manager`. Every other role was left with NO
-- `delegations:view` row at all.
--
-- Because the SPA gates the sidebar link (Sidebar.jsx `canViewDelegations`),
-- the /delegations route (config/pagePermissions.jsx) and the API route
-- (api.php) on the SAME permission, the consequence was total:
--
--   * the "Delegations" menu entry never rendered for anyone but HR,
--   * /delegations answered AccessDenied,
--   * GET /delegations returned 403,
--   * so the register looked "empty" when it was in fact unreachable —
--     the rows existed, nobody could open the page to see them.
--
-- `leave:view` for contrast is present for all ten roles, which is what a
-- healthy seed looks like.
--
-- WHAT IS RESTORED
-- ----------------
-- delegations:view for the nine non-officer roles, i.e. the self-service
-- "who is covering whom" read that the module was designed around. Officers
-- stay EXCLUDED (migration 097): the register is an oversight artefact and an
-- officer must not be able to enumerate it.
--
-- WHAT IS DELIBERATELY NOT RESTORED
-- ---------------------------------
-- `delegations:create`. Migration 040 granted it to seven supervisory roles,
-- but the "New Delegation" form has since been removed from the SPA — every
-- delegation is now minted from an approved leave. Granting a write
-- permission for a screen that no longer exists would re-open an unused path
-- to create authority by hand, which is exactly what that removal was meant
-- to close. `hr_manager`'s pre-existing create row is left untouched; retiring
-- that endpoint entirely is a separate, deliberate decision.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE against uk_role_module_action.
--
-- Place: backend/database/migrations/098_restore_delegations_view_grants.sql
-- ============================================================================

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'delegations', 'view', 1),
('hr_manager',        'delegations', 'view', 1),
('managing_director', 'delegations', 'view', 1),
('dept_head',         'delegations', 'view', 1),
('section_head',      'delegations', 'view', 1),
('sub_section_head',  'delegations', 'view', 1),
('manager',           'delegations', 'view', 1),
('employee',          'delegations', 'view', 1),
('bod_chairman',      'delegations', 'view', 1),
-- Officers remain outside the register (see header note).
('officer',           'delegations', 'view', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- ============================================================================
-- 097_delegations_register_scoping.sql
-- Phase: Acting Authority register — visibility scope
--
-- Migration 040 seeded delegations:view to EVERY role so each user could see
-- "My Delegations". That is right for self-service but wrong for the
-- supervisory register this page has become: an officer must not be able to
-- enumerate who is covering whom across the organisation.
--
-- This migration revokes delegations:view from 'officer'. Because the
-- frontend gates the sidebar entry AND the /delegations route on the same
-- permission (config/pagePermissions.jsx → 'delegations:view'), revoking it
-- closes the surface at all three layers at once:
--
--   1. GET /delegations      → 403 for officers (api.php route gate)
--   2. /delegations route    → AccessDenied screen (ProtectedRoute)
--   3. Sidebar "Delegations" → link disappears
--
-- DelegationService::listFor() ALSO short-circuits to [] for officer
-- (REGISTER_EXCLUDED_ROLES) as defence in depth, so a stray per-user
-- 'delegations:view' ALLOW override can never re-expose the register.
--
-- NOTE — deliberate trade-off, please read:
--   An officer can still be APPOINTED as a duty-cover delegate on a leave
--   application. If that happens they keep working authority (the delegation
--   still resolves through AuthorizationService Priority 6) and the
--   DelegateBanner still explains it (it reads user.active_delegations from
--   /auth/user, which is not permission-gated). They simply cannot BROWSE
--   the register. If you would rather officers keep read access to their own
--   rows, set is_granted back to 1 for officer/view — listFor() would then
--   return only their own delegations, never anyone else's.
--
-- Also revokes the delegations:cancel grant added to officer by migration 096.
-- That grant existed only so an applicant could withdraw their own
-- auto-created duty-cover delegation from this page; with officers excluded
-- from the page the grant is unreachable, and leaving it would imply an
-- affordance they cannot use. Server-side revocation on leave
-- invalidation/cancellation (cancelForLeaveApplication) is unaffected.
--
-- Idempotent: INSERT ... ON DUPLICATE KEY UPDATE against uk_role_module_action.
--
-- Place: backend/database/migrations/097_delegations_register_scoping.sql
-- ============================================================================

-- 1. Officers lose the register entirely.
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('officer', 'delegations', 'view', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 2. Withdraw the now-unreachable officer cancel grant (migration 096).
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('officer', 'delegations', 'cancel', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

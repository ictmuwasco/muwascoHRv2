-- ===========================================================================
-- 102_remove_payroll_module.sql
--
-- Retires the payroll module. The controller (HR\PayrollController) and its
-- four routes (/payroll/periods, /payroll/records) were removed because the
-- module had no working backing store: `payroll_periods` and
-- `payroll_records` were created at RUNTIME by the controller itself
-- (CREATE TABLE IF NOT EXISTS inside the request handler), never existed in
-- any migration, and therefore never existed in a clean database built from
-- the migration history. The endpoints therefore always answered
-- "No payroll periods yet" / "No payroll records yet" on a fresh environment.
--
-- This migration removes the now-orphaned authorization rows so the
-- permission matrix and the RBAC catalog stay consistent: the `payroll` key
-- was also removed from backend/config/permissions.php, leaving these rows
-- unreachable and dead.
--
-- Only `role_permissions` is touched. No payroll data is lost — there was
-- none (the tables did not exist in this database).
--
-- Idempotent.
-- ===========================================================================

DELETE FROM `role_permissions` WHERE `module` = 'payroll';
DELETE FROM `user_page_permissions` WHERE `module` = 'payroll';

-- ===========================================================================
-- End of 102_remove_payroll_module.sql
-- ===========================================================================

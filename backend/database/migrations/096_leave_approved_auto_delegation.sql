-- ============================================================================
-- 096_leave_approved_auto_delegation.sql
-- Phase: Approved Leave -> automatic duty-cover delegation
--
-- Closes the gap between the TWO delegate mechanisms that already exist:
--
--   (a) the duty-cover delegate picked on the Apply Leave form, stored on
--       `leave_applications.delegate_emp_id` (migration 012). It only ever
--       let that person BACK-UP approve the applicant's own application
--       (DelegateService::canDelegateApprove) and granted NO permissions.
--   (b) the Acting Authority module in the `delegations` table (migration
--       040), which DOES grant temporary permissions via
--       AuthorizationService Priority 6 — but until now it could only be
--       populated by hand through the "New Delegation" button.
--
-- This migration lets (a) flow into (b): when a leave application reaches
-- the FINAL 'approved' state, DelegationService::createFromApprovedLeave()
-- writes one row here for the appointed delegate, already 'approved'
-- (the leave approval chain IS the authorization gate) and time-bounded to
-- the exact leave window. The delegate therefore receives the delegator's
-- page permissions AUTOMATICALLY, and they lapse on the end date with no
-- cron (the existing lazy sweep already expires them).
--
-- Adds:
--   - delegations.source              — 'manual' | 'leave_application'
--   - delegations.leave_application_id — reverse link to the leave row
--   - UNIQUE (leave_application_id)   — idempotency guard: one auto
--     delegation per leave application, so a re-run can never double-grant.
--     Manual rows carry NULL, and MySQL allows unlimited NULLs in a UNIQUE
--     index, so existing rows are untouched.
--
-- Also seeds delegations:cancel for officer / employee / bod_chairman.
-- DelegationService::cancel() has ALWAYS permitted the DELEGATOR to revoke
-- their own delegation (`$isDelegator` ownership bypass) — but the route
-- gate (api.php → 'delegations:cancel') refused the request before the
-- service was ever reached, so a non-supervisory applicant could never
-- exercise that right. Now that an ordinary employee can hold an
-- auto-created duty-cover delegation, they must be able to withdraw it.
--
-- Idempotent: guarded ALTERs via information_schema + CREATE INDEX guarded
-- the same way, and INSERT ... ON DUPLICATE KEY UPDATE on
-- uk_role_module_action.
--
-- Place: backend/database/migrations/096_leave_approved_auto_delegation.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. delegations.source
-- ----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'delegations'
      AND COLUMN_NAME  = 'source'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `delegations` ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT ''manual'' AFTER `reason`',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 2. delegations.leave_application_id (reverse link; NULL for manual rows)
-- ----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'delegations'
      AND COLUMN_NAME  = 'leave_application_id'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `delegations` ADD COLUMN `leave_application_id` BIGINT UNSIGNED NULL AFTER `source`',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3. UNIQUE (leave_application_id) — idempotency guard for the auto path.
--    A plain index is enough to make the guard lookup fast; the UNIQUE
--    constraint is what actually prevents a duplicate grant.
-- ----------------------------------------------------------------------------
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'delegations'
      AND INDEX_NAME    = 'uk_delegations_leave_application'
);
SET @ddl = IF(@idx_exists = 0,
    'ALTER TABLE `delegations` ADD UNIQUE INDEX `uk_delegations_leave_application` (`leave_application_id`)',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 4. Permission seed — self-service withdrawal of an auto duty-cover
--    delegation. Granting the ACTION does not let these roles create or
--    approve delegations (those stay supervisory/HR only, migration 040);
--    it only lets the delegator revoke a delegation of their own, which
--    DelegationService::cancel() has always enforced server-side.
-- ----------------------------------------------------------------------------
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('officer',           'delegations', 'cancel', 1),
('employee',          'delegations', 'cancel', 1),
('bod_chairman',      'delegations', 'cancel', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

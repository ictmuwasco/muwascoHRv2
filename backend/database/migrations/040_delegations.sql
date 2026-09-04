-- ============================================================================
-- 040_delegations.sql
-- Phase: Temporary Delegation / Acting Authority System
--
-- Introduces the `delegations` table: an explicit, time-bound, scope-aware
-- transfer of a supervisor's approval authority to a delegate WITHOUT
-- changing the delegate's permanent role. Effective authority is
--
--     Permanent Role + Explicit Temporary Delegation + Delegated Permissions
--     + Delegated Organizational Scope + Valid Time Period
--
-- Lifecycle: pending → approved → (date window) active → expired, with
-- rejected / cancelled terminal states. "Active" authority is DATE-DRIVEN
-- (status='approved' AND CURDATE() BETWEEN start_date AND end_date), so the
-- delegation stops applying automatically at the end date without a cron job
-- (a lazy sweep persists the active/expired status transitions for the UI).
--
-- Also extends `leave_history` so a decision made by a delegate records the
-- delegation reference AND the original approver (acted_for_user_id) next to
-- the actual acting user (performed_by) — §19/§39/§40 of the delegation spec.
--
-- Seeds the `delegations` permission module (see sections 3a–3d below).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS, guarded ALTERs, and
-- INSERT ... ON DUPLICATE KEY UPDATE against uk_role_module_action.
--
-- Place: backend/database/migrations/040_delegations.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. delegations table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `delegations` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `delegator_user_id` INT NOT NULL COMMENT 'The supervisor whose authority is delegated',
    `delegate_user_id`  INT NOT NULL COMMENT 'The user receiving the temporary authority',
    `delegated_role`    VARCHAR(50) NOT NULL COMMENT 'The DELEGATOR''S role whose authority is transferred (role-aware §7)',
    `scope_type`        ENUM('department','section','subsection','organization') NOT NULL COMMENT 'Delegated organizational scope (§8)',
    `scope_id`          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unit id for the scope (0 for organization-wide)',
    `permissions`       TEXT NOT NULL COMMENT 'Explicit JSON snapshot of delegated "module:action" strings (§22)',
    `start_date`        DATE NOT NULL,
    `end_date`          DATE NOT NULL,
    `reason`            VARCHAR(500) DEFAULT NULL,
    `status`            ENUM('pending','approved','active','expired','cancelled','rejected') NOT NULL DEFAULT 'pending',
    `approved_by`       INT DEFAULT NULL,
    `approved_at`       DATETIME DEFAULT NULL,
    `created_by`        INT DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_delegations_delegator` FOREIGN KEY (`delegator_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_delegations_delegate`  FOREIGN KEY (`delegate_user_id`)  REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_delegations_delegate` (`delegate_user_id`, `status`, `start_date`, `end_date`),
    INDEX `idx_delegations_delegator` (`delegator_user_id`, `status`),
    INDEX `idx_delegations_status_window` (`status`, `start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Temporary delegation / acting authority (never a role change)';

-- ----------------------------------------------------------------------------
-- 2. leave_history extension: delegation reference + original approver
--    (guarded ALTER — idempotent via information_schema)
-- ----------------------------------------------------------------------------
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'leave_history'
      AND COLUMN_NAME  = 'delegation_id'
);
SET @ddl = IF(@col_exists = 0,
    'ALTER TABLE `leave_history` ADD COLUMN `delegation_id` BIGINT UNSIGNED NULL AFTER `performed_by`, ADD COLUMN `acted_for_user_id` INT NULL AFTER `delegation_id`',
    'SELECT 1 AS no_op'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- 3. Permission module seed (role_permissions)
-- ----------------------------------------------------------------------------
-- 3a. delegations:view — every role (self-service "My Delegations"; delegates
--     who are officers must be able to see the authority they were given).
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'delegations', 'view', 1),
('hr_manager',        'delegations', 'view', 1),
('dept_head',         'delegations', 'view', 1),
('section_head',      'delegations', 'view', 1),
('sub_section_head',  'delegations', 'view', 1),
('manager',           'delegations', 'view', 1),
('officer',           'delegations', 'view', 1),
('employee',          'delegations', 'view', 1),
('managing_director', 'delegations', 'view', 1),
('bod_chairman',      'delegations', 'view', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 3b. delegations:create — supervisory roles ONLY. Officers/employees can
--     never create a delegation (spec §5/§6, scenario 6).
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'delegations', 'create', 1),
('hr_manager',        'delegations', 'create', 1),
('dept_head',         'delegations', 'create', 1),
('section_head',      'delegations', 'create', 1),
('sub_section_head',  'delegations', 'create', 1),
('manager',           'delegations', 'create', 1),
('managing_director', 'delegations', 'create', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 3c. delegations:approve — the HR approval workflow (§11). If the delegator
--     is hr_manager/managing_director/super_admin, the service additionally
--     requires a super_admin approver (no self-approval anywhere).
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin', 'delegations', 'approve', 1),
('hr_manager',  'delegations', 'approve', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 3d. delegations:cancel — delegator-side roles + HR.
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'delegations', 'cancel', 1),
('hr_manager',        'delegations', 'cancel', 1),
('dept_head',         'delegations', 'cancel', 1),
('section_head',      'delegations', 'cancel', 1),
('sub_section_head',  'delegations', 'cancel', 1),
('manager',           'delegations', 'cancel', 1),
('managing_director', 'delegations', 'cancel', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);


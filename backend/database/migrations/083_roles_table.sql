-- ============================================================================
-- 083_roles_table.sql
-- Phase: Centralized roles table + widened users.role
--
-- Purpose:
--   1. Introduce a `roles` lookup table so role definitions (key, label,
--      description, activation, ordering) live in the database instead of
--      being hardcoded across pages and forms. The frontend consumes it via
--      GET /roles and mirrors it in frontend/src/config/roles.js; role
--      assignment validation (UserService::catalogRoles) prefers this table
--      with config/permissions.php as fallback.
--   2. Widen users.role from ENUM to VARCHAR(50). The enum can only hold the
--      ten seeded values, so a role added to the roles table could never be
--      assigned (audit finding F6/S4 in docs/PHASE1_AUDIT_REPORT.md). With a
--      varchar, the roles table is authoritative; validation still guards
--      assignment against the catalog.
--
-- Sync contract: every role in role_permissions must exist here with
-- is_system = 1. role_permissions.role keeps the varchar key (no refactor);
-- it joins to roles.key by name.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT ... ON DUPLICATE KEY
-- UPDATE + information_schema-guarded ALTER (pattern of migration 036).
--
-- Place: backend/database/migrations/083_roles_table.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Roles table
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(50) NOT NULL COMMENT 'Stable role identifier, matches users.role and role_permissions.role',
    label VARCHAR(100) NOT NULL COMMENT 'Human-readable display name',
    description VARCHAR(255) DEFAULT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Built-in role: cannot be deactivated/deleted',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Inactive roles are hidden from assignment UIs and denied for new assignments',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_roles_key (`key`),
    KEY idx_roles_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Canonical role definitions — single source of truth for role keys and labels';

-- ----------------------------------------------------------------------------
-- 2. Seed the canonical roles (mirrors config/permissions.php "roles" list)
-- ----------------------------------------------------------------------------
INSERT INTO roles (`key`, label, description, is_system, is_active, sort_order) VALUES
    ('super_admin',       'Super Admin',       'Full system access — policy, cannot be restricted', 1, 1, 1),
    ('hr_manager',        'HR Manager',        'Full HR administration', 1, 1, 2),
    ('managing_director', 'Managing Director', 'Org-wide operational oversight', 1, 1, 3),
    ('bod_chairman',      'BOD Chairman',      'Board-level self-service access', 1, 1, 4),
    ('dept_head',         'Department Head',   'Departmental management and team oversight', 1, 1, 5),
    ('section_head',      'Section Head',      'Section oversight', 1, 1, 6),
    ('sub_section_head',  'Sub Section Head',  'Sub-section oversight', 1, 1, 7),
    ('manager',           'Manager',           'Supervisory self-service access', 1, 1, 8),
    ('officer',           'Officer',           'Basic employee access', 1, 1, 9),
    ('employee',          'Employee',          'Self-service only', 1, 1, 10)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    is_system = VALUES(is_system);

-- ----------------------------------------------------------------------------
-- 3. Widen users.role ENUM -> VARCHAR(50) so any role key can be assigned.
--    Nullable is preserved on purpose: the audit reports empty users.role
--    rows resolve to deny-by-default, and forcing NOT NULL would flip those
--    accounts to the (more powerful) 'officer' default.
-- ----------------------------------------------------------------------------
SET @current_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'
);
SET @ddl := IF(@current_type LIKE 'enum%',
    'ALTER TABLE users MODIFY `role` VARCHAR(50) DEFAULT ''officer''',
    'SELECT ''users.role already widened'' AS step');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
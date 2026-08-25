-- Hybrid RBAC + User Permission Override Migration
-- Improves user_page_permissions table to support module/action-level overrides.
-- Replaces page_id with module + action for granular per-action permission control.
-- Adds updated_by for permission change tracking.
--
-- Authorization Hierarchy:
--   1. Super Admin (handled in RBAC.php)
--   2. User-Specific Override (this table)
--   3. Role Permission (role_permissions table)
--   4. Default Deny
--
-- Permission Type:
--   'allow'  = explicitly allow this user for module/action
--   'deny'   = explicitly deny this user for module/action
--   (no record) = inherit role permission
--

-- Step 1: Create backup of existing data for safety (Phase 18 - migration safety)
CREATE TABLE IF NOT EXISTS user_page_permissions_backup AS
SELECT * FROM user_page_permissions;

-- Step 2: Create the new table with module/action structure
DROP TABLE IF EXISTS user_page_permissions_new;

CREATE TABLE user_page_permissions_new (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL COMMENT 'References users.id',
    module     VARCHAR(50) NOT NULL COMMENT 'Module name (e.g., reports, employees, leave)',
    action     VARCHAR(50) NOT NULL COMMENT 'Action (e.g., view, create, edit, delete, export, approve, reject, manage)',
    permission_type ENUM('allow', 'deny') NOT NULL COMMENT 'allow=explicit grant, deny=explicit denial, no record=inherit',
    granted_by INT DEFAULT NULL COMMENT 'User who granted this override (references users.id)',
    updated_by INT DEFAULT NULL COMMENT 'User who last updated this override (references users.id)',
    granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the override was first granted',
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last modification timestamp',
    active      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=inactive (treated as no override), 1=active override',
    notes       TEXT DEFAULT NULL COMMENT 'Administrative context for this permission override',

    -- Prevent duplicate active overrides: one allow OR deny per user/module/action
    UNIQUE KEY uq_user_module_action (user_id, module, action),

    -- Indexes for efficient permission resolution
    INDEX idx_user_id              (user_id),
    INDEX idx_module               (module),
    INDEX idx_action               (action),
    INDEX idx_active               (active),
    INDEX idx_granted_by           (granted_by),
    INDEX idx_updated_by           (updated_by),
    INDEX idx_permission_type      (permission_type),

    -- Composite index for the most common lookup:
    -- SELECT ... WHERE user_id = ? AND module = ? AND action = ? AND active = 1
    INDEX idx_user_module_action_active (user_id, module, action, active),

    -- Foreign keys (ON DELETE behavior chosen to match existing application strategy)
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User-specific permission overrides (hybrid RBAC). Only explicit allow/deny overrides are stored; no record = inherit role permission.';

-- Step 3: Migrate existing data from old table to new table
-- page_id becomes module, action is set to 'view' (page-level overrides become module-level view overrides)
INSERT INTO user_page_permissions_new
    (user_id, module, action, permission_type, granted_by, updated_by, granted_at, updated_at, active, notes)
SELECT
    user_id,
    page_id,
    'view',
    permission_type,
    granted_by,
    granted_by,  -- initialize updated_by with granted_by
    granted_at,
    updated_at,
    active,
    notes
FROM user_page_permissions;

-- Step 4: Drop old table and rename new table to original name
-- This preserves the table name for backward compatibility with existing code references.
RENAME TABLE user_page_permissions TO user_page_permissions_old,
             user_page_permissions_new TO user_page_permissions;

-- Step 5: Drop the old table now that data has been migrated successfully
DROP TABLE IF EXISTS user_page_permissions_old;

-- Step 6: Verify migration (for debugging)
-- SELECT COUNT(*) as old_count FROM user_page_permissions_backup;
-- SELECT COUNT(*) as new_count FROM user_page_permissions;

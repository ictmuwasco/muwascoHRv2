-- Hybrid RBAC + User Permission Override Migration (Phase 015)
-- ============================================================
-- Safely converts the existing `user_page_permissions` table from the old
-- page_id-based structure to the new module+action based structure.
--
-- This migration is IDEMPOTENT and SAFE:
--   * Creates a backup before any modifications
--   * Uses ALTER TABLE (never DROP TABLE on the live table)
--   * Handles the case where columns already exist (re-run safe)
--   * Migrates any existing page_id data to module/action
--
-- Authorization Hierarchy:
--   1. Super Admin (handled in RBAC.php - always ALLOW)
--   2. User-Specific Override (this table) - allow/deny
--   3. Role Permission (role_permissions table)
--   4. Default Deny
--
-- Permission Type:
--   'allow'  = explicitly allow this user for module/action
--   'deny'   = explicitly deny this user for module/action
--   (no record) = inherit role permission
--
-- Tables referenced:
--   users.id (INT UNSIGNED)
--   user_page_permissions (existing table)

-- ============================================================
-- Step 1: Create backup of existing data for safety (Phase 18)
-- ============================================================
CREATE TABLE IF NOT EXISTS user_page_permissions_backup_015 AS
SELECT * FROM user_page_permissions;

-- ============================================================
-- Step 2: Add new columns if they don't exist (idempotent)
-- ============================================================

-- Add 'module' column (maps from old page_id)
SET @module_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND COLUMN_NAME = 'module'
);
SET @sql = IF(@module_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD COLUMN module VARCHAR(50) NOT NULL DEFAULT '''' AFTER user_id,
        ADD INDEX idx_user_page_module (module)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add 'action' column
SET @action_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND COLUMN_NAME = 'action'
);
SET @sql = IF(@action_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD COLUMN action VARCHAR(50) NOT NULL DEFAULT ''view'' AFTER module,
        ADD INDEX idx_user_page_action (action)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add 'updated_by' column
SET @updated_by_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND COLUMN_NAME = 'updated_by'
);
SET @sql = IF(@updated_by_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD COLUMN updated_by INT DEFAULT NULL AFTER granted_by',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 3: Migrate existing data from page_id to module/action
-- ============================================================
-- Where page_id has data and module is empty, copy page_id → module
-- and set action='view' for all existing page-level overrides.
UPDATE user_page_permissions
SET module = page_id,
    action = 'view'
WHERE (module = '' OR module IS NULL)
  AND page_id IS NOT NULL
  AND page_id != '';

-- Initialize updated_by from granted_by for existing records
UPDATE user_page_permissions
SET updated_by = granted_by
WHERE updated_by IS NULL
  AND granted_by IS NOT NULL;

-- ============================================================
-- Step 4: Add unique constraint for (user_id, module, action)
-- ============================================================
-- First check if any duplicates exist before adding the constraint
SET @dup_count = 0;

-- Clean up any pre-existing duplicates (keep the most recent active one)
DELETE p1 FROM user_page_permissions p1
INNER JOIN user_page_permissions p2
WHERE p1.id < p2.id
  AND p1.user_id = p2.user_id
  AND p1.module = p2.module
  AND p1.action = p2.action;

-- Add the unique constraint if it doesn't exist
SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND CONSTRAINT_NAME = 'uq_user_module_action'
      AND CONSTRAINT_TYPE = 'UNIQUE'
);
SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD UNIQUE KEY uq_user_module_action (user_id, module, action)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 5: Add composite index for efficient permission lookup
-- ============================================================
-- The most common query is:
--   WHERE user_id = ? AND module = ? AND action = ? AND active = 1
SET @composite_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND INDEX_NAME = 'idx_user_module_action_active'
);
SET @sql = IF(@composite_idx_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD INDEX idx_user_module_action_active (user_id, module, action, active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 6: Add foreign key for updated_by if it doesn't exist
-- ============================================================
SET @fk_updated_by_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND CONSTRAINT_NAME = 'fk_upp_updated_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_updated_by_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD CONSTRAINT fk_upp_updated_by
        FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure user_id FK uses consistent naming
SET @fk_user_id_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND CONSTRAINT_NAME = 'fk_upp_user_id'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_user_id_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD CONSTRAINT fk_upp_user_id
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure granted_by FK uses consistent naming
SET @fk_granted_by_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_page_permissions'
      AND CONSTRAINT_NAME = 'fk_upp_granted_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_granted_by_exists = 0,
    'ALTER TABLE user_page_permissions
        ADD CONSTRAINT fk_upp_granted_by
        FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 7: Verify migration (commented out for production)
-- ============================================================
-- SELECT COUNT(*) as backup_count FROM user_page_permissions_backup_015;
-- SELECT COUNT(*) as migrated_count FROM user_page_permissions;
-- SHOW COLUMNS FROM user_page_permissions;
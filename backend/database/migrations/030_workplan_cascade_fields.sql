-- 030_workplan_cascade_fields.sql
-- ====================================================================
-- Extends `workplan_objectives` to support the integrated role-based
-- cascading workplan system (MD -> Department -> Section -> Subsection):
--
--   1. `performance_contract_id` becomes NULLABLE so organisation-level
--      Managing Director workplan activities can anchor directly to a
--      strategic goal / target instead of being force-linked to some
--      department's performance contract. Existing rows are untouched;
--      the foreign key itself is preserved.
--   2. Adds an explicit `level` ENUM('organisation','department','section','subsection')
--      describing where the activity sits inside the cascade, backfilled
--      from the existing section/subsection/contract data.
--   3. Adds `created_by` (users.id) recording which user created each
--      activity, for provenance and audit purposes.
--
-- IDEMPOTENT + ADDITIVE: every statement is guarded so re-running is
-- safe, and NO existing data is modified or removed.
--
-- Apply (one time):  mysql -u root -p muwasco < this file
-- ====================================================================

-- --------------------------------------------------------------------
-- 1. Allow organisation-level activities without a department contract
-- --------------------------------------------------------------------
SET @wpo_pc_nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'workplan_objectives'
      AND COLUMN_NAME  = 'performance_contract_id'
);
SET @sql := IF(@wpo_pc_nullable = 'NO',
    'ALTER TABLE workplan_objectives MODIFY performance_contract_id INT(11) NULL COMMENT ''Department performance contract backing this activity (NULL = organisation-level activity)''',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 2. Explicit cascade level column (+ backfill)
-- --------------------------------------------------------------------
SET @wpo_level_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'workplan_objectives'
      AND COLUMN_NAME  = 'level'
);
SET @sql := IF(@wpo_level_exists = 0,
    'ALTER TABLE workplan_objectives ADD COLUMN level ENUM(''organisation'',''department'',''section'',''subsection'') NULL COMMENT ''Position in the organisational cascade'' AFTER subsection_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill (most specific first). Rows already labelled are left alone,
-- so re-running never rewrites anything.
UPDATE workplan_objectives
   SET level = 'subsection'
 WHERE subsection_id IS NOT NULL
   AND (level IS NULL OR level = '');

UPDATE workplan_objectives
   SET level = 'section'
 WHERE section_id IS NOT NULL AND subsection_id IS NULL
   AND (level IS NULL OR level = '');

UPDATE workplan_objectives
   SET level = 'department'
 WHERE section_id IS NULL AND subsection_id IS NULL
   AND performance_contract_id IS NOT NULL
   AND (level IS NULL OR level = '');

-- Remaining unlabelled rows are treated as organisation-level only when
-- they carry strategic alignment but no unit assignment.
UPDATE workplan_objectives
   SET level = 'organisation'
 WHERE section_id IS NULL AND subsection_id IS NULL
   AND performance_contract_id IS NULL
   AND (goal_id IS NOT NULL OR strategic_target_id IS NOT NULL)
   AND (level IS NULL OR level = '');

-- --------------------------------------------------------------------
-- 3. Creator provenance
-- --------------------------------------------------------------------
SET @wpo_created_by_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'workplan_objectives'
      AND COLUMN_NAME  = 'created_by'
);
SET @sql := IF(@wpo_created_by_exists = 0,
    'ALTER TABLE workplan_objectives ADD COLUMN created_by INT(11) NULL COMMENT ''User who created the objective'' AFTER responsible_officer_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------------------
-- 4. Indexes (idempotent)
-- --------------------------------------------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_level');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_level (level)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_created_by');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_created_by (created_by)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS wp_add_col_if_missing;

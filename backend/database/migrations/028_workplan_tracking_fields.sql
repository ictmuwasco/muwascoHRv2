-- 028_workplan_tracking_fields.sql
-- ====================================================================
-- Extends `workplan_objectives` with progress-tracking, resource
-- planning, evidence, ownership and integration fields required by
-- the unified workplan management system. All additions are nullable
-- or carry safe defaults so the existing 194 objectives are unaffected.
--
-- Also adds `soft_deleted` for the soft-delete pattern used across
-- the Strategy & Performance module.
--
-- IDEMPOTENT: each column is added only when it does not already exist.
-- Apply (one time):  mysql -u root -p muwasco < this file
-- ====================================================================

DROP PROCEDURE IF EXISTS wp_add_col_if_missing;
DELIMITER //
CREATE PROCEDURE wp_add_col_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def   VARCHAR(255)
)
SQL SECURITY INVOKER
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = p_table
      AND COLUMN_NAME  = p_column;

    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- === Strategic alignment (denormalised for fast grouping in the integrated view) ===
-- strategic_target_id placed FIRST so it is adjacent to performance_contract_id
CALL wp_add_col_if_missing('workplan_objectives', 'strategic_target_id',
    'INT(11) NULL COMMENT ''Organisation-level strategic target'' FIRST');
CALL wp_add_col_if_missing('workplan_objectives', 'goal_id',
    'INT(11) NULL COMMENT ''Strategic goal perspective (goals.id)''');
CALL wp_add_col_if_missing('workplan_objectives', 'parent_objective_id',
    'INT(11) NULL COMMENT ''Self-referencing FK to parent objective''');

-- === Progress tracking ===
CALL wp_add_col_if_missing('workplan_objectives', 'progress_percent',
    'TINYINT(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''0-100 completion percentage''');
CALL wp_add_col_if_missing('workplan_objectives', 'status',
    'ENUM(''not_started'',''in_progress'',''completed'',''at_risk'',''off_track'') NOT NULL DEFAULT ''not_started''');
CALL wp_add_col_if_missing('workplan_objectives', 'evidence_path',
    'TEXT NULL COMMENT ''Path or reference to uploaded evidence''');

-- === Resource / budget allocation ===
CALL wp_add_col_if_missing('workplan_objectives', 'budget_amount',
    'DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''Allocated budget''');
CALL wp_add_col_if_missing('workplan_objectives', 'resource_notes',
    'TEXT NULL COMMENT ''Free-text resource / funding notes''');

-- === Responsibility (beyond section/subsection) ===
CALL wp_add_col_if_missing('workplan_objectives', 'responsible_officer_id',
    'INT(11) NULL COMMENT ''Employee responsible (employees.id)''');

-- === Timeline tracking ===
CALL wp_add_col_if_missing('workplan_objectives', 'planned_start_date',
    'DATE NULL COMMENT ''Plan start date''');
CALL wp_add_col_if_missing('workplan_objectives', 'planned_end_date',
    'DATE NULL COMMENT ''Plan end date''');
CALL wp_add_col_if_missing('workplan_objectives', 'actual_completion_date',
    'DATE NULL COMMENT ''Actual completion date''');

-- === Cross-departmental dependency mapping ===
CALL wp_add_col_if_missing('workplan_objectives', 'dependencies',
    'JSON NULL COMMENT ''JSON array of cross-workplan dependency links''');

-- === Integration flag ===
CALL wp_add_col_if_missing('workplan_objectives', 'is_integrated',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = visible in org-level integrated view''');

-- === Soft-delete flag ===
CALL wp_add_col_if_missing('workplan_objectives', 'soft_deleted',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Soft-delete flag''');

-- === Indexes for performant filtering (all idempotent) ===
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_status');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_status (status)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_goal');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_goal (goal_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_target');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_target (strategic_target_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_parent');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_parent (parent_objective_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_officer');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_officer (responsible_officer_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_integrated');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_integrated (is_integrated)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
               AND INDEX_NAME = 'idx_wpo_soft_deleted');
SET @sql := IF(@idx = 0, 'ALTER TABLE workplan_objectives ADD INDEX idx_wpo_soft_deleted (soft_deleted)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === Foreign key: parent_objective_id (self-reference, ON DELETE SET NULL) ===
SET @fk := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workplan_objectives'
              AND REFERENCED_TABLE_NAME = 'workplan_objectives'
              AND CONSTRAINT_NAME = 'fk_wpo_parent_objective');
SET @sql := IF(@fk = 0,
    'ALTER TABLE workplan_objectives ADD CONSTRAINT fk_wpo_parent_objective FOREIGN KEY (parent_objective_id) REFERENCES workplan_objectives(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS wp_add_col_if_missing;



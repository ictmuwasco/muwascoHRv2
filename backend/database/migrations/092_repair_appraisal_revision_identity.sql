-- ============================================================================
-- 092: Repair appraisal revision identity for databases migrated before the
-- 091 AUTO_INCREMENT guard was corrected.
--
-- This migration is additive/idempotent: it only changes the revision-log
-- primary key column and adds the lookup index when absent. It does not delete
-- or rewrite appraisal history.
-- ============================================================================

SET @revision_auto := (
  SELECT EXTRA FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appraisal_revision_log'
    AND COLUMN_NAME = 'id'
);
SET @sql := IF(
  COALESCE(@revision_auto, '') NOT LIKE '%auto_increment%',
  'ALTER TABLE appraisal_revision_log MODIFY id INT NOT NULL AUTO_INCREMENT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @revision_index_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'appraisal_revision_log'
    AND INDEX_NAME = 'idx_arl_original_created'
);
SET @sql := IF(
  @revision_index_exists = 0,
  'ALTER TABLE appraisal_revision_log ADD INDEX idx_arl_original_created (original_appraisal_id, created_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'appraisal_revision_identity_repair' AS migration_check,
       (SELECT EXTRA FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'appraisal_revision_log'
          AND COLUMN_NAME = 'id') AS revision_id_extra,
       (SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'appraisal_revision_log'
          AND INDEX_NAME = 'idx_arl_original_created') AS revision_index_rows;

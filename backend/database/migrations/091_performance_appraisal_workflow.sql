-- ============================================================================
-- 091: Performance appraisal workflow governance
--
-- Adds explicit appraisal actions, preserves duplicate score rows before
-- enforcing one active score per appraisal/indicator pair, and makes revision
-- history insertable. Review/back up the live database before applying.
-- ============================================================================

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
  ('super_admin','performance','supervise',1), ('hr_manager','performance','supervise',1),
  ('managing_director','performance','supervise',1), ('dept_head','performance','supervise',1),
  ('section_head','performance','supervise',1), ('sub_section_head','performance','supervise',1),
  ('manager','performance','supervise',1), ('officer','performance','supervise',0),
  ('employee','performance','supervise',0), ('bod_chairman','performance','supervise',0),
  ('super_admin','performance','score',1), ('hr_manager','performance','score',1),
  ('managing_director','performance','score',1), ('dept_head','performance','score',1),
  ('section_head','performance','score',1), ('sub_section_head','performance','score',1),
  ('manager','performance','score',1), ('officer','performance','score',0),
  ('employee','performance','score',0), ('bod_chairman','performance','score',0),
  ('super_admin','performance','approve',1), ('hr_manager','performance','approve',1),
  ('managing_director','performance','approve',1), ('dept_head','performance','approve',1),
  ('section_head','performance','approve',0), ('sub_section_head','performance','approve',0),
  ('manager','performance','approve',0), ('officer','performance','approve',0),
  ('employee','performance','approve',0), ('bod_chairman','performance','approve',0),
  ('super_admin','performance','feedback',1), ('hr_manager','performance','feedback',1),
  ('managing_director','performance','feedback',1), ('dept_head','performance','feedback',1),
  ('section_head','performance','feedback',1), ('sub_section_head','performance','feedback',1),
  ('manager','performance','feedback',1), ('officer','performance','feedback',1),
  ('employee','performance','feedback',1), ('bod_chairman','performance','feedback',1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

UPDATE user_page_permissions upp
JOIN users u ON u.id = upp.user_id
SET upp.active = 0,
    upp.notes = CONCAT(COALESCE(upp.notes, ''), ' [deactivated by migration 091: appraisal supervisory hard policy]')
WHERE upp.active = 1 AND upp.permission_type = 'allow'
  AND u.role IN ('officer', 'employee', 'bod_chairman')
  AND upp.module = 'performance' AND upp.action IN ('supervise', 'score', 'approve');

CREATE TABLE IF NOT EXISTS appraisal_score_archive (
  id INT NOT NULL AUTO_INCREMENT,
  original_score_id INT NOT NULL,
  employee_appraisal_id INT NOT NULL,
  performance_indicator_id INT NOT NULL,
  score DECIMAL(10,4) DEFAULT NULL,
  appraiser_comment TEXT DEFAULT NULL,
  original_created_at TIMESTAMP NULL DEFAULT NULL,
  original_updated_at TIMESTAMP NULL DEFAULT NULL,
  archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  archive_reason VARCHAR(100) NOT NULL DEFAULT 'legacy_duplicate',
  PRIMARY KEY (id),
  UNIQUE KEY uq_appraisal_score_archive_original (original_score_id),
  KEY idx_as_archive_pair (employee_appraisal_id, performance_indicator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO appraisal_score_archive
  (original_score_id, employee_appraisal_id, performance_indicator_id,
   score, appraiser_comment, original_created_at, original_updated_at)
SELECT s.id, s.employee_appraisal_id, s.performance_indicator_id,
       s.score, s.appraiser_comment, s.created_at, s.updated_at
FROM appraisal_scores s
JOIN (
  SELECT employee_appraisal_id, performance_indicator_id,
         MAX(CONCAT(COALESCE(DATE_FORMAT(updated_at, '%Y%m%d%H%i%s'), DATE_FORMAT(created_at, '%Y%m%d%H%i%s'), '00000000000000'), ':', LPAD(id, 10, '0'))) AS keep_key
  FROM appraisal_scores
  GROUP BY employee_appraisal_id, performance_indicator_id
  HAVING COUNT(*) > 1
) grouped ON grouped.employee_appraisal_id = s.employee_appraisal_id
          AND grouped.performance_indicator_id = s.performance_indicator_id
WHERE CONCAT(COALESCE(DATE_FORMAT(s.updated_at, '%Y%m%d%H%i%s'), DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '00000000000000'), ':', LPAD(s.id, 10, '0')) <> grouped.keep_key;

DELETE s FROM appraisal_scores s
JOIN (
  SELECT employee_appraisal_id, performance_indicator_id,
         MAX(CONCAT(COALESCE(DATE_FORMAT(updated_at, '%Y%m%d%H%i%s'), DATE_FORMAT(created_at, '%Y%m%d%H%i%s'), '00000000000000'), ':', LPAD(id, 10, '0'))) AS keep_key
  FROM appraisal_scores
  GROUP BY employee_appraisal_id, performance_indicator_id
  HAVING COUNT(*) > 1
) grouped ON grouped.employee_appraisal_id = s.employee_appraisal_id
          AND grouped.performance_indicator_id = s.performance_indicator_id
WHERE CONCAT(COALESCE(DATE_FORMAT(s.updated_at, '%Y%m%d%H%i%s'), DATE_FORMAT(s.created_at, '%Y%m%d%H%i%s'), '00000000000000'), ':', LPAD(s.id, 10, '0')) <> grouped.keep_key;

SET @score_unique_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_scores'
    AND INDEX_NAME = 'uq_appraisal_score_indicator');
SET @sql := IF(@score_unique_exists = 0,
  'ALTER TABLE appraisal_scores ADD UNIQUE KEY uq_appraisal_score_indicator (employee_appraisal_id, performance_indicator_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Legacy revision history had no primary key and a non-auto-increment id.
-- Add the key before enabling AUTO_INCREMENT.
SET @revision_pk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_revision_log'
    AND CONSTRAINT_NAME = 'PRIMARY' AND CONSTRAINT_TYPE = 'PRIMARY KEY');
SET @sql := IF(@revision_pk_exists = 0,
  'ALTER TABLE appraisal_revision_log ADD PRIMARY KEY (id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @revision_auto := (SELECT EXTRA FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_revision_log'
    AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%');
SET @sql := IF(COALESCE(@revision_auto, '') NOT LIKE '%auto_increment%',
  'ALTER TABLE appraisal_revision_log MODIFY id INT NOT NULL AUTO_INCREMENT',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @revision_index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_revision_log'
    AND INDEX_NAME = 'idx_arl_original_created');
SET @sql := IF(@revision_index_exists = 0,
  'ALTER TABLE appraisal_revision_log ADD INDEX idx_arl_original_created (original_appraisal_id, created_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'appraisal_workflow_governance' AS migration_check,
       (SELECT COUNT(*) FROM appraisal_score_archive) AS archived_score_rows,
       (SELECT COUNT(*) FROM role_permissions WHERE module = 'performance'
          AND action IN ('supervise','score','approve','feedback')) AS appraisal_permission_rows;


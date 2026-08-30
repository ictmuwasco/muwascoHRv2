-- 031_appraisal_cycles_financial_year.sql
-- ====================================================================
-- Links every appraisal cycle to a financial year so quarters are scoped
-- to the correct workplan period (mirrors how performance contracts are
-- permanently tied to financial_years).
--
-- SAFE + IDEMPOTENT: adds `financial_year_id`, backfills existing cycles
-- using their start_date range, then makes it NOT NULL with an FK.
-- No data is removed.
-- ====================================================================

SET @ac_fy := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'appraisal_cycles'
      AND COLUMN_NAME  = 'financial_year_id'
);
SET @sql := IF(@ac_fy = 0,
    'ALTER TABLE appraisal_cycles ADD COLUMN financial_year_id INT(11) NULL AFTER end_date',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: match each cycle to the financial year containing its start date.
UPDATE appraisal_cycles c
LEFT JOIN financial_years fy
       ON c.start_date BETWEEN fy.start_date AND fy.end_date
   SET c.financial_year_id = fy.id
 WHERE c.financial_year_id IS NULL;

-- Any cycle whose dates fall outside every financial year: attach to the
-- most recently started financial year (so no NULLs remain).
UPDATE appraisal_cycles c
   SET c.financial_year_id = (
       SELECT MAX(fy.id) FROM financial_years fy
       WHERE c.start_date >= fy.start_date OR c.start_date <= fy.end_date
   )
 WHERE c.financial_year_id IS NULL;

-- Now enforce it: every cycle must belong to a financial year.
SET @ac_fy_null := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'appraisal_cycles'
      AND COLUMN_NAME  = 'financial_year_id'
);
SET @sql := IF(@ac_fy_null = 'YES',
    'ALTER TABLE appraisal_cycles MODIFY financial_year_id INT(11) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index + foreign key (idempotent).
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_cycles'
               AND INDEX_NAME = 'idx_ac_fy');
SET @sql := IF(@idx = 0, 'ALTER TABLE appraisal_cycles ADD INDEX idx_ac_fy (financial_year_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_cycles'
              AND REFERENCED_TABLE_NAME = 'financial_years');
SET @sql := IF(@fk = 0,
    'ALTER TABLE appraisal_cycles ADD CONSTRAINT fk_ac_financial_year FOREIGN KEY (financial_year_id) REFERENCES financial_years(id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
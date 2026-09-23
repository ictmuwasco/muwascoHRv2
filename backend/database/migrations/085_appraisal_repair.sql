-- ===========================================================================
-- 085: Appraisal repair
--
-- Repairs the appraisal dimension after `appraisal_cycles` was wiped without
-- cleaning dependents:
--
--   1. `employee_appraisals` had 36 rows referencing appraisal_cycle_id 1-4
--      while `appraisal_cycles` had 0 rows (phantom-cycle references — which
--      also left every workplan quarter picker empty).
--   2. `appraisal_scores` had 5 orphan rows (1 score pointing at a missing
--      appraisal, 4 pointing at deleted performance indicators).
--   3. `employee_appraisals` and `appraisal_scores` carried NO foreign keys,
--      so nothing prevents this class of corruption from recurring.
--
-- Repair order (idempotent, dependency-safe):
--   a) Re-seed the four FY2025/26 quarters at ids 1-4 (matching the historic
--      cycle ids and the table's auto_increment=5, so no new id collides).
--      All four marked 'completed': FY2025/26 ended 2026-06-30.
--   b) Remove the 5 orphaned appraisal_scores rows (never renderable, and
--      they would block the FKs added below).
--   c) Add the missing FKs so orphan creation is impossible going forward.
--   d) Indexes for the appraisal read paths.
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- (a) Re-seed FY2025/26 quarters at ids 1-4 (only where an id is free).
--     FY2025/26 = the financial year covering 2025-07-01 .. 2026-06-30.
--
--     The id is RESOLVED from `financial_years` rather than hard-coded to 30:
--     `appraisal_cycles.financial_year_id` carries an FK to `financial_years(id)`
--     (fk_ac_financial_year), so inserting a literal id that does not exist made
--     the whole migration abort with "Cannot add or update a child row: a
--     foreign key constraint fails" on any database whose financial_years is
--     still empty (0000_baseline_schema.sql ships schema only, no data rows).
--     When the year is absent the seed is skipped instead of failing.
-- ---------------------------------------------------------------------------
SET @fy_2025 := (
    SELECT id FROM financial_years
    WHERE start_date = '2025-07-01' AND end_date = '2026-06-30'
    ORDER BY id
    LIMIT 1
);

INSERT INTO appraisal_cycles (id, name, start_date, end_date, status, financial_year_id, created_at, updated_at)
SELECT s.id, s.name, s.start_date, s.end_date, s.status, @fy_2025, NOW(), NOW()
FROM (
    SELECT 1 AS id, 'Q1 2025/26' AS name, '2025-07-01' AS start_date, '2025-09-30' AS end_date,
           'completed' AS status
    UNION ALL SELECT 2, 'Q2 2025/26', '2025-10-01', '2025-12-31', 'completed'
    UNION ALL SELECT 3, 'Q3 2025/26', '2026-01-01', '2026-03-31', 'completed'
    UNION ALL SELECT 4, 'Q4 2025/26', '2026-04-01', '2026-06-30', 'completed'
) AS s
WHERE @fy_2025 IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM appraisal_cycles c WHERE c.id = s.id);

-- ---------------------------------------------------------------------------
-- (b) Remove orphaned appraisal_scores rows.
-- ---------------------------------------------------------------------------
-- Scores whose parent appraisal no longer exists (verified: 1 row).
DELETE s FROM appraisal_scores s
LEFT JOIN employee_appraisals a ON s.employee_appraisal_id = a.id
WHERE a.id IS NULL;

-- Scores whose performance indicator no longer exists (verified: 4 rows).
DELETE s FROM appraisal_scores s
LEFT JOIN performance_indicators p ON s.performance_indicator_id = p.id
WHERE p.id IS NULL;

-- ---------------------------------------------------------------------------
-- (c) Foreign keys so the corruption cannot recur. Each constraint is added
--     only when information_schema reports it missing, which keeps the
--     migration idempotent: a partially applied run can be retried without
--     duplicate-constraint-name errors.
-- ---------------------------------------------------------------------------
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_appraisals'
              AND CONSTRAINT_NAME = 'fk_ea_cycle' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE employee_appraisals ADD CONSTRAINT fk_ea_cycle FOREIGN KEY (appraisal_cycle_id) REFERENCES appraisal_cycles (id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_appraisals'
              AND CONSTRAINT_NAME = 'fk_ea_employee' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE employee_appraisals ADD CONSTRAINT fk_ea_employee FOREIGN KEY (employee_id) REFERENCES employees (id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_appraisals'
              AND CONSTRAINT_NAME = 'fk_ea_appraiser' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE employee_appraisals ADD CONSTRAINT fk_ea_appraiser FOREIGN KEY (appraiser_id) REFERENCES employees (id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_scores'
              AND CONSTRAINT_NAME = 'fk_as_appraisal' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE appraisal_scores ADD CONSTRAINT fk_as_appraisal FOREIGN KEY (employee_appraisal_id) REFERENCES employee_appraisals (id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_scores'
              AND CONSTRAINT_NAME = 'fk_as_indicator' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE appraisal_scores ADD CONSTRAINT fk_as_indicator FOREIGN KEY (performance_indicator_id) REFERENCES performance_indicators (id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- (d) Indexes for the appraisal read paths (cycle+status filters, score joins).
--     Guarded like the FKs above so a re-run stays clean.
-- ---------------------------------------------------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_appraisals'
               AND INDEX_NAME = 'idx_ea_cycle_status');
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_ea_cycle_status ON employee_appraisals (appraisal_cycle_id, status)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_scores'
               AND INDEX_NAME = 'idx_as_appraisal');
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_as_appraisal ON appraisal_scores (employee_appraisal_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appraisal_scores'
               AND INDEX_NAME = 'idx_as_indicator');
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_as_indicator ON appraisal_scores (performance_indicator_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

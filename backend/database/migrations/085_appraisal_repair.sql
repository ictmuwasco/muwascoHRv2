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
--     FY2025/26 = financial_years.id 30 (2025-07-01 .. 2026-06-30).
-- ---------------------------------------------------------------------------
INSERT INTO appraisal_cycles (id, name, start_date, end_date, status, financial_year_id, created_at, updated_at)
SELECT s.id, s.name, s.start_date, s.end_date, s.status, s.financial_year_id, NOW(), NOW()
FROM (
    SELECT 1 AS id, 'Q1 2025/26' AS name, '2025-07-01' AS start_date, '2025-09-30' AS end_date,
           'completed' AS status, 30 AS financial_year_id
    UNION ALL SELECT 2, 'Q2 2025/26', '2025-10-01', '2025-12-31', 'completed', 30
    UNION ALL SELECT 3, 'Q3 2025/26', '2026-01-01', '2026-03-31', 'completed', 30
    UNION ALL SELECT 4, 'Q4 2025/26', '2026-04-01', '2026-06-30', 'completed', 30
) AS s
WHERE NOT EXISTS (SELECT 1 FROM appraisal_cycles c WHERE c.id = s.id);

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
-- (c) Foreign keys so the corruption cannot recur. MariaDB has no
--     "ADD CONSTRAINT IF NOT EXISTS" — on a re-run the duplicate-name error
--     marks the migration as applied, which is the desired behaviour.
-- ---------------------------------------------------------------------------
ALTER TABLE employee_appraisals
    ADD CONSTRAINT fk_ea_cycle
        FOREIGN KEY (appraisal_cycle_id) REFERENCES appraisal_cycles (id),
    ADD CONSTRAINT fk_ea_employee
        FOREIGN KEY (employee_id) REFERENCES employees (id),
    ADD CONSTRAINT fk_ea_appraiser
        FOREIGN KEY (appraiser_id) REFERENCES employees (id);

ALTER TABLE appraisal_scores
    ADD CONSTRAINT fk_as_appraisal
        FOREIGN KEY (employee_appraisal_id) REFERENCES employee_appraisals (id),
    ADD CONSTRAINT fk_as_indicator
        FOREIGN KEY (performance_indicator_id) REFERENCES performance_indicators (id);

-- ---------------------------------------------------------------------------
-- (d) Indexes for the appraisal read paths (cycle+status filters, score joins).
-- ---------------------------------------------------------------------------
CREATE INDEX idx_ea_cycle_status ON employee_appraisals (appraisal_cycle_id, status);
CREATE INDEX idx_as_appraisal ON appraisal_scores (employee_appraisal_id);
CREATE INDEX idx_as_indicator ON appraisal_scores (performance_indicator_id);

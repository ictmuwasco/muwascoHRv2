-- ===========================================================================
-- 086: Appraisal cycles for the CURRENT financial year
--
-- 085 restored the four FY2025/26 quarters (ids 1-4) that the 36 legacy
-- appraisals reference. Those are all `completed` and belong to a financial
-- year that ended 2026-06-30, so the moment the app moves into FY2026/27 there
-- is NO usable cycle: every appraisal-cycle picker (appraisals, workplan
-- quarters) is empty for the live period and no new appraisal can be linked
-- to a cycle.
--
-- This seeds the four quarters of FY2026/27 (financial_years.id 39,
-- 2026-07-01 .. 2027-06-30):
--
--   Q1 2026/27  2026-07-01 .. 2026-09-30  'active'    (in progress today)
--   Q2 2026/27  2026-10-01 .. 2026-12-31  'inactive'  (not yet opened)
--   Q3 2026/27  2027-01-01 .. 2027-03-31  'inactive'
--   Q4 2026/27  2027-04-01 .. 2027-06-30  'inactive'
--
-- Idempotent: each row is inserted only when no cycle with the same
-- (financial_year_id, name) exists, so re-running is a no-op. Auto-increment
-- ids are assigned by the table (never forced), so nothing here can collide
-- with the historic ids 1-4.
-- ===========================================================================

INSERT INTO appraisal_cycles (name, start_date, end_date, status, financial_year_id, created_at, updated_at)
SELECT s.name, s.start_date, s.end_date, s.status, s.financial_year_id, NOW(), NOW()
FROM (
    SELECT 'Q1 2026/27' AS name, '2026-07-01' AS start_date, '2026-09-30' AS end_date,
           'active' AS status, 39 AS financial_year_id
    UNION ALL SELECT 'Q2 2026/27', '2026-10-01', '2026-12-31', 'inactive', 39
    UNION ALL SELECT 'Q3 2026/27', '2027-01-01', '2027-03-31', 'inactive', 39
    UNION ALL SELECT 'Q4 2026/27', '2027-04-01', '2027-06-30', 'inactive', 39
) AS s
WHERE EXISTS (SELECT 1 FROM financial_years fy WHERE fy.id = s.financial_year_id)
  AND NOT EXISTS (
      SELECT 1 FROM appraisal_cycles c
      WHERE c.financial_year_id = s.financial_year_id AND c.name = s.name
  );

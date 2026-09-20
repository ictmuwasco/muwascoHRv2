-- ===========================================================================
-- 087: Exactly ONE active financial year
--
-- The database had TWO rows with is_active = 1:
--   fy=30  2025/26  2025-07-01 .. 2026-06-30   (the year that already ENDED)
--   fy=39  2026/27  2026-07-01 .. 2027-06-30   (the live year)
--
-- A non-unique "active" year is not cosmetic: callers resolve the current year
-- with `WHERE is_active = 1 LIMIT 1` (without ORDER BY), so MySQL is free to
-- return the finished 2025/26 row. That maps new appraisal cycles, workplan
-- quarter pickers and dashboard roll-ups onto a closed financial year, which
-- is exactly the class of bug that emptied the cycle dimension in the first
-- place.
--
-- This keeps the row with the latest start_date active (2026/27) and clears
-- the flag on every other formerly-active year. Idempotent: after it runs once
-- the WHERE matches nothing, and re-running is a no-op.
-- ===========================================================================

UPDATE financial_years
   SET is_active = 0,
       updated_at = NOW()
 WHERE is_active = 1
   AND id <> (
       SELECT keep_id FROM (
           SELECT id AS keep_id
             FROM financial_years
            WHERE is_active = 1
            ORDER BY start_date DESC, id DESC
            LIMIT 1
       ) AS current_fy
   );

-- Backfill missing workplan cascade levels without changing existing labels.
-- Safe to re-run: the update only touches NULL or empty levels.

UPDATE workplan_objectives
   SET level = CASE
       WHEN subsection_id IS NOT NULL THEN 'subsection'
       WHEN section_id IS NOT NULL THEN 'section'
       WHEN performance_contract_id IS NOT NULL THEN 'department'
       WHEN goal_id IS NOT NULL OR strategic_target_id IS NOT NULL THEN 'organisation'
       ELSE level
   END
 WHERE level IS NULL OR level = '';

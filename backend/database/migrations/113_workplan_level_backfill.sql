-- Backfill missing workplan cascade levels without changing existing labels.
-- Safe to re-run: every update only touches NULL or empty levels.

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

UPDATE workplan_objectives
   SET level = 'organisation'
 WHERE section_id IS NULL AND subsection_id IS NULL
   AND performance_contract_id IS NULL
   AND (goal_id IS NOT NULL OR strategic_target_id IS NOT NULL)
   AND (level IS NULL OR level = '');

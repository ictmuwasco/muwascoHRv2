-- ============================================================================
-- Migration 089: Relate the Commercial (dept 2) workplan cascade chain
-- ----------------------------------------------------------------------------
-- The department's contract activities were linked to section-12 and Billing
-- KPIs (performance_indicators.activity_ids) but were NEVER cascaded down:
--   * section 12 (Revenue (Billing)) had ZERO workplan rows, and
--   * subsection 1 (Billing)           had ZERO workplan rows,
-- so the Section / Subsection workplan dashboards and the "Source Activity
-- (added by management)" picker were empty while the KPI page already
-- referenced the top-level rows — i.e. the chain "workplan -> KPI" was broken
-- in the data.
--
-- This migration materialises the missing cascade (parents stay intact):
--   Phase 1: section-level children under every contract activity referenced
--            by a section-12 KPI (65,66,67,70,71,88,89,90,91,100,146).
--   Phase 2: subsection-level (Billing) children under those section children
--            for activities referenced by Billing KPIs; activities with no
--            section-KPI reference (59,62,69) get their Billing child
--            directly under the contract activity (dept -> subsection is a
--            valid strict cascade step).
--
-- KPI activity_ids intentionally keep pointing at the ancestors — the KPI
-- activities pool unions KPI-linked ids (SectionalObjectiveController::
-- kpiLinkedActivityIds), so "Linked Objectives" labels resolve regardless.
--
-- Idempotent: every INSERT is guarded by NOT EXISTS, so re-running is safe.
-- ============================================================================

-- Phase 1: section-12 children (parent = the department's contract activity).
INSERT INTO workplan_objectives
    (performance_contract_id, objective, kpi, measure_unit, section_id, subsection_id,
     level, cycle_ids, goal_id, strategic_target_id, parent_objective_id,
     progress_percent, status, budget_amount, planned_start_date, planned_end_date,
     created_by, created_at, updated_at)
SELECT w.performance_contract_id, w.objective, w.kpi, w.measure_unit, 12, NULL,
       'section', w.cycle_ids, w.goal_id, w.strategic_target_id, w.id,
       0, 'not_started', 0, w.planned_start_date, w.planned_end_date,
       NULL, NOW(), NOW()
FROM workplan_objectives w
WHERE w.id IN (65, 66, 67, 70, 71, 88, 89, 90, 91, 100, 146)
  AND w.soft_deleted = 0
  AND NOT EXISTS (
      SELECT 1 FROM workplan_objectives c
      WHERE c.parent_objective_id = w.id
        AND c.section_id = 12
        AND c.subsection_id IS NULL
        AND c.soft_deleted = 0
  );

-- Phase 2a: Billing children under the section children that exist.
INSERT INTO workplan_objectives
    (performance_contract_id, objective, kpi, measure_unit, section_id, subsection_id,
     level, cycle_ids, goal_id, strategic_target_id, parent_objective_id,
     progress_percent, status, budget_amount, planned_start_date, planned_end_date,
     created_by, created_at, updated_at)
SELECT w.performance_contract_id, w.objective, w.kpi, w.measure_unit, 12, 1,
       'subsection', w.cycle_ids, w.goal_id, w.strategic_target_id, sc.id,
       0, 'not_started', 0, w.planned_start_date, w.planned_end_date,
       NULL, NOW(), NOW()
FROM workplan_objectives sc
JOIN workplan_objectives w ON w.id = sc.parent_objective_id
WHERE sc.parent_objective_id IN (65, 66, 88, 89, 90, 91, 100)
  AND sc.section_id = 12
  AND sc.subsection_id IS NULL
  AND sc.soft_deleted = 0
  AND w.soft_deleted = 0
  AND NOT EXISTS (
      SELECT 1 FROM workplan_objectives b
      WHERE b.parent_objective_id = sc.id
        AND b.subsection_id = 1
        AND b.soft_deleted = 0
  );

-- Phase 2b: Billing children directly under contract activities no section
-- KPI references (59, 62, 69).
INSERT INTO workplan_objectives
    (performance_contract_id, objective, kpi, measure_unit, section_id, subsection_id,
     level, cycle_ids, goal_id, strategic_target_id, parent_objective_id,
     progress_percent, status, budget_amount, planned_start_date, planned_end_date,
     created_by, created_at, updated_at)
SELECT w.performance_contract_id, w.objective, w.kpi, w.measure_unit, 12, 1,
       'subsection', w.cycle_ids, w.goal_id, w.strategic_target_id, w.id,
       0, 'not_started', 0, w.planned_start_date, w.planned_end_date,
       NULL, NOW(), NOW()
FROM workplan_objectives w
WHERE w.id IN (59, 62, 69)
  AND w.soft_deleted = 0
  AND NOT EXISTS (
      SELECT 1 FROM workplan_objectives b
      WHERE b.parent_objective_id = w.id
        AND b.subsection_id = 1
        AND b.soft_deleted = 0
  );

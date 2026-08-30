-- Strategy & Performance module role permissions (ADDITIVE)
-- Adds RBAC rows for the Strategy & Performance modules so the new
-- strategic-plan / performance-contract / workplan / kpi / sectional-objective
-- endpoints are governed by the existing hybrid authorization service.
--
-- No schema change is required: this only inserts rows into the existing
-- role_permissions table (unique key uk_role_module_action makes it idempotent).
--
-- Place: backend/database/migrations/027_strategy_performance_permissions.sql
--
-- Management of organisation-level strategy is additionally narrowed in code
-- (OrgScope::canManageStrategicPlan) to HR managers, super admins and the PME /
-- Audit department heads, mirroring the legacy strategic plan page rules.

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
-- Strategic plan: broadest management for HR / Super Admin / Managing Director
('super_admin', 'strategic_plan', 'view', 1),
('super_admin', 'strategic_plan', 'manage', 1),
('hr_manager', 'strategic_plan', 'view', 1),
('hr_manager', 'strategic_plan', 'manage', 1),
('managing_director', 'strategic_plan', 'view', 1),
('managing_director', 'strategic_plan', 'manage', 1),

-- Department / section / sub-section heads: visibility + departmental management
('dept_head', 'strategic_plan', 'view', 1),
('section_head', 'strategic_plan', 'view', 1),
('sub_section_head', 'strategic_plan', 'view', 1),
('manager', 'strategic_plan', 'view', 1),
('officer', 'strategic_plan', 'view', 1),
('employee', 'strategic_plan', 'view', 1),
('bod_chairman', 'strategic_plan', 'view', 1),

-- Performance contracts
('super_admin', 'performance_contract', 'view', 1),
('super_admin', 'performance_contract', 'manage', 1),
('hr_manager', 'performance_contract', 'view', 1),
('hr_manager', 'performance_contract', 'manage', 1),
('managing_director', 'performance_contract', 'view', 1),
('managing_director', 'performance_contract', 'manage', 1),
('dept_head', 'performance_contract', 'view', 1),
('dept_head', 'performance_contract', 'manage', 1),
('section_head', 'performance_contract', 'view', 1),
('section_head', 'performance_contract', 'manage', 1),
('sub_section_head', 'performance_contract', 'view', 1),
('sub_section_head', 'performance_contract', 'manage', 1),
('manager', 'performance_contract', 'view', 1),
('officer', 'performance_contract', 'view', 1),
('employee', 'performance_contract', 'view', 1),

-- Workplans
('super_admin', 'workplan', 'view', 1),
('super_admin', 'workplan', 'manage', 1),
('hr_manager', 'workplan', 'view', 1),
('hr_manager', 'workplan', 'manage', 1),
('managing_director', 'workplan', 'view', 1),
('managing_director', 'workplan', 'manage', 1),
('dept_head', 'workplan', 'view', 1),
('dept_head', 'workplan', 'manage', 1),
('section_head', 'workplan', 'view', 1),
('section_head', 'workplan', 'manage', 1),
('sub_section_head', 'workplan', 'view', 1),
('sub_section_head', 'workplan', 'manage', 1),
('manager', 'workplan', 'view', 1),
('officer', 'workplan', 'view', 1),

-- KPIs
('super_admin', 'kpi', 'view', 1),
('super_admin', 'kpi', 'manage', 1),
('hr_manager', 'kpi', 'view', 1),
('hr_manager', 'kpi', 'manage', 1),
('managing_director', 'kpi', 'view', 1),
('managing_director', 'kpi', 'manage', 1),
('dept_head', 'kpi', 'view', 1),
('dept_head', 'kpi', 'manage', 1),
('section_head', 'kpi', 'view', 1),
('section_head', 'kpi', 'manage', 1),
('sub_section_head', 'kpi', 'view', 1),
('sub_section_head', 'kpi', 'manage', 1),
('manager', 'kpi', 'view', 1),
('officer', 'kpi', 'view', 1),

-- Sectional objectives / performance indicators
('super_admin', 'sectional_objective', 'view', 1),
('super_admin', 'sectional_objective', 'manage', 1),
('hr_manager', 'sectional_objective', 'view', 1),
('hr_manager', 'sectional_objective', 'manage', 1),
('managing_director', 'sectional_objective', 'view', 1),
('managing_director', 'sectional_objective', 'manage', 1),
('dept_head', 'sectional_objective', 'view', 1),
('dept_head', 'sectional_objective', 'manage', 1),
('section_head', 'sectional_objective', 'view', 1),
('section_head', 'sectional_objective', 'manage', 1),
('sub_section_head', 'sectional_objective', 'view', 1),
('sub_section_head', 'sectional_objective', 'manage', 1),
('manager', 'sectional_objective', 'view', 1),
('officer', 'sectional_objective', 'view', 1)

ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);
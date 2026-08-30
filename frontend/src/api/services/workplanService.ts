import apiClient from '../client';
import type { ApiResponse } from '../../types';

/** Position of an activity within the organisational cascade. */
export type WorkplanLevel = 'organisation' | 'department' | 'section' | 'subsection';

export type WorkplanView = 'md' | 'department' | 'section' | 'subsection' | 'integrated';

export interface WorkplanObjective {
  id: number;
  performance_contract_id: number | null;
  objective: string;
  kpi: string;
  measure_unit: string;
  section_id: number | null;
  subsection_id: number | null;
  goal_id: number | null;
  strategic_target_id: number | null;
  parent_objective_id: number | null;
  responsible_officer_id: number | null;
  progress_percent: number;
  status: 'not_started' | 'in_progress' | 'completed' | 'at_risk' | 'off_track';
  evidence_path: string | null;
  budget_amount: number;
  resource_notes: string | null;
  planned_start_date: string | null;
  planned_end_date: string | null;
  actual_completion_date: string | null;
  dependencies: string | null;
  is_integrated: number;
  level: WorkplanLevel | null;
  created_by: number | null;
  cycle_ids: string | null;
  contract_name: string | null;
  department_name: string | null;
  section_name: string | null;
  subsection_name: string | null;
  goal_name: string | null;
  target_name: string | null;
  /** Lineage enrichment returned by the list endpoint. */
  parent_objective?: string | null;
  parent_level?: string | null;
  created_by_name?: string | null;
  officer_name?: string | null;
  children_count?: number;
  Y1: string | null;
  Y2: string | null;
  Y3: string | null;
   Y4: string | null;
   Y5: string | null;
}

/** A management-cascaded source activity that heads create children under. */
export interface WorkplanSource {
  id: number;
  objective: string;
  level: string;
  created_by_name?: string;
}

/** Employee available for assignment at the caller's organisational unit. */
export interface AssignableEmployee {
  id: number;
  employee_id: string;
  name: string;
  position: string | null;
  department_id: number | null;
  section_id: number | null;
  subsection_id: number | null;
  department_name: string | null;
  section_name: string | null;
  subsection_name: string | null;
}

export interface UnitRef {
  id: number;
  name: string;
  department_id?: number;
  section_id?: number;
}

export interface WorkplanList {
  workplans: WorkplanObjective[];
  can_manage: boolean;
  view: string;
  default_view: string;
  available_views: string[];
  sections: UnitRef[];
  subsections: UnitRef[];
  employees: AssignableEmployee[];
  scope: { role: string; department: number | null; section: number | null; subsection: number | null };
  pagination: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

export interface WorkplanTotals {
  total_activities: number;
  not_started: number;
  in_progress: number;
  completed: number;
  at_risk: number;
  off_track: number;
  completion_rate: number;
  cascaded_count: number;
  local_count: number;
  awaiting_action: number;
  overdue_count: number;
  budget_total: number;
}

export interface DeadlineItem {
  id: number;
  objective: string;
  level: string | null;
  status: string;
  progress_percent: number;
  planned_end_date: string | null;
  section_name: string | null;
  subsection_name: string | null;
}

export interface RecentItem extends Omit<DeadlineItem, 'planned_end_date'> {
  updated_at: string;
}

export interface WorkplanSummary {
  view: string;
  default_view: string;
  available_views: string[];
  can_manage: boolean;
  unit_label: string;
  totals: WorkplanTotals;
  upcoming_deadlines: DeadlineItem[];
  recently_updated: RecentItem[];
  active_financial_year: { id: number; year_name: string; start_date: string; end_date: string } | null;
  scope: { role: string; department: number | null; section: number | null; subsection: number | null };
}

export interface TraceabilityNode {
  id: number;
  objective: string;
  level: string | null;
  status: string;
  progress_percent: number;
  owner?: string | null;
  contract_name?: string | null;
  children_count?: number;
  children?: TraceabilityNode[];
}

export interface TraceabilityResponse {
  objective: Record<string, any>;
  context: {
    strategic_plan: string | null;
    goal: string | null;
    target: string | null;
    performance_contract: string | null;
    kra: string | null;
    department: string | null;
    financial_year: string | null;
    section: string | null;
    subsection: string | null;
    parent_objective: string | null;
    created_by_name: string | null;
  };
  ancestors: TraceabilityNode[];
  descendants: TraceabilityNode[];
}

export interface IntegratedGoal {
  id: number;
  name: string;
  targets: {
    id: number;
    name: string;
    departments: {
      id: number;
      name: string;
      items: WorkplanObjective[];
    }[];
  }[];
}

export interface IntegratedView {
  goals: IntegratedGoal[];
  summary: {
    total_objectives: number;
    in_progress: number;
    completed: number;
    at_risk: number;
    overdue: number;
    budget_total: number;
  };
  can_manage: boolean;
}

export interface ProgressHistoryUpdate {
  id: number;
  action_type: string;
  progress_percent: number | null;
  status: string | null;
  evidence_path: string | null;
  description: string | null;
  old_values: Record<string, any> | null;
  new_values: Record<string, any> | null;
  actor_name: string | null;
  created_at: string;
}

export const workplanService = {
  list: async (params?: Record<string, any>): Promise<ApiResponse<WorkplanList>> => {
    const res = await apiClient.get<ApiResponse<WorkplanList>>('/workplans', { params });
    return res.data;
      },
  /**
   * Returns ONLY the management-cascaded source activities for the caller's
   * unit (section/subsection heads). The backend filters by unit scope,
   * parent_objective_id IS NOT NULL, and created_by != self — so a section
   * head never sees another department's cascaded work.
   */
  sectionSources: async (view: 'section' | 'subsection'): Promise<ApiResponse<{ sources: WorkplanSource[]; can_manage: boolean; view: string }>> => {
    const res = await apiClient.get<ApiResponse<{ sources: WorkplanSource[]; can_manage: boolean; view: string }>>(
      '/workplans/section-sources', { params: { view } }
    );
    return res.data;
  },
  /** Role-scoped dashboard aggregates for one workplan tier. */
  summary: async (view?: string, financialYearId?: string | number): Promise<ApiResponse<WorkplanSummary>> => {
    const params: Record<string, any> = {};
    if (view) params.view = view;
    if (financialYearId) params.financial_year_id = financialYearId;
    const res = await apiClient.get<ApiResponse<WorkplanSummary>>('/workplans/summary', { params });
    return res.data;
  },
  create: async (data: Record<string, any>): Promise<ApiResponse<{ id: number }>> => {
    const res = await apiClient.post<ApiResponse<{ id: number }>>('/workplans', data);
    return res.data;
  },
  /**
   * Legacy-parity batch creation for department heads: one contract, many
   * activities, each with its own section / subsection and appraisal cycles.
   */
  bulkCreate: async (data: {
    performance_contract_id: number;
    items: Record<string, any>[];
  }): Promise<ApiResponse<{ saved: number; failed: number }>> => {
    const res = await apiClient.post<ApiResponse<{ saved: number; failed: number }>>(
      '/workplans/bulk',
      data,
    );
    return res.data;
  },
  update: async (id: number, data: Record<string, any>): Promise<ApiResponse<null>> => {
    const res = await apiClient.put<ApiResponse<null>>(`/workplans/${id}`, data);
    return res.data;
  },
  remove: async (id: number): Promise<ApiResponse<null>> => {
    const res = await apiClient.delete<ApiResponse<null>>(`/workplans/${id}`);
    return res.data;
  },
  /**
   * Cascade an activity downward: creates a validated CHILD activity linked
   * to the parent via parent_objective_id (never a detached duplicate).
   */
  cascade: async (
    parentId: number,
    data: Record<string, any>,
  ): Promise<ApiResponse<{ id: number; level: string; parent_id: number }>> => {
    const res = await apiClient.post<ApiResponse<{ id: number; level: string; parent_id: number }>>(
      `/workplans/${parentId}/cascade`,
      data,
    );
    return res.data;
  },
  /** Full lineage: strategic context + ancestor chain + descendant tree. */
  traceability: async (id: number): Promise<ApiResponse<TraceabilityResponse>> => {
    const res = await apiClient.get<ApiResponse<TraceabilityResponse>>(`/workplans/${id}/traceability`);
    return res.data;
  },
  integratedView: async (): Promise<ApiResponse<IntegratedView>> => {
    const res = await apiClient.get<ApiResponse<IntegratedView>>('/workplans/integrated-view');
    return res.data;
  },
  progressHistory: async (id: number): Promise<ApiResponse<{ updates: ProgressHistoryUpdate[] }>> => {
    const res = await apiClient.get<ApiResponse<{ updates: ProgressHistoryUpdate[] }>>(
      `/workplans/${id}/progress-history`
    );
    return res.data;
  },
  updateProgress: async (id: number, data: Record<string, any>): Promise<ApiResponse<WorkplanObjective>> => {
    const res = await apiClient.put<ApiResponse<WorkplanObjective>>(`/workplans/${id}/progress`, data);
    return res.data;
  },
  dependencies: async (id: number): Promise<ApiResponse<{ dependencies: any[] }>> => {
    const res = await apiClient.get<ApiResponse<{ dependencies: any[] }>>(`/workplans/${id}/dependencies`);
    return res.data;
  },
  exportCsv: (): Promise<Blob> =>
    apiClient.get('/workplans/export', { responseType: 'blob' }).then((r) => r.data),
};
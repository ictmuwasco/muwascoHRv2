import apiClient from '../client';
import type { ApiResponse } from '../../types';

/**
 * Sectional objectives / KPIs (performance_indicators) — the scoring units
 * consumed by the appraisal workflow. Scoped server-side by role:
 * hr_manager (apex) sees everything; heads see their own unit.
 */
export interface Kpi {
  id: number;
  name: string;
  description: string | null;
  max_score: number;
  activity_ids: string | null;
  role: string | null;
  assigned_to_employee_ids: string | null;
  department_id: number | null;
  section_id: number | null;
  subsection_id: number | null;
  is_active: number;
  is_recurrent: number;
  created_by: number | null;
  created_by_name?: string | null;
  created_at: string;
  updated_at: string;
  department_name?: string | null;
  section_name?: string | null;
  subsection_name?: string | null;
}

/** Employee available for KPI assignment (scoped to the caller's role). */
export interface KpiEmployee {
  id: number;
  employee_id: string;
  first_name: string;
  last_name: string;
  email: string;
  employee_type: string | null;
  position: string | null;
  designation: string | null;
  department_id: number | null;
  section_id: number | null;
  subsection_id: number | null;
  department_name: string | null;
  section_name: string | null;
  subsection_name: string | null;
}

/** Workplan activity that can be linked to a KPI. */
export interface KpiActivity {
  id: number;
  objective: string;
  section_id: number | null;
  subsection_id: number | null;
  cycle_ids: string | null;
  parent_objective_id: number | null;
  contract_name: string | null;
  department_id: number | null;
  department_name: string | null;
  section_name: string | null;
  subsection_name: string | null;
  parent_objective: string | null;
}

export interface KpiCycle {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
}

export interface KpiListPayload {
  objectives: Kpi[];
  can_manage: boolean;
  employees: KpiEmployee[];
  activities: KpiActivity[];
  cycles: KpiCycle[];
  roles: string[];
  scope: {
    role: string;
    department: number | null;
    section: number | null;
    subsection: number | null;
  };
}

export interface KpiFormData {
  name: string;
  description?: string;
  max_score: number;
  activity_ids: number[];
  assigned_to_employee_ids: number[];
  is_active: boolean;
  is_recurrent: boolean;
}

export const kpiService = {
  list: async (): Promise<ApiResponse<KpiListPayload>> => {
    const res = await apiClient.get<ApiResponse<KpiListPayload>>('/sectional-objectives');
    return res.data;
  },
  getById: async (id: number): Promise<ApiResponse<Kpi>> => {
    const res = await apiClient.get<ApiResponse<Kpi>>(`/sectional-objectives/${id}`);
    return res.data;
  },
  create: async (data: KpiFormData): Promise<ApiResponse<{ id: number }>> => {
    const res = await apiClient.post<ApiResponse<{ id: number }>>('/sectional-objectives', data);
    return res.data;
  },
  /** Partial update — also used for the Active / Recurrent toggles. */
  update: async (id: number, data: Partial<KpiFormData>): Promise<ApiResponse<null>> => {
    const res = await apiClient.put<ApiResponse<null>>(`/sectional-objectives/${id}`, data);
    return res.data;
  },
  /** Soft delete (deactivate) — scoring history is preserved. */
  remove: async (id: number): Promise<ApiResponse<null>> => {
    const res = await apiClient.delete<ApiResponse<null>>(`/sectional-objectives/${id}`);
    return res.data;
  },
};

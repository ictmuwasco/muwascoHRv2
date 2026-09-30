import apiClient from '../client';
import type { ApiResponse, Appraisal, AppraisalFormData, PaginatedResponse } from '../../types';

export interface AppraisalWorkflowRow {
  id: number;
  status: string;
  status_label?: string;
  employee_id: number;
  employee_name?: string;
  employee_code?: string;
  employee_type?: string;
  department_name?: string | null;
  section_name?: string | null;
  subsection_name?: string | null;
  appraiser_name?: string;
  cycle_name?: string;
  start_date?: string;
  end_date?: string;
  employee_comment?: string | null;
  employee_satisfied?: number;
  employee_comment_date?: string | null;
  supervisors_comment?: string | null;
  satisfaction_label?: string;
  escalation_level?: string | null;
  dept_head_decision?: string | null;
  dept_head_comment?: string | null;
  total_score?: number;
  total_max_score?: number;
  score_percentage?: number;
  can_edit_scores?: boolean;
  created_at?: string;
}

export interface AppraisalIndicator {
  id: number;
  name: string;
  max_score: number;
  activities: Array<{ id: number; name: string; contract_name?: string | null }>;
  score: number | null;
  appraiser_comment: string;
  score_id?: number | null;
}

export interface AppraisalDetail extends AppraisalWorkflowRow {
  indicators: AppraisalIndicator[];
  scores: Array<Record<string, unknown>>;
}

export interface AppraisalWorkspace {
  employees: Array<{ id: number; employee_id: string; first_name: string; last_name: string; employee_type: string; department_name?: string | null; section_name?: string | null }>;
  cycles: Array<{ id: number; name: string; start_date: string; end_date: string; status: string }>;
  counts: { regular: number; pending: number; escalated: number; rejected: number };
  permissions: { supervise: boolean; score: boolean; approve: boolean };
}

export const appraisalService = {
  workspace: async (): Promise<AppraisalWorkspace> => {
    const response = await apiClient.get<ApiResponse<AppraisalWorkspace>>('/appraisals/workspace');
    return response.data.data;
  },
  list: async (tab: 'regular' | 'pending' | 'escalated' | 'rejected'): Promise<AppraisalWorkflowRow[]> => {
    const response = await apiClient.get<ApiResponse<AppraisalWorkflowRow[]>>(`/appraisals/supervisor/${tab}`);
    return response.data.data;
  },
  detail: async (tab: string, id: number): Promise<AppraisalDetail> => {
    const response = await apiClient.get<ApiResponse<AppraisalDetail>>(`/appraisals/supervisor/${tab}/${id}`);
    return response.data.data;
  },
  create: async (data: { employee_id: number; appraisal_cycle_id: number }): Promise<number> => {
    const response = await apiClient.post<ApiResponse<{ id: number }>>('/appraisals', data);
    return response.data.data.id;
  },
  saveScores: async (id: number, data: { scores: Record<number, number>; comments: Record<number, string>; supervisor_comment: string }): Promise<void> => {
    await apiClient.put(`/appraisals/${id}/scores`, data);
  },
  my: async (): Promise<AppraisalWorkflowRow[]> => {
    const response = await apiClient.get<ApiResponse<AppraisalWorkflowRow[]>>('/appraisals/my');
    return response.data.data;
  },
  myDetail: async (id: number): Promise<AppraisalDetail> => {
    const response = await apiClient.get<ApiResponse<AppraisalDetail>>(`/appraisals/my/${id}`);
    return response.data.data;
  },
  submitFeedback: async (id: number, data: { employee_comment: string; employee_satisfied: number }): Promise<void> => {
    await apiClient.post(`/appraisals/my/${id}/feedback`, data);
  },
  decide: async (id: number, data: { decision: string; comment: string }): Promise<void> => {
    await apiClient.put(`/appraisals/${id}/decision`, data);
  },
  getAll: async (params?: Record<string, unknown>): Promise<ApiResponse<PaginatedResponse<Appraisal>>> => {
    const response = await apiClient.get<ApiResponse<PaginatedResponse<Appraisal>>>('/appraisals', { params });
    return response.data;
  },
  getById: async (id: number): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.get<ApiResponse<Appraisal>>(`/appraisals/${id}`);
    return response.data;
  },
  update: async (id: number, data: Partial<AppraisalFormData>): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.put<ApiResponse<Appraisal>>(`/appraisals/${id}`, data);
    return response.data;
  },
  submit: async (id: number): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.put<ApiResponse<Appraisal>>(`/appraisals/${id}/submit`);
    return response.data;
  },
  approve: async (id: number): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.put<ApiResponse<Appraisal>>(`/appraisals/${id}/approve`);
    return response.data;
  },
  getPending: async (): Promise<ApiResponse<Appraisal[]>> => {
    const response = await apiClient.get<ApiResponse<Appraisal[]>>('/appraisals/pending');
    return response.data;
  },
  getByEmployee: async (employeeId: number): Promise<ApiResponse<Appraisal[]>> => {
    const response = await apiClient.get<ApiResponse<Appraisal[]>>(`/appraisals/employee/${employeeId}`);
    return response.data;
  },
};


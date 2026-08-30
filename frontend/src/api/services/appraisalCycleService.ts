import apiClient from '../client';
import type { ApiResponse } from '../../types';

export interface AppraisalCycle {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  status: 'active' | 'inactive' | 'completed';
  /** Financial year this quarter belongs to (required). */
  financial_year_id: number;
  financial_year_name?: string | null;
  created_at: string;
  updated_at: string;
}

export interface FinancialYearRef {
  id: number;
  year_name: string;
  start_date: string;
  end_date: string;
  is_active: number;
}

const fmt = (d: string | null | undefined) =>
  d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '';

/** Label helper shared by every quarter picker ("Q1 2025/2026 · Jul–Sep 2025"). */
export const cycleLabel = (c: Pick<AppraisalCycle, 'name' | 'start_date' | 'end_date'>): string => {
  const range = c.start_date && c.end_date ? `${fmt(c.start_date)} – ${fmt(c.end_date)}` : '';
  return range ? `${c.name} (${range})` : c.name;
};

export const appraisalCycleService = {
  list: async (): Promise<ApiResponse<{ cycles: AppraisalCycle[]; financial_years: FinancialYearRef[] }>> => {
    const res = await apiClient.get<ApiResponse<{ cycles: AppraisalCycle[]; financial_years: FinancialYearRef[] }>>('/appraisal-cycles');
    return res.data;
  },
  create: async (data: Record<string, any>): Promise<ApiResponse<{ id: number }>> => {
    const res = await apiClient.post<ApiResponse<{ id: number }>>('/appraisal-cycles', data);
    return res.data;
  },
  update: async (id: number, data: Record<string, any>): Promise<ApiResponse<null>> => {
    const res = await apiClient.put<ApiResponse<null>>(`/appraisal-cycles/${id}`, data);
    return res.data;
  },
  remove: async (id: number): Promise<ApiResponse<null>> => {
    const res = await apiClient.delete<ApiResponse<null>>(`/appraisal-cycles/${id}`);
    return res.data;
  },
};

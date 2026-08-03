import apiClient from '../client';
import type { ApiResponse, StrategicPlan, Workplan, KPI } from '../../types';

export const strategicPlanService = {
  getAll: async (params?: Record<string, any>): Promise<ApiResponse<StrategicPlan[]>> => {
    const response = await apiClient.get<ApiResponse<StrategicPlan[]>>('/strategic-plans', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<StrategicPlan>> => {
    const response = await apiClient.get<ApiResponse<StrategicPlan>>(`/strategic-plans/${id}`);
    return response.data;
  },

  create: async (data: Partial<StrategicPlan>): Promise<ApiResponse<StrategicPlan>> => {
    const response = await apiClient.post<ApiResponse<StrategicPlan>>('/strategic-plans', data);
    return response.data;
  },

  update: async (id: number, data: Partial<StrategicPlan>): Promise<ApiResponse<StrategicPlan>> => {
    const response = await apiClient.put<ApiResponse<StrategicPlan>>(`/strategic-plans/${id}`, data);
    return response.data;
  },

  getWorkplans: async (planId: number): Promise<ApiResponse<Workplan[]>> => {
    const response = await apiClient.get<ApiResponse<Workplan[]>>(`/strategic-plans/${planId}/workplans`);
    return response.data;
  },

  createWorkplan: async (planId: number, data: Partial<Workplan>): Promise<ApiResponse<Workplan>> => {
    const response = await apiClient.post<ApiResponse<Workplan>>(`/strategic-plans/${planId}/workplans`, data);
    return response.data;
  },

  getKPIs: async (workplanId: number): Promise<ApiResponse<KPI[]>> => {
    const response = await apiClient.get<ApiResponse<KPI[]>>(`/workplans/${workplanId}/kpis`);
    return response.data;
  },

  createKPI: async (workplanId: number, data: Partial<KPI>): Promise<ApiResponse<KPI>> => {
    const response = await apiClient.post<ApiResponse<KPI>>(`/workplans/${workplanId}/kpis`, data);
    return response.data;
  },

  updateKPI: async (id: number, data: Partial<KPI>): Promise<ApiResponse<KPI>> => {
    const response = await apiClient.put<ApiResponse<KPI>>(`/kpis/${id}`, data);
    return response.data;
  },
};
import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, Appraisal, AppraisalFormData } from '../../types';

export const appraisalService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<Appraisal>> => {
    const response = await apiClient.get<PaginatedResponse<Appraisal>>('/appraisals', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.get<ApiResponse<Appraisal>>(`/appraisals/${id}`);
    return response.data;
  },

  create: async (data: AppraisalFormData): Promise<ApiResponse<Appraisal>> => {
    const response = await apiClient.post<ApiResponse<Appraisal>>('/appraisals', data);
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
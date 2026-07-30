import apiClient from '../client';
import type { ApiResponse, Report } from '../../types';

export const reportService = {
  getEmployees: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/reports/employees', { params });
    return response.data;
  },

  getAttendance: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/reports/attendance', { params });
    return response.data;
  },

  getLeave: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/reports/leave', { params });
    return response.data;
  },

  getAppraisal: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/reports/appraisal', { params });
    return response.data;
  },

  getDocumentation: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/reports/documentation', { params });
    return response.data;
  },

  export: async (type: string, format: string, params?: Record<string, any>): Promise<Blob> => {
    const response = await apiClient.get(`/reports/${type}/export/${format}`, {
      params,
      responseType: 'blob',
    });
    return response.data;
  },
};
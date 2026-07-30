import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, Department, DepartmentFormData, Section, Office } from '../../types';

export const departmentService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<Department>> => {
    const response = await apiClient.get<PaginatedResponse<Department>>('/departments', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<Department>> => {
    const response = await apiClient.get<ApiResponse<Department>>(`/departments/${id}`);
    return response.data;
  },

  create: async (data: DepartmentFormData): Promise<ApiResponse<Department>> => {
    const response = await apiClient.post<ApiResponse<Department>>('/departments', data);
    return response.data;
  },

  update: async (id: number, data: Partial<DepartmentFormData>): Promise<ApiResponse<Department>> => {
    const response = await apiClient.put<ApiResponse<Department>>(`/departments/${id}`, data);
    return response.data;
  },

  delete: async (id: number): Promise<ApiResponse<null>> => {
    const response = await apiClient.delete<ApiResponse<null>>(`/departments/${id}`);
    return response.data;
  },

  getSections: async (departmentId: number): Promise<ApiResponse<Section[]>> => {
    const response = await apiClient.get<ApiResponse<Section[]>>(`/departments/${departmentId}/sections`);
    return response.data;
  },

  getOffices: async (sectionId: number): Promise<ApiResponse<Office[]>> => {
    const response = await apiClient.get<ApiResponse<Office[]>>(`/sections/${sectionId}/offices`);
    return response.data;
  },
};
import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, Employee, EmployeeFormData } from '../../types';

export const employeeService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<Employee>> => {
    const response = await apiClient.get<PaginatedResponse<Employee>>('/employees', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<Employee>> => {
    const response = await apiClient.get<ApiResponse<Employee>>(`/employees/${id}`);
    return response.data;
  },

  create: async (data: EmployeeFormData): Promise<ApiResponse<Employee>> => {
    const response = await apiClient.post<ApiResponse<Employee>>('/employees', data);
    return response.data;
  },

  update: async (id: number, data: Partial<EmployeeFormData>): Promise<ApiResponse<Employee>> => {
    const response = await apiClient.put<ApiResponse<Employee>>(`/employees/${id}`, data);
    return response.data;
  },

  delete: async (id: number): Promise<ApiResponse<null>> => {
    const response = await apiClient.delete<ApiResponse<null>>(`/employees/${id}`);
    return response.data;
  },

  search: async (query: string): Promise<ApiResponse<Employee[]>> => {
    const response = await apiClient.get<ApiResponse<Employee[]>>('/employees/search', { params: { q: query } });
    return response.data;
  },
};
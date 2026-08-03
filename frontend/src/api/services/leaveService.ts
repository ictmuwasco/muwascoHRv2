import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, LeaveRequest, LeaveFormData, LeaveType, LeaveBalance, Holiday } from '../../types';

export const leaveService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<LeaveRequest>> => {
    const response = await apiClient.get<PaginatedResponse<LeaveRequest>>('/leave', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<LeaveRequest>> => {
    const response = await apiClient.get<ApiResponse<LeaveRequest>>(`/leave/${id}`);
    return response.data;
  },

  apply: async (data: LeaveFormData): Promise<ApiResponse<LeaveRequest>> => {
    const response = await apiClient.post<ApiResponse<LeaveRequest>>('/leave/apply', data);
    return response.data;
  },

  approve: async (id: number): Promise<ApiResponse<LeaveRequest>> => {
    const response = await apiClient.put<ApiResponse<LeaveRequest>>(`/leave/${id}/approve`);
    return response.data;
  },

  reject: async (id: number, reason: string): Promise<ApiResponse<LeaveRequest>> => {
    const response = await apiClient.put<ApiResponse<LeaveRequest>>(`/leave/${id}/reject`, { reason });
    return response.data;
  },

  cancel: async (id: number): Promise<ApiResponse<LeaveRequest>> => {
    const response = await apiClient.put<ApiResponse<LeaveRequest>>(`/leave/${id}/cancel`);
    return response.data;
  },

  getTypes: async (): Promise<ApiResponse<LeaveType[]>> => {
    const response = await apiClient.get<ApiResponse<LeaveType[]>>('/leave/types');
    return response.data;
  },

  getBalance: async (employeeId: number): Promise<ApiResponse<LeaveBalance[]>> => {
    const response = await apiClient.get<ApiResponse<LeaveBalance[]>>(`/leave/balance/${employeeId}`);
    return response.data;
  },

  getHolidays: async (): Promise<ApiResponse<Holiday[]>> => {
    const response = await apiClient.get<ApiResponse<Holiday[]>>('/leave/holidays');
    return response.data;
  },

  getPending: async (): Promise<ApiResponse<LeaveRequest[]>> => {
    const response = await apiClient.get<ApiResponse<LeaveRequest[]>>('/leave/pending');
    return response.data;
  },

  getByEmployee: async (employeeId: number): Promise<ApiResponse<LeaveRequest[]>> => {
    const response = await apiClient.get<ApiResponse<LeaveRequest[]>>(`/leave/employee/${employeeId}`);
    return response.data;
  },
};
import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, Attendance, AttendanceFormData } from '../../types';

export const attendanceService = {
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<Attendance>> => {
    const response = await apiClient.get<PaginatedResponse<Attendance>>('/attendance', { params });
    return response.data;
  },

  getById: async (id: number): Promise<ApiResponse<Attendance>> => {
    const response = await apiClient.get<ApiResponse<Attendance>>(`/attendance/${id}`);
    return response.data;
  },

  create: async (data: AttendanceFormData): Promise<ApiResponse<Attendance>> => {
    const response = await apiClient.post<ApiResponse<Attendance>>('/attendance', data);
    return response.data;
  },

  update: async (id: number, data: Partial<AttendanceFormData>): Promise<ApiResponse<Attendance>> => {
    const response = await apiClient.put<ApiResponse<Attendance>>(`/attendance/${id}`, data);
    return response.data;
  },

  delete: async (id: number): Promise<ApiResponse<null>> => {
    const response = await apiClient.delete<ApiResponse<null>>(`/attendance/${id}`);
    return response.data;
  },

  getToday: async (): Promise<ApiResponse<Attendance[]>> => {
    const response = await apiClient.get<ApiResponse<Attendance[]>>('/attendance/today');
    return response.data;
  },

  getByEmployee: async (employeeId: number): Promise<ApiResponse<Attendance[]>> => {
    const response = await apiClient.get<ApiResponse<Attendance[]>>(`/attendance/employee/${employeeId}`);
    return response.data;
  },

  getDashboard: async (): Promise<ApiResponse<{ present: number; absent: number; late: number; total: number }>> => {
    const response = await apiClient.get<ApiResponse<{ present: number; absent: number; late: number; total: number }>>('/attendance/dashboard');
    return response.data;
  },
};
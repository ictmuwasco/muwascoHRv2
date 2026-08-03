import apiClient from '../client';
import type { ApiResponse, DashboardStats, ChartData } from '../../types';

export const dashboardService = {
  getStats: async (): Promise<ApiResponse<DashboardStats>> => {
    const response = await apiClient.get<ApiResponse<DashboardStats>>('/dashboard/stats');
    return response.data;
  },

  getAttendanceChart: async (period?: string): Promise<ApiResponse<ChartData>> => {
    const response = await apiClient.get<ApiResponse<ChartData>>('/dashboard/charts/attendance', { params: { period } });
    return response.data;
  },

  getDepartmentChart: async (): Promise<ApiResponse<ChartData>> => {
    const response = await apiClient.get<ApiResponse<ChartData>>('/dashboard/charts/departments');
    return response.data;
  },

  getLeaveChart: async (): Promise<ApiResponse<ChartData>> => {
    const response = await apiClient.get<ApiResponse<ChartData>>('/dashboard/charts/leave');
    return response.data;
  },
};
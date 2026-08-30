import apiClient from '../client'
import type { ApiResponse } from '../../types'

/**
 * Leave Report service - dedicated analytics + reporting endpoints for the
 * Leave Reports module. All endpoints share the same filter query params and
 * re-use the authenticated, role-scoped backend pipeline.
 */
export const leaveReportService = {
  // Filter dropdown values (departments, leave types, financial years).
  options: async (): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/options')
    return res.data?.data
  },

  summary: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/summary', { params })
    return res.data?.data
  },

  trends: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/trends', { params })
    return res.data?.data
  },

  byType: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/by-type', { params })
    return res.data?.data
  },

  byDepartment: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/by-department', { params })
    return res.data?.data
  },

  byStatus: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/by-status', { params })
    return res.data?.data
  },

  duration: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/duration', { params })
    return res.data?.data
  },

  insights: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/insights', { params })
    return res.data?.data
  },

  records: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/leave/records', { params })
    return res.data?.data
  },

  // CSV export respecting all active filters (blob download).
  exportCsv: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get('/reports/leave/export', {
      params,
      responseType: 'blob',
    })
    return res.data
  },
}

export default leaveReportService
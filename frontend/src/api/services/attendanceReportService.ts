import apiClient from '../client'
import type { ApiResponse } from '../../types'

/**
 * Attendance Report service - dedicated analytics + reporting endpoints for
 * the Attendance Analytics & Reporting module. All endpoints share the same
 * filter query params (from, to, department_id, office_id, employee_id,
 * employee_type, status, search) and re-use the authenticated, role-scoped
 * backend pipeline, mirroring the Leave Report service contract.
 */
export const attendanceReportService = {
  // Filter dropdown values (departments, offices, employee types, statuses).
  options: async (): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/options')
    return res.data?.data
  },

  // KPI summary cards for the selected period.
  summary: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/summary', { params })
    return res.data?.data
  },

  // Attendance trend (auto-grouped daily/weekly/monthly server-side).
  trends: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/trends', { params })
    return res.data?.data
  },

  // Attendance status distribution.
  byStatus: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/by-status', { params })
    return res.data?.data
  },

  // Departmental attendance analysis.
  byDepartment: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/by-department', { params })
    return res.data?.data
  },

  // Late arrival analysis (totals, repeat offenders, top employees/departments).
  lateArrivals: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/late-arrivals', { params })
    return res.data?.data
  },

  // Working-hours analysis + trend.
  workingHours: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/working-hours', { params })
    return res.data?.data
  },

  // Dynamically computed insight statements.
  insights: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/insights', { params })
    return res.data?.data
  },

  // Attendance compliance rate + per-bucket series.
  compliance: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/compliance', { params })
    return res.data?.data
  },

  // Paginated per-employee attendance summary (server-side sort + search).
  employees: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/employees', { params })
    return res.data?.data
  },

  // Drill-down: one employee's daily attendance rows.
  records: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get<ApiResponse<any>>('/reports/attendance/records', { params })
    return res.data?.data
  },

  // CSV export respecting all active filters (blob download).
  exportCsv: async (params?: Record<string, any>): Promise<any> => {
    const res = await apiClient.get('/reports/attendance/export', {
      params,
      responseType: 'blob',
    })
    return res.data
  },
}

export default attendanceReportService

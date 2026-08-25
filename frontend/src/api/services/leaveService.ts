import apiClient from '../client';
import type { ApiResponse, PaginatedResponse, LeaveRequest, LeaveFormData, LeaveType, LeaveBalance, Holiday } from '../../types';

export const leaveService = {
  // List own leaves (uses /leave — same as Leave.jsx fetch).
  getAll: async (params?: Record<string, any>): Promise<PaginatedResponse<LeaveRequest>> => {
    const response = await apiClient.get<PaginatedResponse<LeaveRequest>>('/leave', { params });
    return response.data;
  },

  // Manager-only queue (pending / approved / rejected lists, scoped by role).
  manage: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/leave/manage', { params });
    return response.data;
  },

  // Submit a new application.
  apply: async (data: LeaveFormData): Promise<ApiResponse<LeaveRequest>> => {
    const form = new FormData();
    Object.entries(data).forEach(([k, v]) => {
      if (v !== undefined && v !== null) form.append(k, v as any);
    });
    const response = await apiClient.post<ApiResponse<LeaveRequest>>('/leave/applications', form);
    return response.data;
  },

  approve: async (id: number): Promise<ApiResponse<any>> => {
    const response = await apiClient.put<ApiResponse<any>>(`/leave/applications/${id}/approve`);
    return response.data;
  },

  reject: async (id: number, reason: string): Promise<ApiResponse<any>> => {
    const response = await apiClient.put<ApiResponse<any>>(
      `/leave/applications/${id}/reject`,
      { reason }
    );
    return response.data;
  },

  invalidate: async (id: number, reason: string): Promise<ApiResponse<any>> => {
    const response = await apiClient.put<ApiResponse<any>>(
      `/leave/applications/${id}/invalidate`,
      { reason }
    );
    return response.data;
  },

  cancel: async (id: number): Promise<ApiResponse<any>> => {
    const response = await apiClient.put<ApiResponse<any>>(`/leave/applications/${id}/cancel`);
    return response.data;
  },

  getTypes: async (params?: { employee_id?: number }): Promise<ApiResponse<LeaveType[]>> => {
    const response = await apiClient.get<ApiResponse<LeaveType[]>>('/leave/types', { params });
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

  // ─── Employee Leave Profile ────────────────────────────────────────

  /**
   * Get the list of employees the current user is allowed to view
   * in the leave-profile selector.  Role-scoped by the backend.
   */
  getProfileEmployees: async (search?: string): Promise<ApiResponse<any[]>> => {
    const params = search ? { search } : {};
    const response = await apiClient.get<ApiResponse<any[]>>('/leave/profile/employees', { params });
    return response.data;
  },

  /**
   * Get the complete leave profile for an employee.
   * @param employeeId  Employee record ID
   * @param params      { financial_year_id?, status?, leave_type_id?, date_from?, date_to? }
   */
  getProfile: async (employeeId: number, params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>(`/leave/profile/${employeeId}`, { params });
    return response.data;
  },

  /**
   * Get leave balances for an employee in a financial year.
   */
  getProfileBalances: async (employeeId: number, params?: Record<string, any>): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>(`/leave/profile/${employeeId}/balances`, { params });
    return response.data;
  },

  /**
   * Get leave applications for an employee with optional filters.
   */
  getProfileApplications: async (employeeId: number, params?: Record<string, any>): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>(`/leave/profile/${employeeId}/applications`, { params });
    return response.data;
  },

  /**
   * Get the balance timeline (buildBalanceTimeline) for an employee.
   */
  getProfileTimeline: async (employeeId: number, params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>(`/leave/profile/${employeeId}/timeline`, { params });
    return response.data;
  },

  /**
   * Get summary statistics for an employee's leave account.
   */
  getProfileSummary: async (employeeId: number, params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>(`/leave/profile/${employeeId}/summary`, { params });
    return response.data;
  },

  /**
   * Export the employee leave account as CSV.
   * Returns a Blob for download.
   */
  exportProfile: async (employeeId: number, params?: Record<string, any>): Promise<Blob> => {
    const response = await apiClient.get(`/leave/profile/${employeeId}/export`, {
      params: { ...params, format: 'csv' },
      responseType: 'blob',
    });
    return response.data;
  },

  // Leave Roster
  getRoster: async (params?: Record<string, any>): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>('/leave/roster', { params });
    return response.data;
  },

  createRoster: async (data: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.post<ApiResponse<any>>('/leave/roster', data);
    return response.data;
  },

  updateRoster: async (id: number, data: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.put<ApiResponse<any>>(`/leave/roster/${id}`, data);
    return response.data;
  },

  deleteRoster: async (id: number): Promise<ApiResponse<any>> => {
    const response = await apiClient.delete<ApiResponse<any>>(`/leave/roster/${id}`);
    return response.data;
  },

  getRosterStats: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/leave/roster/stats', { params });
    return response.data;
  },

  getRosterDistribution: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/leave/roster/distribution', { params });
    return response.data;
  },

  getRosterUpcoming: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/leave/roster/upcoming', { params });
    return response.data;
  },

  getRosterDepartments: async (params?: Record<string, any>): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>('/leave/roster/departments', { params });
    return response.data;
  },

  getRosterMatrix: async (params?: Record<string, any>): Promise<ApiResponse<any>> => {
    const response = await apiClient.get<ApiResponse<any>>('/leave/roster/matrix', { params });
    return response.data;
  },

  getRosterEmployees: async (params?: Record<string, any>): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>('/leave/roster/employees', { params });
    return response.data;
  },

  getRosterFinancialYears: async (): Promise<ApiResponse<any[]>> => {
    const response = await apiClient.get<ApiResponse<any[]>>('/leave/roster/financial-years');
    return response.data;
  },

  exportRoster: async (params?: Record<string, any>): Promise<any> => {
    const response = await apiClient.get('/leave/roster/export', { params, responseType: 'blob' });
    return response.data;
  },
};
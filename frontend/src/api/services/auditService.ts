import apiClient from '../client';
import type { AuditLog, ApiResponse } from '../../types';

export interface AuditStatistics {
  total_logs: number;
  success: number;
  failed: number;
  last_30_days: number;
  by_module: Array<{ module: string; count: number }>;
}

export interface AuditFilters {
  actions: string[];
  modules: string[];
  roles: string[];
  status: string[];
  users: string[];
}

export interface AuditPagination {
  data: AuditLog[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

export const auditService = {
  /**
   * Fetch paginated, filterable audit logs.
   * Endpoint: GET /api/audit
   */
  getLogs: async (params?: Record<string, any>): Promise<AuditPagination> => {
    const response = await apiClient.get<ApiResponse<AuditPagination>>('/audit', { params });
    return response.data.data;
  },

  /**
   * Fetch summary statistics for dashboard cards.
   * Endpoint: GET /api/audit/statistics
   */
  getStatistics: async (): Promise<AuditStatistics> => {
    const response = await apiClient.get<ApiResponse<AuditStatistics>>('/audit/statistics');
    return response.data.data;
  },

  /**
   * Fetch distinct filter options for dropdowns.
   * Endpoint: GET /api/audit/filters
   */
  getFilters: async (): Promise<AuditFilters> => {
    const response = await apiClient.get<ApiResponse<AuditFilters>>('/audit/filters');
    return response.data.data;
  },

  /**
   * Fetch a single audit log with decoded JSON columns.
   * Endpoint: GET /api/audit/{id}
   */
  getLogById: async (id: number): Promise<AuditLog> => {
    const response = await apiClient.get<ApiResponse<AuditLog>>(`/audit/${id}`);
    return response.data.data;
  },
};

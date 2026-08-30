import apiClient from '../client';
import type { ApiResponse } from '../../types';

// ===========================================================================
// System Monitoring service - typed access to the observability API.
// Backend: backend/app/Controllers/System/MonitoringController.php
// RBAC:    system_errors:view / manage / assign / resolve / view_sensitive
// ===========================================================================

export interface ErrorStatsToday {
  total: number;
  critical: number;
  high: number;
  client: number;
  failed_requests: number;
  affected_users: number;
  slow_requests: number;
  slow_critical: number;
  avg_duration_ms: number;
  max_duration_ms: number;
}

export interface ApplicationMeta {
  environment: string;
  version: string;
  git_commit: string | null;
  deployment_id: string | null;
}

export interface ErrorGroup {
  id: number;
  fingerprint: string;
  fingerprint_hash: string;
  title: string;
  module: string;
  category: string;
  severity: string;
  status: string;
  environment: string;
  exception_class: string | null;
  sample_message: string | null;
  sample_endpoint: string | null;
  sample_http_method: string | null;
  occurrence_count: number;
  affected_user_count: number;
  first_seen_at: string;
  last_seen_at: string;
  last_notified_at: string | null;
  assigned_to: number | null;
  resolved_at: string | null;
  resolved_by: number | null;
  resolution_notes: string | null;
  fixed_version: string | null;
  tags: string | null;
  updated_at: string;
}

export interface ErrorOccurrenceRow {
  [key: string]: unknown;
}

export interface ErrorDetailPayload {
  error: Record<string, unknown>;
  group: ErrorGroup | null;
  occurrences: Array<Record<string, unknown>>;
  audit_events: Array<Record<string, unknown>>;
  performance: Record<string, unknown> | null;
  can_sensitive: boolean;
  application: ApplicationMeta;
}

export interface StatsPayload {
  today: ErrorStatsToday;
  open_groups: number;
  critical_open: number;
  hourly_series: Array<{ bucket: string; total: number }>;
  by_severity: Array<{ severity: string; total: number }>;
  by_module: Array<{ module: string; total: number }>;
  top_endpoints: Array<{
    endpoint: string;
    http_method: string | null;
    errors: number;
    users: number;
    last_seen: string;
  }>;
  spikes: Array<{
    id: number;
    fingerprint: string;
    title: string;
    severity: string;
    last_hour: number;
    avg_per_hour: number;
  }>;
  application: ApplicationMeta;
}

export interface GroupsPage {
  data: ErrorGroup[];
  total: number;
  page: number;
  per_page: number;
  pages: number;
}

export interface PerformanceEvent {
  id: number;
  request_id: string | null;
  endpoint: string | null;
  http_method: string | null;
  duration_ms: number;
  threshold_level: string;
  status_code: number | null;
  user_id: number | null;
  memory_kb: number | null;
  created_at: string;
}

export interface PerformancePage {
  data: PerformanceEvent[];
  summary: { total: number; slow: number; critical: number; avg_ms: number; max_ms: number };
  total: number;
  page: number;
  per_page: number;
  pages: number;
  thresholds: { warning_ms: number; slow_ms: number; critical_ms: number };
}

export interface HealthPayload {
  status: 'healthy' | 'degraded' | 'down';
  db_latency_ms?: number;
  errors_last_hour?: number;
  errors_prev_hour?: number;
  open_critical?: number;
  slow_last_hour?: number;
  application: ApplicationMeta;
  server_time: string;
}

export interface ManagePayload {
  status?: string;
  severity?: string;
  assigned_to?: number | null;
  resolution_notes?: string | null;
  fixed_version?: string | null;
}

export const errorTrackingService = {
  /** GET /api/system/errors/stats */
  getStats: async (): Promise<StatsPayload> => {
    const response = await apiClient.get<ApiResponse<StatsPayload>>('/system/errors/stats');
    return response.data.data;
  },

  /** GET /api/system/errors/groups */
  getGroups: async (params?: Record<string, any>): Promise<GroupsPage> => {
    const response = await apiClient.get<ApiResponse<GroupsPage>>('/system/errors/groups', { params });
    return response.data.data;
  },

  /** GET /api/system/errors/{uuid} */
  getErrorDetail: async (uuid: string): Promise<ErrorDetailPayload> => {
    const response = await apiClient.get<ApiResponse<ErrorDetailPayload>>(`/system/errors/${uuid}`);
    return response.data.data;
  },

  /** POST /api/system/errors/groups/{id}/manage - lifecycle transitions (audited server-side). */
  manageGroup: async (id: number, payload: ManagePayload): Promise<{ group: ErrorGroup }> => {
    const response = await apiClient.post<ApiResponse<{ group: ErrorGroup }>>(
      `/system/errors/groups/${id}/manage`,
      payload
    );
    return response.data.data;
  },

  /** GET /api/system/performance */
  getPerformance: async (params?: Record<string, any>): Promise<PerformancePage> => {
    const response = await apiClient.get<ApiResponse<PerformancePage>>('/system/performance', { params });
    return response.data.data;
  },

  /** GET /api/system/health */
  getHealth: async (): Promise<HealthPayload> => {
    const response = await apiClient.get<ApiResponse<HealthPayload>>('/system/health');
    return response.data.data;
  },
};

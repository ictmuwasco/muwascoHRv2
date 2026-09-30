import apiClient from '../client';
import type { ApiResponse } from '../../types';

/** One completed row in the archive table. */
export interface CompletedAppraisalRow {
  id: number;
  status: string;
  status_label: string;
  employee_id: number;
  employee_code: string;
  employee_name: string;
  employee_type?: string | null;
  department_name?: string | null;
  section_name?: string | null;
  subsection_name?: string | null;
  cycle_name?: string | null;
  start_date?: string | null;
  end_date?: string | null;
  appraiser_name: string;
  submitted_at?: string | null;
  score_percentage: number;
}

/** One scored line inside a report. */
export interface AppraisalReportLine {
  indicator_name: string;
  activity_name: string;
  contract_name?: string | null;
  max_score: number;
  score: number;
  appraiser_comment: string;
}

/** GET /appraisals/{id}/report/detail */
export interface AppraisalReport {
  id: number;
  status: string;
  status_label: string;
  employee_name: string;
  employee_code: string;
  department_name?: string | null;
  section_name?: string | null;
  subsection_name?: string | null;
  cycle_name?: string | null;
  start_date?: string | null;
  end_date?: string | null;
  appraiser_name: string;
  submitted_at?: string | null;
  employee_comment?: string | null;
  employee_comment_date?: string | null;
  supervisors_comment?: string | null;
  supervisors_comment_date?: string | null;
  dept_head_decision?: string | null;
  dept_head_comment?: string | null;
  dept_head_decision_date?: string | null;
  lines: AppraisalReportLine[];
  total_score: number;
  total_max_score: number;
  score_percentage: number;
}

export interface CompletedAppraisalFilters {
  status?: string;
  cycle_id?: number | null;
  department_id?: number | null;
  section_id?: number | null;
  subsection_id?: number | null;
  search?: string;
}

/**
 * The organisational tier the signed-in supervisor actually owns.
 *
 * The server pins every tier the caller does NOT own, so the UI only offers
 * the dropdowns that can still narrow the result:
 *   - `organisation` (HR / super admin / MD) - department, section, subsection
 *   - `department`    (department head)      - section, subsection
 *   - `section`       (section head)         - subsection
 *   - `subsection`    (subsection head)      - no unit filter at all
 */
export type FilterScopeLevel = 'organisation' | 'department' | 'section' | 'subsection';

export interface FilterScope {
  level: FilterScopeLevel;
  organisation: boolean;
  department_id: number | null;
  section_id: number | null;
  subsection_id: number | null;
}

export interface CompletedAppraisalResponse {
  items: CompletedAppraisalRow[];
  total: number;
  page: number;
  per_page: number;
  filters: {
    status: string;
    cycle_id: number | null;
    department_id: number | null;
    section_id: number | null;
    subsection_id: number | null;
    search: string;
  };
}

export interface AppraisalReportOptions {
  statuses: string[];
  cycles: Array<{ id: number; name: string; start_date: string | null; end_date: string | null }>;
  departments: Array<{ id: number; name: string }>;
  sections: Array<{ id: number; name: string }>;
  subsections: Array<{ id: number; name: string }>;
  scope: FilterScope;
}

export type ReportFormat = 'pdf' | 'word' | 'print';

/** One ranked unit (department, section, subsection, employee or cycle). */
export interface AnalyticsUnit {
  id: number | null;
  name: string;
  /** Staff number for an employee bucket; null elsewhere. */
  detail: string | null;
  /** How many appraisals contributed to this figure. */
  count: number;
  /** Score-weighted average, not the mean of per-row percentages. */
  percentage: number;
}

/**
 * GET /appraisals/completed/analytics
 *
 * `available: false` is returned for a self-scoped caller, who has no unit-wide
 * view to aggregate. Every other field is guaranteed present when available.
 */
export interface AppraisalAnalytics {
  available: boolean;
  /** True when the aggregation hit the 100-row cap and totals are partial. */
  truncated: boolean;
  appraisals: number;
  scored: number;
  overall: {
    total_score: number;
    total_max_score: number;
    /** null when nothing in scope has been scored yet. */
    percentage: number | null;
  };
  departments: AnalyticsUnit[];
  sections: AnalyticsUnit[];
  subsections: AnalyticsUnit[];
  top_employee: AnalyticsUnit | null;
  top_cycle: AnalyticsUnit | null;
}

export const appraisalReportService = {
  list: async (
    filters: CompletedAppraisalFilters = {},
    page = 1,
    perPage = 20,
  ): Promise<CompletedAppraisalResponse> => {
    const params: Record<string, string | number> = { page, per_page: perPage };
    if (filters.status) params.status = filters.status;
    if (filters.cycle_id) params.cycle_id = filters.cycle_id;
    if (filters.department_id) params.department_id = filters.department_id;
    if (filters.section_id) params.section_id = filters.section_id;
    if (filters.subsection_id) params.subsection_id = filters.subsection_id;
    if (filters.search) params.search = filters.search;
    const res = await apiClient.get<ApiResponse<CompletedAppraisalResponse>>('/appraisals/completed', { params });
    // Return the PAYLOAD, not the envelope, matching options() and detail().
    // This used to hand back the whole `{success, message, data}` object while
    // the page read `res.data.items`, so the lookup landed on `envelope.items`
    // (undefined) and `?? []` silently turned every result into an empty table.
    return res.data.data;
  },

  options: async (): Promise<AppraisalReportOptions> => {
    const res = await apiClient.get<ApiResponse<AppraisalReportOptions>>('/appraisals/completed/filters');
    return res.data.data;
  },

  /**
   * Headline figures for the caller's scope.
   *
   * Accepts the same filters as `list()` on purpose: the analytics describe
   * exactly the rows the table is showing, so narrowing the table narrows the
   * numbers too rather than leaving them describing something else.
   */
  analytics: async (filters: CompletedAppraisalFilters = {}): Promise<AppraisalAnalytics> => {
    const params: Record<string, string | number> = {};
    if (filters.status) params.status = filters.status;
    if (filters.cycle_id) params.cycle_id = filters.cycle_id;
    if (filters.department_id) params.department_id = filters.department_id;
    if (filters.section_id) params.section_id = filters.section_id;
    if (filters.subsection_id) params.subsection_id = filters.subsection_id;
    if (filters.search) params.search = filters.search;
    const res = await apiClient.get<ApiResponse<AppraisalAnalytics>>('/appraisals/completed/analytics', { params });
    return res.data.data;
  },

  detail: async (id: number): Promise<AppraisalReport> => {
    const res = await apiClient.get<ApiResponse<AppraisalReport>>(`/appraisals/${id}/report/detail`);
    return res.data.data;
  },

  /**
   * Download a rendered document.
   *
   * Fetched as a blob rather than a plain link so the Authorization header
   * is sent (the API is JWT-protected) and the token never lands in a URL
   * that ends up in browser history or server logs.
   */
  download: async (id: number, format: ReportFormat): Promise<void> => {
    const res = await apiClient.get(`/appraisals/${id}/report`, {
      params: { format },
      responseType: 'blob',
    });
    const disposition = String(res.headers['content-disposition'] ?? '');
    const match = /filename="?([^";]+)"?/i.exec(disposition);
    const filename = match?.[1] ?? `appraisal-${id}.${format === 'word' ? 'docx' : format}`;
    triggerBlobDownload(res.data, filename);
  },

  /**
   * URL for the print view, which opens in a new tab. No auth header is
   * possible on a plain navigation, so this is only used after the caller
   * has already confirmed access through the list.
   */
  printUrl: (id: number): string => `${apiClient.defaults.baseURL}/appraisals/${id}/report?format=print`,
};

/** Save a Blob response to disk under the server-supplied filename. */
const triggerBlobDownload = (blob: Blob, filename: string): void => {
  const href = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = href;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  // Revoke on the next tick so Safari has time to start the download.
  window.setTimeout(() => URL.revokeObjectURL(href), 1000);
};

import apiClient from '../client';
import type { ApiResponse } from '../../types';

/** Filter values shared by every slice of the report. */
export interface AppraisalReportFilters {
  /** One or more workflow statuses; omitted means all of them. */
  status?: string[];
  cycle_id?: number | null;
  department_id?: number | null;
  section_id?: number | null;
  subsection_id?: number | null;
  search?: string;
}

export interface AppraisalReportOptions {
  statuses: string[];
  cycles: Array<{ id: number; name: string; start_date: string | null; end_date: string | null }>;
  departments: Array<{ id: number; name: string }>;
  sections: Array<{ id: number; name: string }>;
  subsections: Array<{ id: number; name: string }>;
  employee_types: Array<{ value: string; label: string }>;
}

export interface AppraisalReportSummary {
  scored_appraisals: number;
  employees_scored: number;
  cycles_covered: number;
  total_score: number;
  total_max_score: number;
  /** null when nothing in scope has been scored. */
  average_percentage: number | null;
  exemplary: number;
  strong_performer: number;
  meets_expectations: number;
  needs_improvement: number;
}

export interface AppraisalTrendPoint {
  cycle_id: number;
  label: string;
  start_date: string | null;
  end_date: string | null;
  appraisals: number;
  employees: number;
  percentage: number | null;
}

/** One ranked organisational unit. */
export interface AppraisalUnitStat {
  id: number;
  name: string;
  appraisals: number;
  employees: number;
  percentage: number;
}

export interface AppraisalPerformer {
  employee_pk: number;
  employee_code: string;
  employee_name: string;
  employee_type: string | null;
  department: string | null;
  section: string | null;
  appraisals: number;
  percentage: number;
}

export interface AppraisalEmployeeRow {
  employee_pk: number;
  employee_code: string;
  employee_name: string;
  employee_type: string | null;
  department: string | null;
  section: string | null;
  appraisals: number;
  total_score: number;
  total_max: number;
  percentage: number | null;
}

export const appraisalPerformanceReportService = {
  options: async (): Promise<AppraisalReportOptions> => {
    const res = await apiClient.get<ApiResponse<AppraisalReportOptions>>(
      '/reports/appraisal/options',
    );
    return res.data.data;
  },

  summary: async (f: AppraisalReportFilters = {}): Promise<AppraisalReportSummary> => {
    const res = await apiClient.get<ApiResponse<AppraisalReportSummary>>(
      '/reports/appraisal/summary',
      {
        params: toParams(f),
      },
    );
    return res.data.data;
  },

  trends: async (f: AppraisalReportFilters = {}): Promise<AppraisalTrendPoint[]> => {
    const res = await apiClient.get<ApiResponse<AppraisalTrendPoint[]>>(
      '/reports/appraisal/trends',
      {
        params: toParams(f),
      },
    );
    return res.data.data ?? [];
  },

  byDepartment: async (f: AppraisalReportFilters = {}): Promise<AppraisalUnitStat[]> => {
    const res = await apiClient.get<ApiResponse<AppraisalUnitStat[]>>(
      '/reports/appraisal/by-department',
      {
        params: toParams(f),
      },
    );
    return res.data.data ?? [];
  },

  bySection: async (f: AppraisalReportFilters = {}): Promise<AppraisalUnitStat[]> => {
    const res = await apiClient.get<ApiResponse<AppraisalUnitStat[]>>(
      '/reports/appraisal/by-section',
      {
        params: toParams(f),
      },
    );
    return res.data.data ?? [];
  },

  bySubsection: async (f: AppraisalReportFilters = {}): Promise<AppraisalUnitStat[]> => {
    const res = await apiClient.get<ApiResponse<AppraisalUnitStat[]>>(
      '/reports/appraisal/by-subsection',
      {
        params: toParams(f),
      },
    );
    return res.data.data ?? [];
  },

  byStatus: async (
    f: AppraisalReportFilters = {},
  ): Promise<Array<{ status: string; label: string; count: number }>> => {
    const res = await apiClient.get<
      ApiResponse<Array<{ status: string; label: string; count: number }>>
    >('/reports/appraisal/by-status', { params: toParams(f) });
    return res.data.data ?? [];
  },

  performers: async (
    f: AppraisalReportFilters = {},
  ): Promise<{ top: AppraisalPerformer[]; bottom: AppraisalPerformer[] }> => {
    const res = await apiClient.get<
      ApiResponse<{ top: AppraisalPerformer[]; bottom: AppraisalPerformer[] }>
    >('/reports/appraisal/performers', { params: toParams(f) });
    return res.data.data ?? { top: [], bottom: [] };
  },

  insights: async (f: AppraisalReportFilters = {}): Promise<string[]> => {
    const res = await apiClient.get<ApiResponse<string[]>>('/reports/appraisal/insights', {
      params: toParams(f),
    });
    return res.data.data ?? [];
  },

  employees: async (
    f: AppraisalReportFilters = {},
    page = 1,
    perPage = 25,
    sort = 'percentage',
    dir = 'desc',
  ): Promise<AppraisalEmployeePage> => {
    const res = await apiClient.get<ApiResponse<AppraisalEmployeePage>>(
      '/reports/appraisal/employees',
      {
        params: { ...toParams(f), page, per_page: perPage, sort, dir },
      },
    );
    return res.data.data ?? { items: [], total: 0, page: 1, per_page: perPage, last_page: 1 };
  },

  /**
   * The appraisal register: one row per scored appraisal, paginated.
   *
   * Deliberately the underlying grain rather than a per-employee rollup - a
   * filter has to visibly narrow a table of records, not just the charts.
   */
  appraisals: async (
    f: AppraisalReportFilters = {},
    page = 1,
    perPage = 25,
    sort = 'submitted',
    dir = 'desc',
  ): Promise<AppraisalRegisterPage> => {
    const res = await apiClient.get<ApiResponse<AppraisalRegisterPage>>(
      '/reports/appraisal/appraisals',
      {
        params: { ...toParams(f), page, per_page: perPage, sort, dir },
      },
    );
    return res.data.data ?? { items: [], total: 0, page: 1, per_page: perPage, last_page: 1 };
  },

  /** Returns the raw CSV blob for download, mirroring the register on screen. */
  exportCsv: async (f: AppraisalReportFilters = {}): Promise<Blob> => {
    const res = await apiClient.get('/reports/appraisal/export', {
      params: toParams(f),
      responseType: 'blob',
    });
    return new Blob([res.data], { type: 'text/csv;charset=utf-8;' });
  },
};

export default appraisalPerformanceReportService;

export interface AppraisalEmployeePage {
  items: AppraisalEmployeeRow[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
}

/**
 * One row per scored appraisal - the grain the page's filters describe.
 *
 * This is the underlying data, not an aggregate of it, so every filter visibly
 * changes a table and not only the charts.
 */
export interface AppraisalRegisterRow {
  appraisal_id: number;
  employee_code: string;
  employee_name: string;
  employee_type: string | null;
  department: string | null;
  section: string | null;
  subsection: string | null;
  cycle: string | null;
  status: string;
  status_label: string;
  submitted_at: string | null;
  total_score: number;
  total_max: number;
  percentage: number | null;
}

export interface AppraisalRegisterPage {
  items: AppraisalRegisterRow[];
  total: number;
  page: number;
  per_page: number;
  last_page: number;
}

/** Build the query params every endpoint shares. */
const toParams = (f: AppraisalReportFilters): Record<string, string | number> => {
  const p: Record<string, string | number> = {};
  if (f.status?.length) p.status = f.status.join(',');
  if (f.cycle_id) p.cycle_id = f.cycle_id;
  if (f.department_id) p.department_id = f.department_id;
  if (f.section_id) p.section_id = f.section_id;
  if (f.subsection_id) p.subsection_id = f.subsection_id;
  if (f.search) p.search = f.search;
  return p;
};

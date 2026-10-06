import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  BarChart,
  Bar,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  LabelList,
} from 'recharts';
import toast from 'react-hot-toast';
import {
  Award,
  AlertTriangle,
  BarChart3,
  CalendarRange,
  ChevronLeft,
  ChevronRight,
  Download,
  FileText,
  Filter,
  Loader2,
  Printer,
  RefreshCw,
  Search,
  Sparkles,
  UserX,
  Users,
  X,
} from 'lucide-react';
import type { ElementType } from 'react';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import appraisalReportService from '../../api/services/appraisalPerformanceReportService';
import type {
  AppraisalRegisterRow,
  AppraisalPerformer,
  AppraisalReportFilters,
  AppraisalReportOptions,
  AppraisalReportSummary,
  AppraisalTrendPoint,
  AppraisalUnitStat,
} from '../../api/services/appraisalPerformanceReportService';
// Permission gate (global rule): /reports/appraisal/export is gated by
// reports:export - a view-only user reads the report but sees no Export button.

/** Render a DB datetime as a short readable date; pass the raw value through on failure. */
const formatDateCell = (value: string | null): string => {
  if (!value) return '-';
  const d = new Date(value.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

/** Sortable columns of the appraisal register, mapped to backend sort keys. */
const REGISTER_COLUMNS: ReadonlyArray<{ key: string; label: string; numeric?: boolean }> = [
  { key: 'code', label: 'Staff No' },
  { key: 'employee', label: 'Employee' },
  { key: 'department', label: 'Department' },
  { key: 'cycle', label: 'Cycle' },
  { key: 'status', label: 'Status' },
  { key: 'score', label: 'Score', numeric: true },
  { key: 'submitted', label: 'Submitted' },
];

import { Can } from '../../components/ui/PermissionGate';

const PER_PAGE = 25;

/** Performance bands, matching the thresholds used server-side and in the PDFs. */
const BANDS = [
  { key: 'exemplary', label: 'Exemplary (90-100%)', color: '#10b981' },
  { key: 'strong_performer', label: 'Strong (75-89%)', color: '#84cc16' },
  { key: 'meets_expectations', label: 'Meets (50-74%)', color: '#f59e0b' },
  { key: 'needs_improvement', label: 'Needs improvement (<50%)', color: '#ef4444' },
] as const;

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  awaiting_employee: 'Awaiting employee',
  submitted: 'Submitted',
  completed: 'Completed',
  awaiting_submission: 'Awaiting submission',
  pending_dept_approval: 'Pending dept approval',
  under_review: 'Under review',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

const PIE_COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#6366f1', '#94a3b8', '#ef4444'];

const band = (pct: number) =>
  pct < 50
    ? 'text-red-600 dark:text-red-400'
    : pct < 75
      ? 'text-amber-600 dark:text-amber-400'
      : 'text-green-600 dark:text-green-400';

const bandVariant = (pct: number): 'success' | 'warning' | 'danger' => {
  if (pct >= 75) return 'success';
  if (pct >= 50) return 'warning';
  return 'danger';
};

const downloadBlob = (blob: Blob, filename: string) => {
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  window.URL.revokeObjectURL(url);
};

const StatCard = ({
  title,
  value,
  icon: Icon,
  subtitle,
  variant = 'default',
}: {
  title: string;
  value: string | number;
  icon: ElementType;
  subtitle?: string;
  variant?: 'default' | 'success' | 'warning' | 'danger' | 'info';
}) => {
  const accent = {
    default: 'bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300',
    success: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    warning: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    info: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  }[variant];

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-gray-500 dark:text-gray-400">{title}</p>
          <p className="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{value}</p>
          {subtitle && <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{subtitle}</p>}
        </div>
        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${accent}`}>
          <Icon className="h-5 w-5" />
        </div>
      </div>
    </Card>
  );
};

/** One row of a performer list. */
const PerformerRow = ({ p, tone }: { p: AppraisalPerformer; tone: 'top' | 'bottom' }) => (
  <li className="flex items-center justify-between gap-3 py-2">
    <div className="min-w-0">
      <p className="truncate text-sm font-medium text-gray-900 dark:text-gray-100">
        {p.employee_name}
      </p>
      <p className="truncate text-xs text-gray-500 dark:text-gray-400">
        {p.employee_code}
        {p.department ? ` - ${p.department}` : ''} - {p.appraisals} appraisal
        {p.appraisals === 1 ? '' : 's'}
      </p>
    </div>
    <Badge variant={bandVariant(p.percentage)}>
      {tone === 'top' ? '+' : '-'} {p.percentage}%
    </Badge>
  </li>
);

/**
 * A ranked unit list with a proportional bar. Clicking a unit filters the whole
 * report to it, which is the fastest route from "who is behind?" to the people.
 */
const UnitList = ({
  title,
  units,
  onPick,
}: {
  title: string;
  units: AppraisalUnitStat[];
  onPick?: (id: number) => void;
}) => {
  if (!units.length) return null;
  const best = Math.max(...units.map((u) => u.percentage));
  return (
    <Card
      title={title}
      subtitle={`${units.length} unit${units.length === 1 ? '' : 's'} with scored appraisals`}
    >
      <ul className="divide-y divide-gray-100 dark:divide-slate-700/60">
        {units.map((u) => (
          <li key={u.id} className="py-2">
            <div className="flex items-baseline justify-between gap-3">
              {onPick ? (
                <button
                  type="button"
                  onClick={() => onPick(u.id)}
                  title={`Filter the report to ${u.name}`}
                  className="truncate text-left text-sm text-gray-700 hover:text-primary-600 hover:underline dark:text-gray-300"
                >
                  {u.name}
                </button>
              ) : (
                <span className="truncate text-sm text-gray-700 dark:text-gray-300">{u.name}</span>
              )}
              <span className={`shrink-0 text-sm font-semibold ${band(u.percentage)}`}>
                {u.percentage}%
              </span>
            </div>
            <div className="mt-1 flex items-center gap-2">
              {/* Scaled against the leader in THIS list, not 100%, so a tier
                  where everyone scores 60% still shows its own spread. */}
              <div className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-700">
                <div
                  className="h-full rounded-full bg-primary-500"
                  style={{ width: `${best > 0 ? Math.max(2, (u.percentage / best) * 100) : 0}%` }}
                />
              </div>
              <span className="w-20 shrink-0 text-right text-xs text-gray-400">
                {u.employees} staff - {u.appraisals}
              </span>
            </div>
          </li>
        ))}
      </ul>
    </Card>
  );
};

const AppraisalReport = () => {
  const [options, setOptions] = useState<AppraisalReportOptions | null>(null);
  const [filters, setFilters] = useState<AppraisalReportFilters>({});
  const [search, setSearch] = useState('');

  const [summary, setSummary] = useState<AppraisalReportSummary | null>(null);
  const [trends, setTrends] = useState<AppraisalTrendPoint[]>([]);
  const [departments, setDepartments] = useState<AppraisalUnitStat[]>([]);
  const [sections, setSections] = useState<AppraisalUnitStat[]>([]);
  const [subsections, setSubsections] = useState<AppraisalUnitStat[]>([]);
  const [byStatus, setByStatus] = useState<Array<{ status: string; label: string; count: number }>>(
    [],
  );
  const [performers, setPerformers] = useState<{
    top: AppraisalPerformer[];
    bottom: AppraisalPerformer[];
  }>({
    top: [],
    bottom: [],
  });
  const [insights, setInsights] = useState<string[]>([]);

  const [rows, setRows] = useState<AppraisalRegisterRow[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [sort, setSort] = useState('submitted');
  const [dir, setDir] = useState<'asc' | 'desc'>('desc');

  const [loading, setLoading] = useState(true);
  const [loadingRows, setLoadingRows] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState('');
  const [reloadKey, setReloadKey] = useState(0);

  // Search is typed continuously but the table should not re-query on every
  // keystroke; a ref carries the latest value without making it an effect dep.
  const searchRef = useRef(search);
  searchRef.current = search;

  useEffect(() => {
    appraisalReportService
      .options()
      .then(setOptions)
      .catch(() => {
        /* the selects simply stay empty */
      });
  }, []);

  // Analytics: every slice shares ONE filter object, so the page cannot show a
  // department chart describing a different filter than the headline score.
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    Promise.all([
      appraisalReportService.summary(filters),
      appraisalReportService.trends(filters),
      appraisalReportService.byDepartment(filters),
      appraisalReportService.bySection(filters),
      appraisalReportService.bySubsection(filters),
      appraisalReportService.byStatus(filters),
      appraisalReportService.performers(filters),
      appraisalReportService.insights(filters),
    ])
      .then(([sum, tr, dep, sec, sub, st, perf, ins]) => {
        if (cancelled) return;
        setSummary(sum);
        setTrends(tr);
        setDepartments(dep);
        setSections(sec);
        setSubsections(sub);
        setByStatus(st);
        setPerformers(perf);
        setInsights(ins);
      })
      .catch((err: any) => {
        if (cancelled) return;
        setError(err?.response?.data?.message || 'Failed to load the appraisal report.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [filters, reloadKey]);

  // Employee table: page-dependent, so fetched separately from the analytics.
  useEffect(() => {
    let cancelled = false;
    setLoadingRows(true);
    appraisalReportService
      .appraisals({ ...filters, search: searchRef.current || undefined }, page, PER_PAGE, sort, dir)
      .then((res) => {
        if (cancelled) return;
        setRows(res.items);
        setTotal(res.total);
        setLastPage(res.last_page);
      })
      .catch(() => {
        if (cancelled) return;
        setRows([]);
        setTotal(0);
        setLastPage(1);
      })
      .finally(() => {
        if (!cancelled) setLoadingRows(false);
      });
    return () => {
      cancelled = true;
    };
  }, [filters, page, sort, dir, reloadKey]);

  /** Merge a filter patch and return to page 1. */
  const patch = useCallback((p: Partial<AppraisalReportFilters>) => {
    setFilters((f) => ({ ...f, ...p }));
    setPage(1);
  }, []);

  /**
   * Changing a parent tier invalidates whatever was chosen beneath it. Without
   * this, picking "Technical" then "HR" left a section id belonging to
   * Technical, which the pinned scope query AND-ed to zero rows - an empty
   * report with no obvious cause.
   */
  const applyDepartment = useCallback((value: string) => {
    setFilters((f) => ({
      ...f,
      department_id: value ? Number(value) : null,
      section_id: null,
      subsection_id: null,
    }));
    setPage(1);
  }, []);

  const handleSort = (key: string) => {
    if (sort === key) {
      setDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSort(key);
      // Text columns read naturally A-Z; scores and dates are most useful
      // highest/newest first, so a first click is descending for those.
      setDir(
        key === 'employee' || key === 'code' || key === 'department' || key === 'cycle'
          ? 'asc'
          : 'desc',
      );
    }
    setPage(1);
  };

  const handleExport = useCallback(async () => {
    setExporting(true);
    try {
      const blob = await appraisalReportService.exportCsv({
        ...filters,
        search: search || undefined,
      });
      downloadBlob(blob, `appraisal-report-${new Date().toISOString().slice(0, 10)}.csv`);
      toast.success('Appraisal report exported');
    } catch (err: any) {
      toast.error(
        err?.response?.data?.message || 'Export failed. You may not have export permission.',
      );
    } finally {
      setExporting(false);
    }
  }, [filters, search]);

  const resetFilters = () => {
    setFilters({});
    setSearch('');
    setPage(1);
  };

  const activeCount = useMemo(
    () =>
      [
        filters.status?.length,
        filters.cycle_id,
        filters.department_id,
        filters.section_id,
        filters.subsection_id,
        search,
      ].filter(Boolean).length,
    [filters, search],
  );

  const cycleLabel = useMemo(() => {
    if (!filters.cycle_id) return 'All cycles';
    const c = options?.cycles.find((x) => x.id === filters.cycle_id);
    return c ? c.name : 'All cycles';
  }, [filters.cycle_id, options]);

  const trendData = trends.map((t) => ({ label: t.label, Score: t.percentage }));
  const deptData = departments.slice(0, 12).map((d) => ({ name: d.name, Score: d.percentage }));
  const statusData = byStatus
    .map((s) => ({ name: STATUS_LABELS[s.status] ?? s.label, value: s.count }))
    .filter((s) => s.value > 0);
  const bandData = BANDS.map((b) => ({
    name: b.label,
    count: summary ? summary[b.key] : 0,
    fill: b.color,
  })).filter((b) => b.count > 0);

  const bandTotal = summary
    ? summary.exemplary +
      summary.strong_performer +
      summary.meets_expectations +
      summary.needs_improvement
    : 0;
  const strongTotal = summary ? summary.exemplary + summary.strong_performer : 0;
  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Appraisal Reports</h1>
          <p className="text-gray-500 dark:text-gray-400">
            Company-wide appraisal performance, trends and outliers across your scope.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-600 dark:border-slate-700 dark:bg-slate-800 dark:text-gray-300">
            <CalendarRange className="h-4 w-4 text-gray-400" />
            {cycleLabel}
          </span>
          <Button variant="outline" size="sm" onClick={() => window.print()}>
            <Printer className="mr-1 h-4 w-4" /> Print
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => setReloadKey((k) => k + 1)}
            disabled={loading}
          >
            <RefreshCw className={`mr-1 h-4 w-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
          </Button>
          <Can module="reports" action="export">
            <Button variant="primary" size="sm" onClick={handleExport} disabled={exporting}>
              {exporting ? (
                <Loader2 className="mr-1 h-4 w-4 animate-spin" />
              ) : (
                <Download className="mr-1 h-4 w-4" />
              )}
              Export CSV
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-4">
        <div className="mb-3 flex items-center gap-2">
          <Filter className="h-4 w-4 text-gray-400" />
          <h3 className="text-sm font-medium text-gray-700 dark:text-gray-200">Filters</h3>
          {activeCount > 0 && <Badge>{activeCount} active</Badge>}
        </div>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Cycle
            </label>
            <select
              value={filters.cycle_id ?? ''}
              onChange={(e) => patch({ cycle_id: e.target.value ? Number(e.target.value) : null })}
              className="input"
            >
              <option value="">All cycles</option>
              {(options?.cycles ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Department
            </label>
            <select
              value={filters.department_id ?? ''}
              onChange={(e) => applyDepartment(e.target.value)}
              className="input"
            >
              <option value="">All departments</option>
              {(options?.departments ?? []).map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Section
            </label>
            <select
              value={filters.section_id ?? ''}
              onChange={(e) =>
                patch({
                  section_id: e.target.value ? Number(e.target.value) : null,
                  subsection_id: null,
                })
              }
              className="input"
            >
              <option value="">All sections</option>
              {(options?.sections ?? []).map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Status
            </label>
            <select
              value={filters.status?.[0] ?? ''}
              onChange={(e) => patch({ status: e.target.value ? [e.target.value] : [] })}
              className="input"
            >
              <option value="">All statuses</option>
              {(options?.statuses ?? Object.keys(STATUS_LABELS)).map((s) => (
                <option key={s} value={s}>
                  {STATUS_LABELS[s] ?? s}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">
              Search
            </label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
              <input
                value={search}
                onChange={(e) => {
                  setSearch(e.target.value);
                  setPage(1);
                }}
                placeholder="Name or staff no."
                className="input pl-9"
              />
            </div>
          </div>
        </div>
        {activeCount > 0 && (
          <div className="mt-3 flex justify-end">
            <Button variant="outline" size="sm" onClick={resetFilters}>
              <X className="mr-1 h-4 w-4" /> Reset filters
            </Button>
          </div>
        )}
      </Card>

      {error && (
        <Card className="border-red-200 p-4 dark:border-red-800">
          <div className="flex flex-col items-center py-3 text-center">
            <AlertTriangle className="mb-2 h-7 w-7 text-red-500" />
            <p className="text-sm font-medium text-red-600 dark:text-red-400">{error}</p>
            <Button variant="outline" className="mt-3" onClick={() => setReloadKey((k) => k + 1)}>
              <RefreshCw className="mr-1 h-4 w-4" /> Retry
            </Button>
          </div>
        </Card>
      )}

      {loading ? (
        <div className="grid grid-cols-2 gap-4 animate-pulse md:grid-cols-3 lg:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-24 rounded-xl bg-gray-100 dark:bg-slate-800" />
          ))}
        </div>
      ) : summary ? (
        <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
          <StatCard
            title="Average Score"
            value={summary.average_percentage !== null ? `${summary.average_percentage}%` : 'N/A'}
            icon={BarChart3}
            variant={
              summary.average_percentage !== null && summary.average_percentage >= 75
                ? 'success'
                : 'warning'
            }
            subtitle={`${summary.total_score} of ${summary.total_max_score} points`}
          />
          <StatCard title="Scored Appraisals" value={summary.scored_appraisals} icon={FileText} />
          <StatCard title="Employees Appraised" value={summary.employees_scored} icon={Users} />
          <StatCard title="Cycles Covered" value={summary.cycles_covered} icon={CalendarRange} />
          <StatCard
            title="Strong or Better"
            value={strongTotal}
            icon={Award}
            variant="success"
            subtitle={
              bandTotal > 0
                ? `${Math.round((strongTotal / bandTotal) * 100)}% of appraisals`
                : undefined
            }
          />
          <StatCard
            title="Needs Improvement"
            value={summary.needs_improvement}
            icon={UserX}
            variant="danger"
            subtitle="Below 50%"
          />
        </div>
      ) : null}

      {!loading && insights.length > 0 && (
        <Card title="Appraisal Insights" subtitle="Derived from the current scope and filters">
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            {insights.map((insight, i) => (
              <div
                key={i}
                className="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200"
              >
                <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-primary-500" />
                <span>{insight}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card title="Performance Trend by Cycle" subtitle="Average score, oldest cycle first">
        <div className="h-72">
          {loading ? (
            <div className="h-full animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
          ) : trendData.length === 0 ? (
            <p className="py-24 text-center text-sm text-gray-400">
              No scored appraisals for this selection.
            </p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trendData}>
                <defs>
                  <linearGradient id="scoreFill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="#10b981" stopOpacity={0.35} />
                    <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis domain={[0, 100]} tick={{ fontSize: 12 }} unit="%" />
                <Tooltip />
                <Area
                  type="monotone"
                  dataKey="Score"
                  stroke="#10b981"
                  fill="url(#scoreFill)"
                  strokeWidth={2}
                  connectNulls
                />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Score Distribution" subtitle="How scored appraisals fall into each band">
          <div className="h-72">
            {loading ? (
              <div className="h-full animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
            ) : bandData.length === 0 ? (
              <p className="py-24 text-center text-sm text-gray-400">
                No scored appraisals to band.
              </p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={bandData}
                    dataKey="count"
                    nameKey="name"
                    innerRadius={55}
                    outerRadius={90}
                    paddingAngle={2}
                  >
                    {bandData.map((entry, i) => (
                      <Cell key={i} fill={entry.fill} />
                    ))}
                    <LabelList dataKey="count" position="center" />
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>

        <Card title="Appraisals by Status" subtitle="Workflow stage of every scored appraisal">
          <div className="h-72">
            {loading ? (
              <div className="h-full animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
            ) : statusData.length === 0 ? (
              <p className="py-24 text-center text-sm text-gray-400">No data.</p>
            ) : (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={statusData} dataKey="value" nameKey="name" outerRadius={90}>
                    {statusData.map((_, i) => (
                      <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
          </div>
        </Card>
      </div>

      <Card title="Departmental Performance" subtitle="Average score by department">
        <div className="h-72">
          {loading ? (
            <div className="h-full animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
          ) : deptData.length === 0 ? (
            <p className="py-24 text-center text-sm text-gray-400">
              No department data for this selection.
            </p>
          ) : (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={deptData} layout="vertical" margin={{ left: 8, right: 24 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                <XAxis type="number" domain={[0, 100]} tick={{ fontSize: 12 }} unit="%" />
                <YAxis type="category" dataKey="name" width={150} tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="Score" fill="#6366f1" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <UnitList
          title="By Department"
          units={departments}
          onPick={(id) => applyDepartment(String(id))}
        />
        <UnitList
          title="By Section"
          units={sections}
          onPick={(id) => patch({ section_id: id, subsection_id: null })}
        />
        <UnitList
          title="By Subsection"
          units={subsections}
          onPick={(id) => patch({ subsection_id: id })}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Top Performers" subtitle="Employees with at least 2 scored appraisals">
          {loading ? (
            <div className="h-40 animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
          ) : performers.top.length === 0 ? (
            <p className="py-12 text-center text-sm text-gray-400">
              Nobody has two or more scored appraisals yet, so no ranking can be made.
            </p>
          ) : (
            <ul className="divide-y divide-gray-100 dark:divide-slate-700/60">
              {performers.top.map((p) => (
                <PerformerRow key={p.employee_pk} p={p} tone="top" />
              ))}
            </ul>
          )}
        </Card>

        <Card title="Needs Support" subtitle="Lowest averages among consistently appraised staff">
          {loading ? (
            <div className="h-40 animate-pulse rounded-lg bg-gray-100 dark:bg-slate-900/50" />
          ) : performers.bottom.length === 0 ? (
            <p className="py-12 text-center text-sm text-gray-400">
              Not enough consistently appraised staff to identify an outlier.
            </p>
          ) : (
            <ul className="divide-y divide-gray-100 dark:divide-slate-700/60">
              {performers.bottom.map((p) => (
                <PerformerRow key={p.employee_pk} p={p} tone="bottom" />
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card
        title="Appraisal Register"
        subtitle={
          loadingRows
            ? undefined
            : `${total} appraisal${total === 1 ? '' : 's'} match the current filters - every scored appraisal in your scope`
        }
      >
        {loadingRows ? (
          <div className="flex justify-center py-10">
            <Loader2 className="h-6 w-6 animate-spin text-gray-400" />
          </div>
        ) : rows.length === 0 ? (
          <div className="py-12 text-center">
            <BarChart3 className="mx-auto mb-2 h-8 w-8 text-gray-300" />
            <p className="font-medium text-gray-500 dark:text-gray-400">
              No scored appraisals found
            </p>
            <p className="mt-1 text-sm text-gray-400">
              Every filter above narrows both this table and the charts above it. Try resetting
              them.
            </p>
          </div>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    {REGISTER_COLUMNS.map((c) => (
                      <th
                        key={c.key}
                        className={`px-3 py-2 ${c.numeric ? 'text-right' : 'text-left'}`}
                      >
                        <button
                          type="button"
                          onClick={() => handleSort(c.key)}
                          className={`inline-flex items-center gap-1 text-xs font-medium uppercase tracking-wider ${
                            sort === c.key
                              ? 'text-primary-600 dark:text-primary-400'
                              : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
                          }`}
                        >
                          {c.label}
                          {sort === c.key && (
                            <span aria-hidden="true">{dir === 'asc' ? '+' : '-'}</span>
                          )}
                        </button>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 bg-white dark:divide-slate-800">
                  {rows.map((r) => (
                    <tr
                      key={r.appraisal_id}
                      className="hover:bg-gray-50 dark:hover:bg-slate-700/50"
                    >
                      <td className="whitespace-nowrap px-3 py-2 text-gray-900 dark:text-gray-100">
                        {r.employee_code}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 font-medium text-gray-900 dark:text-gray-100">
                        {r.employee_name}
                        {r.employee_type && (
                          <span className="ml-1 text-xs font-normal text-gray-400">
                            {r.employee_type}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-gray-900 dark:text-gray-100">
                        {r.department ?? '-'}
                        {r.section && (
                          <span className="block text-xs text-gray-400">{r.section}</span>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-gray-900 dark:text-gray-100">
                        {r.cycle ?? '-'}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2">
                        <Badge
                          variant={
                            r.status === 'completed'
                              ? 'success'
                              : r.status === 'rejected' || r.status === 'cancelled'
                                ? 'danger'
                                : 'default'
                          }
                        >
                          {r.status_label}
                        </Badge>
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-right">
                        {r.percentage === null ? (
                          <span className="text-gray-400">-</span>
                        ) : (
                          <>
                            <Badge variant={bandVariant(r.percentage)}>{r.percentage}%</Badge>
                            <span className="mt-0.5 block text-xs text-gray-400">
                              {r.total_score} / {r.total_max}
                            </span>
                          </>
                        )}
                      </td>
                      <td className="whitespace-nowrap px-3 py-2 text-gray-900 dark:text-gray-100">
                        {r.submitted_at ? formatDateCell(r.submitted_at) : '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {lastPage > 1 && (
              <div className="mt-4 flex items-center justify-between">
                <p className="text-sm text-gray-500">
                  Page {page} of {lastPage} - {total} appraisals
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= lastPage}
                    onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </Card>

      <p className="text-xs text-gray-400">
        Averages are weighted by points scored (total score / total possible), not an average of
        per-appraisal percentages. Only appraisals with recorded scores are included, and only
        within your organisational scope.
      </p>
    </div>
  );
};

export default AppraisalReport;

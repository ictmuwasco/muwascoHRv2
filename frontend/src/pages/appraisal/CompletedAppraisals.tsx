import { useCallback, useEffect, useMemo, useState } from 'react';
import { BarChart3, ChevronDown, ChevronUp, Download, FileText, Filter, Printer, RefreshCw, Search, X } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Combobox from '../../components/ui/Combobox';
import Modal from '../../components/ui/Modal';
import Badge from '../../components/ui/Badge';
import {
  appraisalReportService,
  type AppraisalAnalytics,
  type AppraisalReport,
  type AppraisalReportOptions,
  type CompletedAppraisalRow,
  type ReportFormat,
} from '../../api/services/appraisalReportService';
import { formatAppraisalDate } from './AppraisalUI';
import CompletedAppraisalAnalytics from '../../components/appraisal/CompletedAppraisalAnalytics';

const input =
  'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800';

const PER_PAGE = 20;

/**
 * Every workflow status the API accepts, plus the "All statuses" default.
 *
 * The list is an archive, so it opens on every status rather than a
 * hard-coded 'completed' - a supervisor asking "what has this person been
 * through" should not have to know which stage to pick.
 */
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

/** Mirrors the red/amber/green bands used in the generated documents. */
const scoreBand = (pct: number) =>
  pct < 50
    ? 'text-red-600 dark:text-red-400'
    : pct < 75
      ? 'text-amber-600 dark:text-amber-400'
      : 'text-green-600 dark:text-green-400';

const statusVariant = (status: string) => {
  if (status === 'completed') return 'success' as const;
  if (status === 'submitted') return 'default' as const;
  if (status === 'rejected' || status === 'cancelled') return 'danger' as const;
  return 'default' as const;
};

const CompletedAppraisals = () => {
  const { can, loading: authLoading } = useAuth();
  // Officers hold performance:feedback but not performance:supervise. For them
  // the API returns ONLY their own completed appraisals, so the page drops the
  // filter panel (there is nothing to choose between) and the filter lookup
  // entirely — calling it is what produced the 403.
  //
  // NOTE: isSupervisor must be read AFTER authLoading is false. AuthContext
  // delays setLoading(false) until /auth/user resolves, so the permission set
  // here is always authoritative (not the stale localStorage snapshot).
  const isSupervisor = !authLoading && can('performance', 'supervise');

  const [rows, setRows] = useState<CompletedAppraisalRow[]>([]);
  const [options, setOptions] = useState<AppraisalReportOptions | null>(null);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  const [status, setStatus] = useState('');
  const [cycleId, setCycleId] = useState('');
  const [departmentId, setDepartmentId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [subsectionId, setSubsectionId] = useState('');
  const [search, setSearch] = useState('');

  // The filter panel is collapsible: the archive is mostly read in one pass,
  // and a permanently open 6-control panel pushed the results off-screen.
  const [filtersOpen, setFiltersOpen] = useState(true);

  const [detail, setDetail] = useState<AppraisalReport | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  // Analytics are fetched separately from the table so a slow aggregation never
  // blocks the rows from appearing, and so a failure in one cannot blank the
  // other. They are driven by the SAME filters, deliberately: a headline number
  // that kept describing the whole organisation while the table showed one
  // department would be actively misleading.
  const [analytics, setAnalytics] = useState<AppraisalAnalytics | null>(null);
  const [analyticsLoading, setAnalyticsLoading] = useState(false);
  const [analyticsError, setAnalyticsError] = useState('');

  // Which unit dropdowns to show. The server pins every tier the caller does
  // NOT own and reports which one they do, so offering a department picker to
  // a section head would be a control that cannot change anything. Options
  // default to hidden until the server has told us the scope, so a scope head
  // never flashes a full set of dropdowns before narrowing to their own.
  const scopeLevel = options?.scope?.level ?? 'organisation';
  const showDepartment = isSupervisor && scopeLevel === 'organisation';
  const showSection = isSupervisor && (scopeLevel === 'organisation' || scopeLevel === 'department');
  const showSubsection = isSupervisor && scopeLevel !== 'subsection';

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await appraisalReportService.list(
        // Self-scoped officers must not send filters: the API would ignore the
        // unit filters anyway, and sending them would misrepresent intent.
        isSupervisor
          ? {
              status,
              cycle_id: cycleId ? Number(cycleId) : null,
              department_id: departmentId ? Number(departmentId) : null,
              section_id: sectionId ? Number(sectionId) : null,
              subsection_id: subsectionId ? Number(subsectionId) : null,
              search,
            }
          : {},
        page,
        PER_PAGE,
      );
      setRows(res.items ?? []);
      setTotal(res.total ?? 0);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Unable to load completed appraisals.');
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [isSupervisor, status, cycleId, departmentId, sectionId, subsectionId, search, page]);

  useEffect(() => {
    if (authLoading) return; // wait for authoritative permissions before loading
    load();
  }, [authLoading, load]);

  // Analytics follow the same filter set as the table - but NOT `page`, since
  // page 4 of the same filtered set describes identical records. Re-aggregating
  // on every pagination click would be pure waste.
  useEffect(() => {
    if (authLoading || !isSupervisor) return;
    let cancelled = false;
    setAnalyticsLoading(true);
    setAnalyticsError('');
    appraisalReportService
      .analytics({
        status,
        cycle_id: cycleId ? Number(cycleId) : null,
        department_id: departmentId ? Number(departmentId) : null,
        section_id: sectionId ? Number(sectionId) : null,
        subsection_id: subsectionId ? Number(subsectionId) : null,
        search,
      })
      .then((data) => {
        if (!cancelled) setAnalytics(data);
      })
      .catch((err: any) => {
        if (!cancelled) {
          setAnalytics(null);
          // Stays local to the panel: the table below is still perfectly
          // usable, so a failed aggregation must not take the page's main error
          // banner down with it.
          setAnalyticsError(err.response?.data?.message || 'Unable to load performance analytics.');
        }
      })
      .finally(() => {
        if (!cancelled) setAnalyticsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [authLoading, isSupervisor, status, cycleId, departmentId, sectionId, subsectionId, search]);


  // Only supervisors have filter options to fetch. Officers skipped this
  // before, which is exactly where the 403 came from.
  useEffect(() => {
    if (!isSupervisor) {
      setOptions(null);
      return;
    }
    let cancelled = false;
    appraisalReportService
      .options()
      .then((data) => {
        if (!cancelled) setOptions(data);
      })
      .catch(() => {
        if (!cancelled) setOptions(null);
      });
    return () => {
      cancelled = true;
    };
  }, [isSupervisor]);

  const statusOptions = useMemo(
    () => [
      { value: '', label: 'All statuses' },
      ...(options?.statuses ?? Object.keys(STATUS_LABELS)).map((s) => ({
        value: s,
        label: STATUS_LABELS[s] ?? s,
      })),
    ],
    [options],
  );
  const cycleOptions = useMemo(
    () =>
      (options?.cycles ?? []).map((c) => ({
        value: c.id,
        label: c.name,
        description:
          c.start_date && c.end_date
            ? `${formatAppraisalDate(c.start_date)} – ${formatAppraisalDate(c.end_date)}`
            : undefined,
      })),
    [options],
  );
  const departmentOptions = useMemo(
    () => (options?.departments ?? []).map((d) => ({ value: d.id, label: d.name })),
    [options],
  );
  const sectionOptions = useMemo(
    () => (options?.sections ?? []).map((s) => ({ value: s.id, label: s.name })),
    [options],
  );
  const subsectionOptions = useMemo(
    () => (options?.subsections ?? []).map((s) => ({ value: s.id, label: s.name })),
    [options],
  );

  /** Any filter change returns to page 1, otherwise the pager can strand you. */
  const applyFilter = (setter: (v: string) => void) => (value: string) => {
    setter(value);
    setPage(1);
  };

  /**
   * Changing a parent tier invalidates whatever was chosen beneath it, so the
   * stale child selection is cleared. Without this, picking "Technical" and
   * then "HR" left a section id belonging to Technical, which the pinned scope
   * query then AND-ed to zero rows - an empty result with no obvious cause.
   */
  const applyDepartment = (value: string) => {
    setDepartmentId(value);
    setSectionId('');
    setSubsectionId('');
    setPage(1);
  };

  const applySection = (value: string) => {
    setSectionId(value);
    setSubsectionId('');
    setPage(1);
  };

  const resetFilters = () => {
    setStatus('');
    setCycleId('');
    setDepartmentId('');
    setSectionId('');
    setSubsectionId('');
    setSearch('');
    setPage(1);
  };

  const openDetail = async (row: CompletedAppraisalRow) => {
    setDetailLoading(true);
    setError('');
    try {
      setDetail(await appraisalReportService.detail(row.id));
    } catch (err: any) {
      setError(err.response?.data?.message || 'Unable to load the appraisal breakdown.');
    } finally {
      setDetailLoading(false);
    }
  };

  const download = async (id: number, format: ReportFormat) => {
    setBusy(true);
    setError('');
    try {
      await appraisalReportService.download(id, format);
    } catch (err: any) {
      setError(err.response?.data?.message || 'The document could not be generated.');
    } finally {
      setBusy(false);
    }
  };

  const print = async (id: number) => {
    await download(id, 'print');
    setNotice("Print view ready - use your browser's print dialog.");
  };

  const hasFilters = !!status || !!cycleId || !!departmentId || !!sectionId || !!subsectionId || !!search;
  const pages = Math.max(1, Math.ceil(total / PER_PAGE));

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
            {isSupervisor ? 'Completed Appraisals' : 'My Completed Appraisals'}
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            {isSupervisor
              ? 'Finalised appraisals within your organisational scope, with score breakdown and document export.'
              : 'Your finalised appraisals. Download a copy for your records.'}
          </p>
        </div>
        <Button variant="outline" onClick={load} disabled={loading}>
          <RefreshCw className="mr-2 h-4 w-4" />Refresh
        </Button>
      </div>

      {(error || notice) && (
        <div
          className={`flex items-center rounded-lg px-4 py-3 text-sm ${error ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}
        >
          <span className="flex-1">{error || notice}</span>
          <button
            onClick={() => {
              setError('');
              setNotice('');
            }}
            className="float-right font-bold"
            aria-label="Dismiss"
          >
            ×
          </button>
        </div>
      )}

      {isSupervisor && (
        <Card>
          {/* Collapsible header - stays visible so the panel can always be
              re-opened, and shows the active filter count when collapsed. */}
          <div className="flex items-center justify-between gap-3">
            <button
              type="button"
              onClick={() => setFiltersOpen((open) => !open)}
              aria-expanded={filtersOpen}
              className="flex flex-1 items-center gap-2 text-left"
            >
              <Filter className="h-4 w-4 text-gray-500" aria-hidden="true" />
              <span className="text-sm font-medium text-gray-900 dark:text-gray-100">Filters</span>
              {hasFilters && (
                <span className="rounded-full bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800 dark:bg-primary-900 dark:text-primary-100">
                  {[
                    status && STATUS_LABELS[status],
                    cycleId,
                    departmentId,
                    sectionId,
                    subsectionId,
                    search,
                  ].filter(Boolean).length}{' '}
                  active
                </span>
              )}
              {filtersOpen ? (
                <ChevronUp className="ml-auto h-4 w-4 text-gray-400" aria-hidden="true" />
              ) : (
                <ChevronDown className="ml-auto h-4 w-4 text-gray-400" aria-hidden="true" />
              )}
            </button>
            {hasFilters && filtersOpen && (
              <Button variant="ghost" size="sm" onClick={resetFilters}>
                <X className="mr-1 h-4 w-4" />Clear
              </Button>
            )}
          </div>

          {filtersOpen && (
            <>
              <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Combobox
                  label="Status"
                  value={status}
                  onChange={applyFilter(setStatus)}
                  options={statusOptions}
                  placeholder="All statuses"
                />
                <Combobox
                  label="Appraisal cycle"
                  value={cycleId}
                  onChange={applyFilter(setCycleId)}
                  options={cycleOptions}
                  placeholder="All cycles"
                  noOptionsMessage="No appraisal cycles found."
                />
                {/* Only the tiers the caller actually owns. A section head has
                    one department - the filter could not change the result. */}
                {showDepartment && (
                  <Combobox
                    label="Department"
                    value={departmentId}
                    onChange={applyDepartment}
                    options={departmentOptions}
                    placeholder="All departments"
                    noOptionsMessage="No departments in your scope."
                  />
                )}
                {showSection && (
                  <Combobox
                    label="Section"
                    value={sectionId}
                    onChange={applySection}
                    options={sectionOptions}
                    placeholder="All sections"
                    noOptionsMessage="No sections in your scope."
                  />
                )}
                {showSubsection && (
                  <Combobox
                    label="Subsection"
                    value={subsectionId}
                    onChange={applyFilter(setSubsectionId)}
                    options={subsectionOptions}
                    placeholder="All subsections"
                    noOptionsMessage="No subsections in your scope."
                  />
                )}
              </div>
              <div className="mt-3 flex flex-col gap-2 md:flex-row md:items-end">
                <div className="relative w-full md:max-w-sm">
                  <label className="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                  <Search className="absolute left-3 top-9 h-4 w-4 text-gray-400" aria-hidden="true" />
                  <input
                    value={search}
                    onChange={(e) => {
                      setSearch(e.target.value);
                      setPage(1);
                    }}
                    placeholder="Employee name, staff number or cycle"
                    className={`${input} pl-9`}
                  />
                </div>
              </div>
            </>
          )}
        </Card>
      )}

      {isSupervisor && (
        <CompletedAppraisalAnalytics
          analytics={analytics}
          loading={analyticsLoading}
          error={analyticsError}
          scopeLevel={scopeLevel}
        />
      )}

      <Card className="overflow-hidden">
        <div className="flex items-center justify-between border-b border-gray-200 p-4 dark:border-slate-700">
          <p className="text-xs text-gray-500">
            {loading ? 'Loading…' : `${total} record${total === 1 ? '' : 's'}`}
          </p>
        </div>

        {loading ? (
          <p className="p-10 text-center text-sm text-gray-500">Loading…</p>
        ) : !rows.length ? (
          <div className="p-12 text-center text-gray-500">
            <BarChart3 className="mx-auto mb-3 h-10 w-10 text-gray-400" />
            <p className="font-medium text-gray-900 dark:text-gray-100">No appraisals found</p>
            <p className="mt-1 text-sm">
              {hasFilters
                ? 'No appraisals match the current filters.'
                : isSupervisor
                  ? 'No completed appraisals are available in your scope yet.'
                  : 'You have no completed appraisals yet.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
              <thead className="bg-gray-50 dark:bg-slate-900">
                <tr>
                  {(isSupervisor
                    ? ['Employee', 'Cycle', 'Unit', 'Score', 'Appraiser', 'Submitted', 'Status', 'Actions']
                    : ['Cycle', 'Score', 'Submitted', 'Status', 'Actions']
                  ).map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-xs uppercase tracking-wide text-gray-500">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50">
                    {isSupervisor && (
                      <td className="px-4 py-3">
                        <span className="font-semibold text-gray-900 dark:text-gray-100">{r.employee_name}</span>
                        <br />
                        <small className="text-gray-400">{r.employee_code}</small>
                      </td>
                    )}
                    <td className="px-4 py-3">
                      {r.cycle_name ?? '—'}
                      <br />
                      <small className="text-gray-400">
                        {formatAppraisalDate(r.start_date)} – {formatAppraisalDate(r.end_date)}
                      </small>
                    </td>
                    {isSupervisor && (
                      <td className="px-4 py-3">
                        {r.department_name || '—'}
                        {r.section_name && (
                          <>
                            <br />
                            <small className="text-gray-400">{r.section_name}</small>
                          </>
                        )}
                      </td>
                    )}
                    <td className={`px-4 py-3 font-semibold ${scoreBand(r.score_percentage)}`}>
                      {r.score_percentage}%
                    </td>
                    {isSupervisor && <td className="px-4 py-3">{r.appraiser_name || '—'}</td>}
                    <td className="px-4 py-3">{r.submitted_at ? formatAppraisalDate(r.submitted_at) : '—'}</td>
                    <td className="px-4 py-3">
                      <Badge variant={statusVariant(r.status)}>{r.status_label}</Badge>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {isSupervisor && (
                          <Button size="sm" variant="outline" onClick={() => openDetail(r)}>
                            <FileText className="mr-1 h-3.5 w-3.5" />Breakdown
                          </Button>
                        )}
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => download(r.id, 'pdf')}>
                          <Download className="mr-1 h-3.5 w-3.5" />PDF
                        </Button>
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => download(r.id, 'word')}>
                          <Download className="mr-1 h-3.5 w-3.5" />Word
                        </Button>
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => print(r.id)}>
                          <Printer className="mr-1 h-3.5 w-3.5" />Print
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {pages > 1 && (
        <div className="flex items-center justify-between">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="text-sm text-gray-500">
            Page {page} of {pages}
          </span>
          <Button size="sm" variant="outline" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>
            Next
          </Button>
        </div>
      )}

      <Modal
        isOpen={!!detail}
        onClose={() => setDetail(null)}
        title={detail ? `Score breakdown - ${detail.employee_name}` : 'Score breakdown'}
        size="2xl"
      >
        {detailLoading ? (
          <p className="py-12 text-center text-gray-500">Loading…</p>
        ) : detail ? (
          <div className="space-y-5">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg bg-gray-50 p-4 text-sm dark:bg-slate-700/40 md:grid-cols-3">
              {[
                ['Employee', `${detail.employee_name} (${detail.employee_code})`],
                ['Cycle', detail.cycle_name ?? '—'],
                ['Department', detail.department_name ?? '—'],
                ['Section', detail.section_name ?? '—'],
                ['Appraiser', detail.appraiser_name || '—'],
                ['Submitted', detail.submitted_at ? formatAppraisalDate(detail.submitted_at) : '—'],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs uppercase tracking-wide text-gray-500">{label}</dt>
                  <dd className="font-medium text-gray-900 dark:text-gray-100">{value}</dd>
                </div>
              ))}
            </dl>

            <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
              <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
                <thead className="bg-gray-50 dark:bg-slate-900">
                  <tr>
                    {['Activity', 'Indicator', 'Set', 'Score', '%', 'Comment'].map((h) => (
                      <th key={h} className="px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                  {!detail.lines.length ? (
                    <tr>
                      <td colSpan={6} className="p-6 text-center text-gray-500">
                        No scores have been recorded for this appraisal.
                      </td>
                    </tr>
                  ) : (
                    detail.lines.map((line, i) => {
                      const pct = line.max_score > 0 ? (line.score / line.max_score) * 100 : 0;
                      return (
                        <tr key={`${line.indicator_name}-${i}`}>
                          <td className="px-3 py-2.5">
                            {line.activity_name}
                            {line.contract_name && <p className="text-xs text-gray-400">({line.contract_name})</p>}
                          </td>
                          <td className="px-3 py-2.5">{line.indicator_name}</td>
                          <td className="px-3 py-2.5 text-right">{line.max_score}</td>
                          <td className={`px-3 py-2.5 text-right font-medium ${scoreBand(pct)}`}>{line.score}</td>
                          <td className={`px-3 py-2.5 text-right font-medium ${scoreBand(pct)}`}>{pct.toFixed(1)}%</td>
                          <td className="px-3 py-2.5 text-gray-600 dark:text-gray-300">
                            {line.appraiser_comment || '—'}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
                {!!detail.lines.length && (
                  <tfoot className="bg-green-50 dark:bg-slate-700/40">
                    <tr>
                      <td className="px-3 py-2.5 font-semibold" colSpan={2}>
                        Total
                      </td>
                      <td className="px-3 py-2.5 text-right font-semibold">{detail.total_max_score}</td>
                      <td className="px-3 py-2.5 text-right font-semibold">{detail.total_score}</td>
                      <td className={`px-3 py-2.5 text-right font-semibold ${scoreBand(detail.score_percentage)}`}>
                        {detail.score_percentage}%
                      </td>
                      <td />
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>

            {detail.employee_comment && (
              <div className="rounded-lg border border-gray-200 p-4 text-sm dark:border-slate-700">
                <h3 className="font-semibold text-gray-900 dark:text-gray-100">Employee comment</h3>
                <p className="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{detail.employee_comment}</p>
                {detail.employee_comment_date && (
                  <small className="mt-2 block text-gray-400">{formatAppraisalDate(detail.employee_comment_date)}</small>
                )}
              </div>
            )}
            {detail.supervisors_comment && (
              <div className="rounded-lg border border-gray-200 p-4 text-sm dark:border-slate-700">
                <h3 className="font-semibold text-gray-900 dark:text-gray-100">Supervisor comment</h3>
                <p className="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{detail.supervisors_comment}</p>
                {detail.supervisors_comment_date && (
                  <small className="mt-2 block text-gray-400">{formatAppraisalDate(detail.supervisors_comment_date)}</small>
                )}
              </div>
            )}

            <div className="flex flex-wrap justify-end gap-2 border-t pt-4 dark:border-slate-700">
              <Button variant="outline" disabled={busy} onClick={() => detail && download(detail.id, 'pdf')}>
                <Download className="mr-1 h-4 w-4" />PDF
              </Button>
              <Button variant="outline" disabled={busy} onClick={() => detail && download(detail.id, 'word')}>
                <Download className="mr-1 h-4 w-4" />Word
              </Button>
              <Button onClick={() => setDetail(null)}>Close</Button>
            </div>
          </div>
        ) : null}
      </Modal>
    </div>
  );
};

export default CompletedAppraisals;

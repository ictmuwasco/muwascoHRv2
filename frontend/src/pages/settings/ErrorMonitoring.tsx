import { useState, useEffect, useCallback } from 'react';
import {
  Activity, AlertTriangle, RefreshCw, Search, ChevronLeft, ChevronRight,
  Users, Gauge, ServerCrash, Copy, Check, XCircle, ShieldAlert,
} from 'lucide-react';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import Select from '../../components/ui/Select';
import { useAuth } from '../../context/AuthContext';
import {
  errorTrackingService,
  type StatsPayload,
  type GroupsPage,
  type ErrorGroup,
  type PerformancePage,
  type HealthPayload,
  type ErrorDetailPayload,
} from '../../api/services/errorTrackingService';

// ===========================================================================
// System Monitoring dashboard (Settings ▸ System Monitor)
//
// Surfaces the observability layer: error statistics & trends, spike
// detection, top failing endpoints, deduplicated error groups with full
// lifecycle management, slow-request tracking and system health - all tied
// together by the shared Request ID correlation.
// ===========================================================================

const MONITORING_ROLES = ['super_admin', 'hr_manager'];
const SEVERITIES = ['', 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO', 'DEBUG'];
const STATUSES = ['', 'NEW', 'ACKNOWLEDGED', 'INVESTIGATING', 'FIXED', 'VERIFIED', 'RESOLVED', 'IGNORED'];

const severityVariant = (s: string): 'danger' | 'warning' | 'primary' | 'default' =>
  s === 'CRITICAL' ? 'danger' : s === 'HIGH' ? 'warning' : s === 'MEDIUM' ? 'primary' : 'default';

const statusVariant = (status: string): 'danger' | 'warning' | 'success' | 'primary' | 'default' =>
  status === 'NEW' ? 'danger'
    : ['INVESTIGATING', 'ACKNOWLEDGED'].includes(status) ? 'warning'
      : ['RESOLVED', 'VERIFIED'].includes(status) ? 'success'
        : status === 'FIXED' ? 'primary' : 'default';

const levelVariant = (l: string): 'danger' | 'warning' | 'primary' =>
  l === 'critical' ? 'danger' : l === 'slow' ? 'warning' : 'primary';

const timeAgo = (dateStr?: string | null): string => {
  if (!dateStr) return '-';
  const seconds = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T')).getTime()) / 1000);
  if (Number.isNaN(seconds)) return dateStr;
  if (seconds < 60) return `${seconds}s ago`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
};

const fmtDateTime = (dateStr?: string | null): string =>
  dateStr ? dateStr.replace('T', ' ').slice(0, 19) : '-';

const num = (v: unknown): string => Number(v ?? 0).toLocaleString();

const ErrorMonitoring = () => {
  const { user } = useAuth() as any;
  const canManage = MONITORING_ROLES.includes(String(user?.role ?? ''));

  // --- Data ----------------------------------------------------------------
  const [stats, setStats] = useState<StatsPayload | null>(null);
  const [health, setHealth] = useState<HealthPayload | null>(null);
  const [groupsPage, setGroupsPage] = useState<GroupsPage>({ data: [], total: 0, page: 1, per_page: 15, pages: 0 });
  const [perfPage, setPerfPage] = useState<PerformancePage | null>(null);

  // --- UI ------------------------------------------------------------------
  const [activeTab, setActiveTab] = useState<'overview' | 'errors' | 'performance'>('overview');
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [search, setSearch] = useState('');
  const [severityFilter, setSeverityFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [moduleFilter, setModuleFilter] = useState('');
  const [page, setPage] = useState(1);

  // Detail modal
  const [detailOpen, setDetailOpen] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detail, setDetail] = useState<ErrorDetailPayload | null>(null);
  const [copiedId, setCopiedId] = useState(false);

  // Manage form
  const emptyForm = { status: '', severity: '', assigned_to: '', resolution_notes: '', fixed_version: '' };
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  // --- Loaders --------------------------------------------------------------

  const loadOverview = useCallback(async () => {
    try {
      const [s, h] = await Promise.all([errorTrackingService.getStats(), errorTrackingService.getHealth()]);
      setStats(s); setHealth(h);
    } catch (error) {
      console.error('Failed to load monitoring overview:', error);
    }
  }, []);

  const loadGroups = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, any> = { page, per_page: 15 };
      if (search.trim()) params.search = search.trim();
      if (severityFilter) params.severity = severityFilter;
      if (statusFilter) params.status = statusFilter;
      if (moduleFilter) params.module = moduleFilter;
      setGroupsPage(await errorTrackingService.getGroups(params));
    } catch (error) {
      console.error('Failed to load error groups:', error);
    } finally {
      setLoading(false);
    }
  }, [page, search, severityFilter, statusFilter, moduleFilter]);

  const loadPerformance = useCallback(async () => {
    try {
      setPerfPage(await errorTrackingService.getPerformance({ page: 1, per_page: 25 }));
    } catch (error) {
      console.error('Failed to load performance events:', error);
    }
  }, []);

  const refreshAll = useCallback(async () => {
    setRefreshing(true);
    try {
      await Promise.all([
        loadOverview(),
        activeTab === 'errors' ? loadGroups() : Promise.resolve(),
        activeTab === 'performance' ? loadPerformance() : Promise.resolve(),
      ]);
    } finally {
      setRefreshing(false);
    }
  }, [loadOverview, loadGroups, loadPerformance, activeTab]);

  useEffect(() => { loadOverview(); }, [loadOverview]);
  useEffect(() => { if (activeTab === 'errors') loadGroups(); }, [activeTab, loadGroups]);
  useEffect(() => { if (activeTab === 'performance') loadPerformance(); }, [activeTab, loadPerformance]);

  // Live-ops: refresh overview once a minute while the tab is visible.
  useEffect(() => {
    const timer = setInterval(() => {
      if (document.visibilityState === 'visible') loadOverview();
    }, 60_000);
    return () => clearInterval(timer);
  }, [loadOverview]);

  /** Open the detail modal anchored on an occurrence uuid. */
  const openDetailByUuid = useCallback(async (uuid: string) => {
    setDetailOpen(true);
    setSaveError(null);
    setDetailLoading(true);
    try {
      const d = await errorTrackingService.getErrorDetail(uuid);
      setDetail(d);
      const g = d.group;
      setForm({
        status: g?.status ?? '',
        severity: g?.severity ?? '',
        assigned_to: g?.assigned_to != null ? String(g.assigned_to) : '',
        resolution_notes: g?.resolution_notes ?? '',
        fixed_version: g?.fixed_version ?? '',
      });
    } catch (error) {
      console.error('Failed to load error detail:', error);
    } finally {
      setDetailLoading(false);
    }
  }, []);

  const copyRequestId = (requestId: string) => {
    navigator.clipboard?.writeText(requestId).then(() => {
      setCopiedId(true);
      setTimeout(() => setCopiedId(false), 1500);
    }).catch(() => { /* clipboard unavailable */ });
  };

  /** Persist lifecycle changes for the open group (server audits each change). */
  const saveChanges = async () => {
    if (!detail?.group) return;
    setSaving(true);
    setSaveError(null);
    try {
      const payload: Record<string, any> = {};
      if (form.status) payload.status = form.status;
      if (form.severity) payload.severity = form.severity;
      if (form.assigned_to.trim() !== '') payload.assigned_to = Number(form.assigned_to);
      if (form.resolution_notes !== '') payload.resolution_notes = form.resolution_notes;
      if (form.fixed_version !== '') payload.fixed_version = form.fixed_version;

      await errorTrackingService.manageGroup(detail.group.id, payload);
      await openDetailByUuid(String((detail.error as any).error_uuid));
      if (activeTab === 'errors') loadGroups();
      loadOverview();
    } catch (error: any) {
      setSaveError(error?.response?.data?.message ?? 'Failed to update error group');
    } finally {
      setSaving(false);
    }
  };

  // =========================================================================
  // Render
  // =========================================================================

  const app = stats?.application;
  const today = stats?.today;
  const healthVariant = health?.status === 'healthy' ? 'success' : health?.status === 'degraded' ? 'warning' : 'danger';
  // Thresholds with built-in fallbacks - never trust the wire for constants.
  const th = { warning_ms: 2000, slow_ms: 4000, critical_ms: 8000, ...(perfPage?.thresholds ?? {}) };

  const statCards = [
    { label: 'Errors Today',    value: num(today?.total),           icon: <ServerCrash className="h-5 w-5" />,   tone: 'text-red-600 bg-red-100 dark:bg-red-900/40 dark:text-red-300' },
    { label: 'Critical Today',  value: num(today?.critical),        icon: <AlertTriangle className="h-5 w-5" />, tone: 'text-red-700 bg-red-100 dark:bg-red-900/40 dark:text-red-300' },
    { label: 'High Severity',   value: num(today?.high),            icon: <ShieldAlert className="h-5 w-5" />,   tone: 'text-orange-600 bg-orange-100 dark:bg-orange-900/40 dark:text-orange-300' },
    { label: 'Open Groups',     value: num(stats?.open_groups),     icon: <Activity className="h-5 w-5" />,      tone: 'text-primary-600 bg-primary-100 dark:bg-primary-900/40 dark:text-primary-300' },
    { label: 'Affected Users',  value: num(today?.affected_users),  icon: <Users className="h-5 w-5" />,         tone: 'text-purple-600 bg-purple-100 dark:bg-purple-900/40 dark:text-purple-300' },
    { label: 'Failed Requests', value: num(today?.failed_requests), icon: <XCircle className="h-5 w-5" />,       tone: 'text-rose-600 bg-rose-100 dark:bg-rose-900/40 dark:text-rose-300' },
    { label: 'Slow Requests',   value: num(today?.slow_requests),   icon: <Gauge className="h-5 w-5" />,         tone: 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900/40 dark:text-yellow-300' },
    { label: 'Client Errors',   value: num(today?.client),          icon: <Activity className="h-5 w-5" />,      tone: 'text-sky-600 bg-sky-100 dark:bg-sky-900/40 dark:text-sky-300' },
  ];

  return (
    <div className="space-y-6">
      {/* Header: title + health + deployment identity + refresh ------------- */}
      <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">System Monitoring</h2>
          {health && (
            <Badge variant={healthVariant as any}>
              {health.status.toUpperCase()}
              {health.db_latency_ms != null ? ` · DB ${health.db_latency_ms}ms` : ''}
            </Badge>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
          {app?.environment && <span className="px-2 py-1 rounded bg-gray-100 dark:bg-slate-700 dark:text-gray-200 font-mono">{app.environment}</span>}
          {app?.version && <span className="px-2 py-1 rounded bg-gray-100 dark:bg-slate-700 dark:text-gray-200 font-mono">v{app.version}</span>}
          {app?.git_commit && (
            <span className="px-2 py-1 rounded bg-gray-100 dark:bg-slate-700 dark:text-gray-200 font-mono" title={`Commit ${app.git_commit}`}>
              {String(app.git_commit).slice(0, 7)}
            </span>
          )}
          <Button variant="outline" size="sm" onClick={refreshAll} disabled={refreshing} className="ml-1">
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
            Refresh
          </Button>
        </div>
      </div>

      {/* Sub-tab navigation -------------------------------------------------- */}
      <div className="border-b border-gray-200 dark:border-slate-700">
        <nav className="-mb-px flex space-x-8">
          {(['overview', 'errors', 'performance'] as const).map((tabId) => (
            <button
              key={tabId}
              onClick={() => setActiveTab(tabId)}
              className={`py-2 px-1 border-b-2 text-sm font-medium capitalize transition-colors ${
                activeTab === tabId
                  ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:border-slate-500'
              }`}
            >
              {tabId}
              {tabId === 'overview' && stats && (stats.today.critical > 0 || stats.spikes.length > 0) && (
                <span className="ml-2 inline-flex h-2 w-2 rounded-full bg-red-500 align-middle" />
              )}
            </button>
          ))}
        </nav>
      </div>

      {/* ================================================================== */}
      {/* OVERVIEW                                                           */}
      {/* ================================================================== */}
      {activeTab === 'overview' && (
        <div className="space-y-6">
          {/* Stat cards ----------------------------------------------------- */}
          <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
            {statCards.map((card) => (
              <Card key={card.label} className="p-4">
                <div className="flex items-center gap-3">
                  <div className={`p-2 rounded-lg ${card.tone}`}>{card.icon}</div>
                  <div className="min-w-0">
                    <p className="text-xs text-gray-500 dark:text-gray-400 truncate">{card.label}</p>
                    <p className="text-xl font-bold text-gray-900 dark:text-gray-100">{card.value}</p>
                  </div>
                </div>
              </Card>
            ))}
          </div>

          {/* Spike alerts ---------------------------------------------------- */}
          {stats && stats.spikes.length > 0 && (
            <div className="rounded-xl border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 p-4 space-y-3">
              <div className="flex items-center gap-2 text-amber-800 dark:text-amber-200 font-semibold">
                <AlertTriangle className="h-5 w-5" />
                Error Spikes Detected ({stats.spikes.length})
              </div>
              <div className="grid md:grid-cols-2 gap-3">
                {stats.spikes.map((spike) => {
                  const increase = Math.max(100, Math.round((spike.last_hour / Math.max(1, spike.avg_per_hour)) * 100 - 100));
                  return (
                    <button
                      key={spike.id}
                      onClick={() => { setSearch(spike.fingerprint); setModuleFilter(''); setSeverityFilter(''); setStatusFilter(''); setActiveTab('errors'); setPage(1); }}
                      className="bg-white dark:bg-slate-800/60 rounded-lg border border-amber-200 dark:border-amber-800 p-3 flex items-center justify-between gap-3 text-left hover:border-amber-400 dark:hover:border-amber-600 transition-colors"
                    >
                      <div className="min-w-0">
                        <p className="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{spike.title}</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400 font-mono truncate">{spike.fingerprint}</p>
                        <Badge variant={severityVariant(spike.severity) as any} className="mt-1">{spike.severity}</Badge>
                      </div>
                      <div className="text-right shrink-0">
                        <Badge variant="danger">+{increase}%</Badge>
                        <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">{spike.last_hour}/hr vs ~{spike.avg_per_hour}/hr</p>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* Hourly trend + severity distribution ----------------------------- */}
          <div className="grid lg:grid-cols-3 gap-4">
            <Card className="lg:col-span-2 p-4" title="Errors · Last 24 Hours">
              {stats && stats.hourly_series.length > 0 ? (
                (() => {
                  const maxTotal = Math.max(...stats.hourly_series.map((b) => Number(b.total)), 1);
                  return (
                    <div className="flex items-end gap-1 h-36 pt-4">
                      {stats.hourly_series.map((bucket) => (
                        <div key={bucket.bucket} className="flex-1 group relative flex flex-col justify-end h-full">
                          <div
                            className={`w-full rounded-t transition-all ${
                              Number(bucket.total) > maxTotal * 0.75 ? 'bg-red-500'
                                : Number(bucket.total) > maxTotal * 0.4 ? 'bg-amber-400'
                                  : 'bg-primary-400'}`}
                            style={{ height: `${Math.max(4, (Number(bucket.total) / maxTotal) * 100)}%` }}
                          />
                          <span className="absolute bottom-full mb-1 hidden group-hover:block left-1/2 -translate-x-1/2 whitespace-nowrap bg-gray-900 text-white text-xs rounded px-2 py-1 z-10 pointer-events-none">
                            {bucket.bucket} · {bucket.total}
                          </span>
                        </div>
                      ))}
                    </div>
                  );
                })()
              ) : (
                <p className="text-sm text-gray-400 py-10 text-center">No errors in the last 24 hours 🎉</p>
              )}
            </Card>

            <Card className="p-4" title="By Severity · 7 days">
              <div className="space-y-2.5">
                {(stats?.by_severity ?? []).map((row) => {
                  const total = (stats?.by_severity ?? []).reduce((sum, r) => sum + Number(r.total), 0) || 1;
                  const pct = Math.round((Number(row.total) / total) * 100);
                  return (
                    <div key={row.severity}>
                      <div className="flex justify-between text-xs mb-1">
                        <span className="font-medium">{row.severity}</span>
                        <span className="text-gray-400">{num(row.total)} · {pct}%</span>
                      </div>
                      <div className="h-1.5 bg-gray-100 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div
                          className={`h-full rounded-full ${
                            row.severity === 'CRITICAL' ? 'bg-red-500'
                              : row.severity === 'HIGH' ? 'bg-orange-400'
                                : row.severity === 'MEDIUM' ? 'bg-yellow-400' : 'bg-gray-300'}`}
                          style={{ width: `${Math.max(2, pct)}%` }}
                        />
                      </div>
                    </div>
                  );
                })}
                {(!stats || stats.by_severity.length === 0) && (
                  <p className="text-sm text-gray-400 py-6 text-center">No data</p>
                )}
              </div>
            </Card>
          </div>

          {/* Modules + top failing endpoints ----------------------------------- */}
          <div className="grid lg:grid-cols-3 gap-4">
            <Card className="p-4" title="Affected Modules · 7 days">
              <div className="flex flex-wrap gap-2">
                {(stats?.by_module ?? []).map((m) => (
                  <button
                    key={m.module}
                    onClick={() => { setSearch(''); setModuleFilter(m.module); setSeverityFilter(''); setStatusFilter(''); setActiveTab('errors'); setPage(1); }}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-gray-200 dark:border-slate-600 hover:border-primary-400 hover:text-primary-700 dark:hover:text-primary-400 text-sm"
                  >
                    {m.module}
                    <span className="px-1.5 rounded-full bg-gray-100 dark:bg-slate-700 text-gray-600 dark:text-gray-200 text-xs font-semibold">{num(m.total)}</span>
                  </button>
                ))}
                {(!stats || stats.by_module.length === 0) && <p className="text-sm text-gray-400">No module errors recorded.</p>}
              </div>
            </Card>

            <Card className="lg:col-span-2 p-4 pb-0" title="Top Failing Endpoints · 7 days">
              {(stats?.top_endpoints ?? []).length > 0 ? (
                <Table
                  columns={[
                    {
                      key: 'endpoint',
                      label: 'Endpoint',
                      render: (_v: any, row: any) => (
                        <button
                          onClick={() => { setSearch(row.endpoint); setModuleFilter(''); setSeverityFilter(''); setStatusFilter(''); setActiveTab('errors'); setPage(1); }}
                          className="font-mono text-xs text-left hover:text-primary-700"
                          title="Show error groups for this endpoint"
                        >
                          <span className="font-bold mr-1.5">{row.http_method}</span>
                          {row.endpoint}
                        </button>
                      ),
                    },
                    { key: 'errors', label: 'Errors', render: (v: any) => <span className="font-semibold">{num(v)}</span> },
                    { key: 'users', label: 'Users', render: (v: any) => num(v) },
                    { key: 'last_seen', label: 'Last Seen', render: (v: any) => timeAgo(v) },
                  ]}
                  data={stats?.top_endpoints ?? []}
                />
              ) : (
                <p className="text-sm text-gray-400 py-6 px-1">No endpoint failures recorded.</p>
              )}
            </Card>
          </div>
        </div>
      )}




      {/* ================================================================== */}
      {/* ERRORS                                                             */}
      {/* ================================================================== */}
      {activeTab === 'errors' && (
        <div className="space-y-4">
          {/* Filter bar ------------------------------------------------------ */}
          <Card className="p-4">
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
              <div className="relative lg:col-span-2">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                <input
                  value={search}
                  onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                  placeholder="Search fingerprint, title, hash…"
                  className="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md text-sm bg-white dark:bg-slate-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                />
              </div>
              <Select
                value={severityFilter}
                onChange={(e: any) => { setSeverityFilter(e.target.value); setPage(1); }}
                options={[{ value: '', label: 'All Severities' }, ...SEVERITIES.filter(Boolean).map((s) => ({ value: s, label: s }))]}
              />
              <Select
                value={statusFilter}
                onChange={(e: any) => { setStatusFilter(e.target.value); setPage(1); }}
                options={[{ value: '', label: 'All Statuses' }, ...STATUSES.filter(Boolean).map((s) => ({ value: s, label: s }))]}
              />
            </div>
            {moduleFilter && (
              <div className="flex items-center gap-2 mt-3 text-sm text-gray-600 dark:text-gray-300">
                Module filter:
                <Badge variant="primary">{moduleFilter}</Badge>
                <button onClick={() => setModuleFilter('')} className="text-xs text-primary-700 underline">clear</button>
              </div>
            )}
          </Card>

          {/* Groups table ---------------------------------------------------- */}
          <Card className="pb-2" title={`Error Groups (${num(groupsPage.total)})`}>
            <Table
              columns={[
                {
                  key: 'title',
                  label: 'Error',
                  render: (_v: any, g: ErrorGroup) => (
                    <button onClick={() => openDetailByUuid(String(g.id))} className="text-left max-w-md">
                      <span className="font-medium text-gray-900 dark:text-gray-100 hover:text-primary-700 dark:hover:text-primary-400 block truncate">{g.title}</span>
                      <span className="text-xs font-mono text-gray-400 dark:text-gray-500 block truncate">{g.fingerprint}</span>
                    </button>
                  ),
                },
                { key: 'module', label: 'Module', render: (v: any) => <Badge variant="default">{v}</Badge> },
                { key: 'severity', label: 'Severity', render: (v: any) => <Badge variant={severityVariant(v) as any}>{v}</Badge> },
                { key: 'status', label: 'Status', render: (v: any) => <Badge variant={statusVariant(v) as any}>{v}</Badge> },
                { key: 'occurrence_count', label: 'Hits', render: (v: any) => <span className="font-semibold">{num(v)}</span> },
                { key: 'affected_user_count', label: 'Users', render: (v: any) => num(v) },
                { key: 'last_seen_at', label: 'Last Seen', render: (v: any) => timeAgo(v) },
                {
                  key: 'id',
                  label: '',
                  render: (_v: any, g: ErrorGroup) => (
                    <button onClick={() => openDetailByUuid(String(g.id))} className="text-primary-700 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300 text-xs font-medium">
                      View
                    </button>
                  ),
                },
              ]}
              data={groupsPage.data}
            />

            {!loading && groupsPage.data.length === 0 && (
              <p className="text-sm text-gray-400 py-10 px-6 text-center">No error groups match the current filters.</p>
            )}
            {loading && <p className="text-sm text-gray-400 py-10 px-6 text-center animate-pulse">Loading…</p>}

            {/* Pagination ------------------------------------------------------ */}
            <div className="flex items-center justify-between px-6 py-3 border-t border-gray-100 dark:border-slate-700 text-sm">
              <span className="text-gray-500 dark:text-gray-400">
                Page {groupsPage.page} of {Math.max(1, groupsPage.pages)} · {num(groupsPage.total)} groups
              </span>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={groupsPage.page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>
                  <ChevronLeft className="h-4 w-4" /> Prev
                </Button>
                <Button variant="outline" size="sm" disabled={groupsPage.page >= groupsPage.pages} onClick={() => setPage((p) => p + 1)}>
                  Next <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* ================================================================== */}
      {/* PERFORMANCE                                                        */}
      {/* ================================================================== */}
      {activeTab === 'performance' && perfPage && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
            {[
              { label: 'Events · 24h', value: num(perfPage.summary.total) },
              { label: 'Slow',         value: num(perfPage.summary.slow), tone: 'text-yellow-600' },
              { label: 'Critical',     value: num(perfPage.summary.critical), tone: 'text-red-600' },
              { label: 'Avg Duration', value: `${num(perfPage.summary.avg_ms)} ms` },
              { label: 'Max Duration', value: `${num(perfPage.summary.max_ms)} ms`, tone: 'text-gray-900' },
            ].map((card) => (
              <Card key={card.label} className="p-4">
                <p className="text-xs text-gray-500 dark:text-gray-400">{card.label}</p>
                <p className={`text-xl font-bold ${card.tone ?? 'text-gray-900 dark:text-gray-100'}`}>{card.value}</p>
              </Card>
            ))}
          </div>

          <Card className="pb-2" title="Slow Request Events">
            <Table
              columns={[
                {
                  key: 'endpoint',
                  label: 'Endpoint',
                  render: (_v: any, row: any) => (
                    <button
                      onClick={() => row.request_id && navigator.clipboard?.writeText(String(row.request_id)).catch(() => {})}
                      disabled={!row.request_id}
                      className="font-mono text-xs text-left hover:text-primary-700 disabled:text-gray-400"
                      title={row.request_id ? `Copy request id ${row.request_id}` : ''}>
                      <span className="font-bold mr-1.5">{row.http_method}</span>
                      {row.endpoint || '-'}
                    </button>
                  ),
                },
                {
                  key: 'duration_ms',
                  label: 'Duration',
                  render: (v: any) => (
                    <span className={`font-semibold ${Number(v) >= th.critical_ms ? 'text-red-600' : Number(v) >= th.slow_ms ? 'text-orange-500' : 'text-gray-900'}`}>
                      {num(v)} ms
                    </span>
                  ),
                },
                { key: 'threshold_level', label: 'Level', render: (v: any) => <Badge variant={levelVariant(v) as any}>{v}</Badge> },
                { key: 'status_code', label: 'HTTP', render: (v: any) => v ?? '-' },
                { key: 'memory_kb', label: 'Memory', render: (v: any) => (v ? `${num(Math.round(Number(v) / 1024))} MB` : '-') },
                { key: 'created_at', label: 'When', render: (v: any) => fmtDateTime(v) },
              ]}
              data={perfPage.data}
            />
            {perfPage.data.length === 0 && (
              <p className="text-sm text-gray-400 py-10 px-6 text-center">
                No slow requests recorded. Thresholds: warning ≥ {th.warning_ms}ms · slow ≥ {th.slow_ms}ms · critical ≥ {th.critical_ms}ms
              </p>
            )}
          </Card>
        </div>
      )}
      {/* ================================================================== */}
      {/* ERROR DETAIL MODAL                                                 */}
      {/* ================================================================== */}
      <Modal
        isOpen={detailOpen}
        onClose={() => { setDetailOpen(false); setDetail(null); }}
        title={detail?.group ? `Error Group #${detail.group.id}` : 'Error Detail'}
        size="2xl"
      >
        {detailLoading && <p className="text-sm text-gray-400 animate-pulse py-8 text-center">Loading detail…</p>}

        {!detailLoading && detail && (
          <div className="space-y-6">
            {/* Overview ------------------------------------------------------ */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Overview</h4>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                <div>
                  <p className="text-gray-500 text-xs">Severity</p>
                  {detail.group && <Badge variant={severityVariant(detail.group.severity) as any}>{detail.group.severity}</Badge>}
                </div>
                <div>
                  <p className="text-gray-500 text-xs">Status</p>
                  {detail.group && <Badge variant={statusVariant(detail.group.status) as any}>{detail.group.status}</Badge>}
                </div>
                <div><p className="text-gray-500 text-xs">Occurrences</p><p className="font-bold">{num(detail.group?.occurrence_count)}</p></div>
                <div><p className="text-gray-500 text-xs">Affected Users</p><p className="font-bold">{num(detail.group?.affected_user_count)}</p></div>
                <div><p className="text-gray-500 text-xs">First Seen</p>{fmtDateTime(detail.group?.first_seen_at)}</div>
                <div><p className="text-gray-500 text-xs">Last Seen</p>{fmtDateTime(detail.group?.last_seen_at)}</div>
                <div><p className="text-gray-500 text-xs">Module</p>{detail.group?.module ?? '-'}</div>
                <div><p className="text-gray-500 text-xs">Category</p>{String((detail.error as any).category ?? '-')}</div>
              </div>
              {detail.group?.resolution_notes && (
                <div className="mt-3 rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 p-3 text-sm">
                  <p className="font-medium text-green-800 dark:text-green-300">Resolution Notes</p>
                  <p className="text-green-700 dark:text-green-200 whitespace-pre-wrap">{detail.group.resolution_notes}</p>
                  {detail.group.fixed_version && <p className="text-xs text-green-600 dark:text-green-400 mt-1">Fixed in v{detail.group.fixed_version}</p>}
                </div>
              )}
            </section>

            {/* Request ------------------------------------------------------- */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Request</h4>
              <div className="rounded-lg bg-gray-50 dark:bg-slate-900/50 p-3 space-y-1.5 text-sm font-mono break-all">
                <div className="flex items-center gap-2">
                  <span className="text-gray-500 w-24 shrink-0 text-xs font-sans dark:text-gray-400">Request ID</span>
                  <span>{String((detail.error as any).request_id ?? '-')}</span>
                  {(detail.error as any).request_id && (
                    <button onClick={() => copyRequestId(String((detail.error as any).request_id))} className="text-primary-600" title="Copy request id">
                      {copiedId ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    </button>
                  )}
                </div>
                <div><span className="text-gray-500 w-24 inline-block text-xs font-sans dark:text-gray-400">Endpoint</span>
                  <span className="font-bold mr-2">{String((detail.error as any).http_method ?? '')}</span>
                  {String((detail.error as any).endpoint ?? '-')}
                </div>
                <div><span className="text-gray-500 w-24 inline-block text-xs font-sans dark:text-gray-400">HTTP Status</span>{String((detail.error as any).status_code ?? '-')}</div>
                <div><span className="text-gray-500 w-24 inline-block text-xs font-sans dark:text-gray-400">Source</span>{String((detail.error as any).source)} · {String((detail.error as any).environment)}</div>
                <div><span className="text-gray-500 w-24 inline-block text-xs font-sans dark:text-gray-400">IP</span>{String((detail.error as any).ip_address ?? '-')}</div>
                <div className="truncate"><span className="text-gray-500 w-24 inline-block text-xs font-sans dark:text-gray-400">User Agent</span>{String((detail.error as any).user_agent ?? '-')}</div>
              </div>
            </section>

            {/* Exception ----------------------------------------------------- */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                Exception {!detail.can_sensitive && (
                  <span className="normal-case font-normal text-amber-600 dark:text-amber-400">(technical details restricted)</span>
                )}
              </h4>
              <div className="space-y-1.5 text-sm">
                <p><span className="text-gray-500 text-xs inline-block w-28">Class</span><code>{String((detail.error as any).exception_class ?? '-')}</code></p>
                <p className="break-all"><span className="text-gray-500 text-xs inline-block w-28">Message</span>{String((detail.error as any).message ?? '-')}</p>
                <p className="break-all"><span className="text-gray-500 text-xs inline-block w-28">File : Line</span><code>{String((detail.error as any).file ?? '-')}:{String((detail.error as any).line ?? '-')}</code></p>
                {detail.can_sensitive && (detail.error as any).stack_trace && (
                  <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-slate-900 text-slate-100 text-xs p-3 whitespace-pre-wrap">
                    {String((detail.error as any).stack_trace)}
                  </pre>
                )}
              </div>
            </section>

            {/* Application --------------------------------------------------- */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Application</h4>
              <div className="flex flex-wrap gap-2 text-xs">
                <Badge variant="default">{detail.application.environment}</Badge>
                <Badge variant="primary">v{detail.application.version || '?'}</Badge>
                {detail.application.git_commit && <Badge variant="default">{String(detail.application.git_commit).slice(0, 7)}</Badge>}
                {detail.application.deployment_id && <Badge variant="default">deploy: {String(detail.application.deployment_id)}</Badge>}
                {(detail.error as any).application_version && (
                  <Badge variant={String((detail.error as any).application_version) !== detail.application.version ? 'warning' : 'default'}>
                    occurred on v{String((detail.error as any).application_version)}
                  </Badge>
                )}
              </div>
            </section>

            {/* Related audit events (request-id bridge to the audit trail) ----- */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                Related Audit Events ({detail.audit_events.length})
              </h4>
              {detail.audit_events.length > 0 ? (
                <Table
                  columns={[
                    { key: 'created_at', label: 'Time', render: (v: any) => fmtDateTime(v) },
                    { key: 'module', label: 'Module' },
                    { key: 'action', label: 'Action' },
                    { key: 'description', label: 'Description', render: (v: any) => <span className="block max-w-sm truncate">{v}</span> },
                    { key: 'user_name_snapshot', label: 'User' },
                  ]}
                  data={detail.audit_events}
                />
              ) : (
                <p className="text-sm text-gray-400">No audit events share this request id.</p>
              )}
            </section>

            {/* Recent occurrences --------------------------------------------- */}
            <section>
              <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Recent Occurrences</h4>
              {detail.occurrences.length > 0 ? (
                <Table
                  columns={[
                    { key: 'created_at', label: 'When', render: (v: any) => timeAgo(v) },
                    {
                      key: 'source',
                      label: 'Source',
                      render: (v: any) => <Badge variant={v === 'client' ? 'primary' : v === 'job' ? 'warning' : 'default'}>{String(v)}</Badge>,
                    },
                    { key: 'endpoint', label: 'Endpoint', render: (v: any) => <span className="font-mono text-xs block max-w-[16rem] truncate">{v ?? '-'}</span> },
                    { key: 'application_version', label: 'Version', render: (v: any) => (v ? `v${v}` : '-') },
                    {
                      key: 'error_uuid',
                      label: '',
                      render: (_v: any, row: any) => (
                        <button onClick={() => openDetailByUuid(String(row.error_uuid))} className="text-xs text-primary-700 underline">
                          inspect
                        </button>
                      ),
                    },
                  ]}
                  data={detail.occurrences}
                />
              ) : (
                <p className="text-sm text-gray-400">This is the only recorded occurrence.</p>
              )}
            </section>

            {/* Manage / resolution --------------------------------------------- */}
            {canManage && detail.group && (
              <section className="border-t pt-4">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Triage &amp; Resolution</h4>
                <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                  <Select
                    label="Status"
                    value={form.status}
                    onChange={(e: any) => setForm({ ...form, status: e.target.value })}
                    options={STATUSES.filter(Boolean).map((s) => ({ value: s, label: s }))}
                  />
                  <Select
                    label="Severity"
                    value={form.severity}
                    onChange={(e: any) => setForm({ ...form, severity: e.target.value })}
                    options={SEVERITIES.filter(Boolean).map((s) => ({ value: s, label: s }))}
                  />
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Assigned To (user id)</label>
                    <input
                      type="number"
                      min={0}
                      value={form.assigned_to}
                      onChange={(e) => setForm({ ...form, assigned_to: e.target.value })}
                      placeholder="Unassigned"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md text-sm bg-white dark:bg-slate-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Fixed Version</label>
                    <input
                      value={form.fixed_version}
                      onChange={(e) => setForm({ ...form, fixed_version: e.target.value })}
                      placeholder="e.g. 2.8.2"
                      className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md text-sm bg-white dark:bg-slate-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                    />
                  </div>
                </div>
                <textarea
                  rows={2}
                  value={form.resolution_notes}
                  onChange={(e) => setForm({ ...form, resolution_notes: e.target.value })}
                  placeholder="Resolution notes — root cause and fix (audited)"
                  className="w-full px-3 py-2 border border-gray-300 dark:border-slate-600 rounded-md text-sm bg-white dark:bg-slate-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                />
                {saveError && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{saveError}</p>}
                <div className="mt-3 flex justify-end">
                  <Button onClick={saveChanges} disabled={saving}>
                    {saving ? 'Saving…' : 'Save Changes'}
                  </Button>
                </div>
              </section>
            )}
          </div>
        )}
      </Modal>
    </div>
  );
}

export default ErrorMonitoring;

import { useCallback, useEffect, useState } from 'react';
import apiClient from '../../../api/client';
import { useAuth } from '../../../context/AuthContext';
import Button from '../../../components/ui/Button';
import Card from '../../../components/ui/Card';
import { AlertTriangle, CheckCircle, Download, Plus, RefreshCw } from 'lucide-react';
import { workplanService } from '../../../api/services/workplanService';
import type { WorkplanObjective } from '../../../api/services/workplanService';
import { appraisalCycleService } from '../../../api/services/appraisalCycleService';
import type { AppraisalCycle } from '../../../api/services/appraisalCycleService';
import BulkWorkplanModal from './BulkWorkplanModal';
import TierAddWorkplanModal from './TierAddWorkplanModal';
import { useWorkplanTier, downloadWorkplanCsv, type TierView } from './useWorkplanTier';
import WorkplanDashboard from './WorkplanDashboard';
import WorkplanFilters from './WorkplanFilters';
import WorkplanActivityTable, { type ProgressPatch } from './WorkplanActivityTable';
import WorkplanActivityFormModal, { type StrategyRefs } from './WorkplanActivityFormModal';
import CascadeActivityDialog from './CascadeActivityDialog';
import TraceabilityPanel from './TraceabilityPanel';
import HistoryModal from './HistoryModal';

interface Props {
  view: TierView;
  title: string;
  description: string;
  allowContractless?: boolean;
  showSection?: boolean;
  showSubsection?: boolean;
  showOfficer?: boolean;
  showIntegratedFlag?: boolean;
  showCommitmentsPanel?: boolean;
}

type FormState =
  | { open: false }
  | { open: true; mode: 'add'; presetContractId: number | null }
  | { open: true; mode: 'edit'; record: WorkplanObjective };

/**
 * Shared engine behind all four workplan tiers. Every data call is scoped by
 * the backend to the authenticated user's organisational unit - this component
 * simply renders whatever the API allows the caller to see and do.
 */
export default function TierWorkplanPage({
  view, title, description,
  allowContractless = false, showSection = false, showSubsection = false,
  showOfficer = false, showIntegratedFlag = false, showCommitmentsPanel = false,
}: Props) {
  const tier = useWorkplanTier(view);
  const { user } = useAuth();
  const [refs, setRefs] = useState<StrategyRefs>({ contracts: [], goals: [], targets: [] });
  const [fys, setFys] = useState<{ id: number; year_name: string }[]>([]);
  const [cycles, setCycles] = useState<AppraisalCycle[]>([]);
  const [bulkOpen, setBulkOpen] = useState(false);
  const [form, setForm] = useState<FormState>({ open: false });
  const [cascadeParent, setCascadeParent] = useState<WorkplanObjective | null>(null);
  const [tierAddOpen, setTierAddOpen] = useState(false);
  const [traceId, setTraceId] = useState<number | null>(null);
  const [historyId, setHistoryId] = useState<number | null>(null);

  // Strategy reference data (plans/goals/targets/FYs) + caller-scoped contracts.
  useEffect(() => {
    let cancelled = false;
    Promise.all([
      apiClient.get('/strategic-plans'),
      apiClient.get('/performance-contracts'),
      appraisalCycleService.list(),
    ]).then(([spRes, pcRes, cycRes]: any[]) => {
      if (cancelled) return;
      const sd = spRes.data?.data ?? {};
      const contracts = (pcRes.data?.data?.contracts ?? []).map((c: any) => ({
        id: c.id,
        name: c.name,
        goal_id: c.goal_id,
        target_id: c.target_id,
        department_id: c.department_id ?? null,
        department_name: c.department_name ?? null,
      }));
      setRefs({ contracts, goals: sd.goals ?? [], targets: sd.targets ?? [] });
      setFys(sd.financial_years ?? []);
      setCycles((cycRes.data?.cycles ?? []) as AppraisalCycle[]);
    }).catch(() => { /* reference pickers degrade gracefully */ });
    return () => { cancelled = true; };
  }, []);

  const saveProgress = useCallback(async (row: WorkplanObjective, patch: ProgressPatch) => {
    try {
      await workplanService.updateProgress(row.id, patch);
      tier.setNotice('Progress updated.');
      await tier.reload();
    } catch (err: any) {
      tier.setError(err.response?.data?.message || 'Failed to update progress.');
    }
  }, [tier]);

  const deleteRow = useCallback(async (row: WorkplanObjective) => {
    if (!window.confirm('Delete this activity? Its history is kept but it will be removed from the workplan.')) return;
    try {
      await workplanService.remove(row.id);
      tier.setNotice('Activity deleted.');
      await tier.reload();
    } catch (err: any) {
      tier.setError(err.response?.data?.message || 'Failed to delete the activity.');
    }
  }, [tier]);

  const exportCsv = async () => {
    try {
      await downloadWorkplanCsv();
      tier.setNotice('Workplan export downloaded.');
    } catch {
      tier.setError('Export failed.');
    }
  };

  /** Shared post-save routine: surface the message, reload the list and
   *  re-pull the section / subsection source activities (they may change when
   *  management cascades new work into the unit between page loads). */
  const handleSaved = (msg: string) => {
    tier.setNotice(msg);
    tier.reload();
    refreshSources();
  };

  const rows = tier.list?.workplans ?? [];
  const pagination = tier.list?.pagination ?? null;
  const canManage = !!tier.list?.can_manage;

  // Section heads review only the activities they personally created; sourced /
  // cascaded items are managed through the add + cascade flows instead.
  const visibleRows = view === 'section' && user?.id != null
    ? rows.filter((r) => Number(r.created_by) === Number(user.id))
    : rows;

  // Pin reference data (contracts) and the create flows to the caller's own
  // department so a department head never sees a neighbour's workplan options.
  const scopeInfo = tier.list?.scope;
  const wideRole = !!scopeInfo
    && (scopeInfo.role === 'super_admin' || scopeInfo.role === 'hr_manager' || scopeInfo.role === 'managing_director');
  const deptId = !wideRole && scopeInfo && scopeInfo.department != null ? scopeInfo.department : null;
  const deptContracts = deptId != null
    ? refs.contracts.filter((c) => c.department_id === deptId)
    : refs.contracts;
  // Source activities for section / subsection heads - fetched from the
  // dedicated sectionSources endpoint so the server handles unit-scoping,
  // the parent_objective_id IS NOT NULL filter (cascaded only) and the
  // created_by != self exclusion (no string-vs-integer strict-inequality bug).
  // Refreshed after saves and manual refreshes (see below) so the picker picks
  // up work cascaded into the unit without needing a full page remount.
  const [sectionSources, setSectionSources] = useState<{ id: number; objective: string }[]>([]);
  const [sourcesVersion, setSourcesVersion] = useState(0);
  const refreshSources = useCallback(() => setSourcesVersion((v) => v + 1), []);
  useEffect(() => {
    if (view !== 'section' && view !== 'subsection') return;
    let alive = true;
    workplanService.sectionSources(view)
      .then((res) => {
        if (!alive) return;
        setSectionSources(
          (res.data?.sources ?? []).map((s) => ({ id: s.id, objective: s.objective })),
        );
      })
      .catch(() => { setSectionSources([]); });
    return () => { alive = false; };
  }, [view, sourcesVersion]);

  if (tier.loading && !tier.list) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600" />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{title}</h2>
          <p className="text-sm text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>

      {(tier.error || tier.notice) && (
        <div className={`rounded-lg px-4 py-3 text-sm flex items-start gap-2 ${
          tier.error
            ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
            : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'}`}>
          {tier.error ? <AlertTriangle className="h-4 w-4 mt-0.5 flex-none" /> : <CheckCircle className="h-4 w-4 mt-0.5 flex-none" />}
          <span className="flex-1">{tier.error || tier.notice}</span>
          <button onClick={() => { tier.setError(''); tier.setNotice(''); }} className="text-xs underline">dismiss</button>
        </div>
      )}

      <WorkplanDashboard summary={tier.summary} />

      {tier.summary && (() => {
        const s = tier.summary.scope;
        const broadRole = ['super_admin', 'hr_manager', 'managing_director'].includes(s.role);
        const unresolved = !broadRole && s.department === null && s.section === null && s.subsection === null;
        if (unresolved) {
          return (
            <div className="rounded-lg bg-amber-50 dark:bg-amber-900/30 border border-amber-200 dark:border-amber-800 px-4 py-3 text-sm text-amber-800 dark:text-amber-200 flex items-start gap-2">
              <AlertTriangle className="h-4 w-4 mt-0.5 flex-none" />
              <span>
                This login (<strong>{s.role}</strong>) is not linked to an active employee profile, so your
                department / section could not be detected and the workplan shows nothing. Ask an administrator
                to open <strong>Settings → Users</strong> and link this account to your employee record.
              </span>
            </div>
          );
        }
        if (tier.summary.totals.total_activities === 0) {
          return (
            <div className="rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 px-4 py-3 text-sm text-blue-800 dark:text-blue-200">
              No workplan activities in <strong>{tier.summary.unit_label}</strong> yet.{' '}
              {canManage
                ? view === 'department'
                  ? 'Create your first one with “Add Departmental Workplan”, or copy commitments from your contract below.'
                  : view === 'section'
                    ? 'Create your own activities with “Add Section Workplan”, or wait for your supervisor to cascade work down to you.'
                    : view === 'subsection'
                      ? 'Create your own activities with “Add Subsection Workplan”, or wait for your supervisor to cascade work down to you.'
                      : 'Create your first one with “New Activity”, or wait for your supervisor to cascade work down to you.'
                : 'This page fills up automatically as soon as your supervisor cascades work to your unit.'}
            </div>
          );
        }
        return null;
      })()}

      {showCommitmentsPanel && deptContracts.length > 0 && (
        <Card title="Source Commitments (Performance Contracts)">
          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
            {deptContracts.slice(0, 9).map((c) => (
              <button key={c.id}
                onClick={() => setBulkOpen(true)}
                disabled={!canManage}
                className="text-left rounded-lg border border-gray-200 dark:border-slate-700 px-3 py-2 hover:border-primary-300 hover:bg-primary-50/40 dark:hover:bg-slate-700/40 transition-colors disabled:opacity-60">
                <p className="text-sm font-medium text-gray-800 dark:text-gray-100 line-clamp-1">{c.name}</p>
                <p className="text-xs text-gray-400 dark:text-gray-500">
                  {c.department_name ? `Dept: ${c.department_name}` : 'Department commitment'} · click to plan an activity
                </p>
              </button>
            ))}
            {deptContracts.length > 9 && (
              <p className="col-span-full text-xs text-gray-400">{deptContracts.length - 9} more commitments available in the activity form.</p>
            )}
          </div>
        </Card>
      )}

      <WorkplanFilters
        status={tier.status} onStatusChange={tier.setStatus}
        searchInput={tier.searchInput} onSearchInputChange={tier.setSearchInput}
        onApplySearch={tier.applySearch}
        parentFilter={tier.parentFilter} onParentFilterChange={tier.setParentFilter}
        financialYearId={tier.fyId} onFinancialYearChange={tier.setFyId} financialYears={fys}
        actions={
          <>
            <Button variant="outline" onClick={exportCsv}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            <Button variant="outline" onClick={() => { tier.reload(); refreshSources(); }}><RefreshCw className="h-4 w-4 mr-2" />Refresh</Button>
            {canManage && (
              view === 'department' ? (
                <Button onClick={() => setBulkOpen(true)}>
                  <Plus className="h-4 w-4 mr-2" />Add Departmental Workplan
                </Button>
              ) : view === 'section' ? (
                <Button onClick={() => setTierAddOpen(true)}>
                  <Plus className="h-4 w-4 mr-2" />Add Section Workplan
                </Button>
              ) : view === 'subsection' ? (
                <Button onClick={() => setTierAddOpen(true)}>
                  <Plus className="h-4 w-4 mr-2" />Add Subsection Workplan
                </Button>
              ) : (
                <Button onClick={() => setForm({ open: true, mode: 'add', presetContractId: null })}>
                  <Plus className="h-4 w-4 mr-2" />New Activity
                </Button>
              )
            )}
          </>
        }
      />

      <WorkplanActivityTable
        rows={visibleRows}
        canManage={canManage}
        showOfficer={showOfficer}
        onSaveProgress={saveProgress}
        onEdit={(row) => setForm({ open: true, mode: 'edit', record: row })}
        onCascade={(row) => setCascadeParent(row)}
        onTrace={(row) => setTraceId(row.id)}
        onHistory={(row) => setHistoryId(row.id)}
        onDelete={deleteRow}
      />

      {pagination && pagination.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
          <span>Showing {visibleRows.length} activities · page {pagination.current_page} of {pagination.last_page}</span>
          <div className="flex gap-2">
            <Button variant="outline" disabled={pagination.current_page <= 1}
              onClick={() => tier.setPage((p: number) => Math.max(1, p - 1))}>Previous</Button>
            <Button variant="outline" disabled={pagination.current_page >= pagination.last_page}
              onClick={() => tier.setPage((p: number) => p + 1)}>Next</Button>
          </div>
        </div>
      )}

      <WorkplanActivityFormModal
        isOpen={form.open}
        mode={form.open ? form.mode : 'add'}
        record={form.open && form.mode === 'edit' ? form.record : null}
        presetContractId={form.open && form.mode === 'add' ? form.presetContractId : null}
        refs={refs}
        sections={tier.list?.sections ?? []}
        subsections={tier.list?.subsections ?? []}
        employees={tier.list?.employees ?? []}
        allowContractless={allowContractless}
        showSection={showSection}
        showSubsection={showSubsection}
        showOfficer={showOfficer}
        showIntegratedFlag={showIntegratedFlag}
        mdMode={view === 'md'}
        departmentId={deptId}
        cycles={cycles}
        onClose={() => setForm({ open: false })}
        onSaved={handleSaved}
        onError={tier.setError}
      />

      {view === 'department' && (
        <BulkWorkplanModal
          isOpen={bulkOpen}
          contracts={deptContracts}
          departmentId={deptId}
          sections={tier.list?.sections ?? []}
          subsections={tier.list?.subsections ?? []}
          employees={tier.list?.employees ?? []}
          cycles={cycles}
          onClose={() => setBulkOpen(false)}
          onSaved={handleSaved}
          onError={tier.setError}
        />
      )}

      {(view === 'section' || view === 'subsection') && (
        <TierAddWorkplanModal
          isOpen={tierAddOpen}
          kind={view === 'section' ? 'section' : 'subsection'}
          sources={sectionSources}
          subsections={tier.list?.subsections ?? []}
          employees={tier.list?.employees ?? []}
          sectionId={tier.list?.scope.section ?? null}
          cycles={cycles}
          onClose={() => setTierAddOpen(false)}
          onSaved={handleSaved}
          onError={tier.setError}
        />
      )}

      <CascadeActivityDialog
        isOpen={cascadeParent !== null}
        parent={cascadeParent}
        contracts={deptContracts}
        departmentId={deptId}
        sections={tier.list?.sections ?? []}
        subsections={tier.list?.subsections ?? []}
        employees={tier.list?.employees ?? []}
        cycles={cycles}
        onClose={() => setCascadeParent(null)}
        onCascaded={(msg) => { tier.setNotice(msg); tier.reload(); }}
        onError={tier.setError}
      />

      <TraceabilityPanel objectiveId={traceId} onClose={() => setTraceId(null)} />
      <HistoryModal objectiveId={historyId} onClose={() => setHistoryId(null)}
        objectiveTitle={historyId !== null
          ? rows.find((r) => r.id === historyId)?.objective ?? null
          : null} />
    </div>
  );
}

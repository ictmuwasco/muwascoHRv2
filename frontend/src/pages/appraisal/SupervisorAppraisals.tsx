import { useCallback, useEffect, useMemo, useState } from 'react';
import { BarChart3, Plus, RefreshCw, Search } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import Button from '../../components/ui/Button';
import Card from '../../components/ui/Card';
import Modal from '../../components/ui/Modal';
import Combobox from '../../components/ui/Combobox';
import { appraisalService, type AppraisalDetail, type AppraisalWorkspace, type AppraisalWorkflowRow } from '../../api/services/appraisalService';
import { AppraisalStatus, formatAppraisalDate } from './AppraisalUI';
import SupervisorAppraisalDetail from './SupervisorAppraisalDetail';

type Tab = 'regular' | 'pending' | 'escalated' | 'rejected';
const TABS: Array<[Tab, string]> = [['regular', 'Regular Appraisals'], ['pending', 'Pending Approval'], ['escalated', 'Escalated'], ['rejected', 'Rejected History']];
const input = 'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800';

export default function SupervisorAppraisals() {
  const { can } = useAuth();
  const canScore = can('performance', 'score');
  const canApprove = can('performance', 'approve');
  const [workspace, setWorkspace] = useState<AppraisalWorkspace | null>(null);
  const [tab, setTab] = useState<Tab>('regular');
  const [rows, setRows] = useState<AppraisalWorkflowRow[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [detail, setDetail] = useState<AppraisalDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [employeeId, setEmployeeId] = useState('');
  const [cycleId, setCycleId] = useState('');
  // Validation feedback for the create dialog. It has to live INSIDE the modal:
  // the page-level banner renders behind the overlay, so writing there made
  // the dialog look like it silently did nothing.
  const [createError, setCreateError] = useState('');

  const load = useCallback(async (next: Tab) => {
    setLoading(true); setError('');
    try { const [w, list] = await Promise.all([appraisalService.workspace(), appraisalService.list(next)]); setWorkspace(w); setRows(list); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to load supervisor appraisals.'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => {
    if (!canApprove && tab !== 'regular') setTab('regular');
  }, [canApprove, tab]);
  useEffect(() => { load(tab); }, [load, tab]);
  const filtered = useMemo(() => { const q = search.trim().toLowerCase(); return q ? rows.filter((r) => `${r.employee_name} ${r.employee_code} ${r.cycle_name}`.toLowerCase().includes(q)) : rows; }, [rows, search]);

  // Flatten the workspace payload into Combobox options. Keeping the search
  // text in the Combobox (client-side over the already-authorized roster)
  // means the picker can never widen the caller's data scope.
  const employeeOptions = useMemo(
    () =>
      (workspace?.employees ?? []).map((e) => ({
        value: e.id,
        label: `${(e.first_name || '').trim()} ${(e.last_name || '').trim()} (${e.employee_id})`.trim(),
        description: [e.department_name, e.employee_type?.replace(/_/g, ' ')].filter(Boolean).join(' · '),
      })),
    [workspace],
  );
  const cycleOptions = useMemo(
    () =>
      (workspace?.cycles ?? []).map((c) => ({
        value: c.id,
        label: c.name,
        description: `${formatAppraisalDate(c.start_date)} – ${formatAppraisalDate(c.end_date)}`,
      })),
    [workspace],
  );


  const openDetail = async (row: AppraisalWorkflowRow) => {
    setDetailLoading(true); setError('');
    try { setDetail(await appraisalService.detail(tab, row.id)); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to load appraisal details.'); }
    finally { setDetailLoading(false); }
  };
  const create = async () => {
    if (!employeeId || !cycleId) { setCreateError('Select an employee and appraisal cycle.'); return; }
    setCreateError(''); setBusy(true);
    try { await appraisalService.create({ employee_id: +employeeId, appraisal_cycle_id: +cycleId }); setCreateOpen(false); setEmployeeId(''); setCycleId(''); setNotice('Appraisal draft created successfully.'); setTab('regular'); await load('regular'); }
    catch (err: any) { setCreateError(err.response?.data?.message || 'Unable to create appraisal.'); }
    finally { setBusy(false); }
  };
  const save = async (scores: Record<number, number>, comments: Record<number, string>, supervisor_comment: string) => {
    if (!detail) return; setBusy(true);
    try { await appraisalService.saveScores(detail.id, { scores, comments, supervisor_comment }); setNotice('Scores saved and employee notified.'); setDetail(null); await load(tab); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to save scores.'); }
    finally { setBusy(false); }
  };
  const decide = async (decision: string, comment: string) => {
    if (!detail) return; setBusy(true);
    try { await appraisalService.decide(detail.id, { decision, comment }); setNotice('Decision recorded successfully.'); setDetail(null); await load(tab); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to record decision.'); }
    finally { setBusy(false); }
  };

  if (loading && !workspace) return <div className="flex h-64 items-center justify-center"><div className="h-12 w-12 animate-spin rounded-full border-b-2 border-primary-600" /></div>;
  const visibleTabs = canApprove ? TABS : TABS.filter(([id]) => id === 'regular');
  return <div className="space-y-6">
    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Performance Appraisals</h1><p className="text-gray-500 dark:text-gray-400">Manage appraisals across your authorized organizational scope.</p></div><div className="flex gap-2"><Button variant="outline" onClick={() => load(tab)}><RefreshCw className="mr-2 h-4 w-4" />Refresh</Button>{canScore && <Button onClick={() => setCreateOpen(true)}><Plus className="mr-2 h-4 w-4" />New appraisal</Button>}</div></div>
    {(error || notice) && <div className={`rounded-lg px-4 py-3 text-sm ${error ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'}`}><span className="flex-1">{error || notice}</span><button onClick={() => { setError(''); setNotice(''); }} className="float-right font-bold">×</button></div>}
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">{visibleTabs.map(([id, label]) => <button key={id} onClick={() => { setTab(id); setDetail(null); }} className={`rounded-xl border p-4 text-left ${tab === id ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 bg-white dark:border-slate-700 dark:bg-slate-800'}`}><p className="text-xs uppercase tracking-wide text-gray-500">{label}</p><p className="mt-1 text-2xl font-bold text-primary-600">{workspace?.counts[id] ?? 0}</p></button>)}</div>
    <Card className="overflow-hidden"><div className="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-slate-700 md:flex-row md:items-center md:justify-between"><div><h2 className="font-semibold">{visibleTabs.find(([id]) => id === tab)?.[1]}</h2><p className="text-xs text-gray-500">{filtered.length} record{filtered.length === 1 ? '' : 's'}</p></div><div className="relative w-full md:max-w-xs"><Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search employee or cycle" className={`${input} pl-9`} /></div></div>
      {loading ? <p className="p-10 text-center text-sm text-gray-500">Loading…</p> : !filtered.length ? <div className="p-12 text-center text-gray-500"><BarChart3 className="mx-auto mb-3 h-10 w-10 text-gray-400" /><p className="font-medium text-gray-900">No appraisals found</p></div> : <div className="overflow-x-auto"><table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700"><thead className="bg-gray-50 dark:bg-slate-900"><tr>{['Employee', 'Cycle', 'Unit', 'Score', 'Status', 'Action'].map((h) => <th key={h} className="px-4 py-3 text-left text-xs uppercase tracking-wide text-gray-500">{h}</th>)}</tr></thead><tbody className="divide-y divide-gray-100 dark:divide-slate-800">{filtered.map((r) => <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/50"><td className="px-4 py-3"><b>{r.employee_name}</b><br /><small className="text-gray-400">{r.employee_code} · {r.employee_type?.replace(/_/g, ' ')}</small></td><td className="px-4 py-3">{r.cycle_name}<br /><small className="text-gray-400">{formatAppraisalDate(r.start_date)} – {formatAppraisalDate(r.end_date)}</small></td><td className="px-4 py-3">{r.department_name || '—'}{r.section_name && <><br /><small className="text-gray-400">{r.section_name}</small></>}</td><td className="px-4 py-3 font-semibold text-primary-600">{r.score_percentage ?? 0}%</td><td className="px-4 py-3"><AppraisalStatus status={r.status} label={r.status_label} /></td><td className="px-4 py-3"><Button size="sm" variant="outline" onClick={() => openDetail(r)}>Review</Button></td></tr>)}</tbody></table></div>}
    </Card>
    <Modal isOpen={createOpen} onClose={() => { setCreateOpen(false); setCreateError(''); }} title="Start Performance Appraisal" size="md">
      <form
        className="space-y-4"
        onSubmit={(e) => { e.preventDefault(); create(); }}
      >
        <Combobox
          label="Employee"
          required
          value={employeeId}
          onChange={(v) => { setEmployeeId(v); setCreateError(''); }}
          options={employeeOptions}
          placeholder="Search employees by name, number or department"
          emptyMessage="No employee matches your search."
          noOptionsMessage="No employees in your scope are available for appraisal."
          error={!employeeId && createError ? createError : undefined}
        />
        <Combobox
          label="Appraisal cycle"
          required
          value={cycleId}
          onChange={(v) => { setCycleId(v); setCreateError(''); }}
          options={cycleOptions}
          placeholder="Search active appraisal cycles"
          emptyMessage="No cycle matches your search."
          noOptionsMessage="No active appraisal cycles are available."
          error={employeeId && !cycleId && createError ? createError : undefined}
        />
        {createError && employeeId && cycleId && (
          <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300">{createError}</p>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={() => { setCreateOpen(false); setCreateError(''); }}>Cancel</Button>
          <Button type="submit" loading={busy}>Create</Button>
        </div>
      </form>
    </Modal>
    <Modal isOpen={!!detail} onClose={() => setDetail(null)} title={detail ? `${detail.employee_name} · ${detail.cycle_name || 'Appraisal'}` : 'Appraisal detail'} size="2xl">{detailLoading ? <p className="py-12 text-center text-gray-500">Loading…</p> : detail && <SupervisorAppraisalDetail detail={detail} canScore={canScore} canApprove={canApprove} saving={busy} deciding={busy} onSave={save} onDecision={decide} onClose={() => setDetail(null)} />}</Modal>
  </div>;
}

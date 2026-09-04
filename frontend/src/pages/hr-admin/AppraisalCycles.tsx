import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../../context/AuthContext';
import Card from '../../components/ui/Card';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import { appraisalCycleService, type AppraisalCycle, type FinancialYearRef } from '../../api/services/appraisalCycleService';
import { Plus, Pencil, Trash2, RefreshCw, AlertTriangle, CheckCircle } from 'lucide-react';

const fmt = (d: string | null) =>
  d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'N/A';

const statusVariant = (s: string): 'success' | 'warning' | 'default' =>
  s === 'active' ? 'success' : s === 'completed' ? 'default' : 'warning';

type FormState =
  | { open: false }
  | { open: true; mode: 'add' }
  | { open: true; mode: 'edit'; record: AppraisalCycle };

/**
 * HR Admin → Appraisal Cycles. The quarterly cycles (Q1..Q4 of a financial
 * year) that every workplan activity at every level attaches itself to.
 */
export default function AppraisalCycles() {
  // Centralized effective-permission check (matches the API gate
  // performance:manage) — no hardcoded role arrays.
  const { can } = useAuth();
  const canManage = can('performance', 'manage');

  const [cycles, setCycles] = useState<AppraisalCycle[]>([]);
  const [financialYears, setFinancialYears] = useState<FinancialYearRef[]>([]);
  const [fyFilter, setFyFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [form, setForm] = useState<FormState>({ open: false });

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await appraisalCycleService.list();
      setCycles(res.data?.cycles ?? []);
      setFinancialYears(res.data?.financial_years ?? []);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load appraisal cycles.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const runAction = async (fn: () => Promise<any>, successMsg: string) => {
    setError(''); setNotice('');
    try {
      await fn();
      setNotice(successMsg);
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || 'The request could not be completed.');
    }
  };

  const confirmDelete = (row: AppraisalCycle) => {
    if (!window.confirm(`Delete "${row.name}"? Cycles still referenced by workplan activities are protected.`)) return;
    runAction(() => appraisalCycleService.remove(row.id), `"${row.name}" deleted.`);
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Appraisal Cycles</h1>
          <p className="text-gray-500">
            The quarterly performance periods every workplan activity is scheduled against
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={load}><RefreshCw className="h-4 w-4 mr-2" />Refresh</Button>
          {canManage && (
            <Button onClick={() => setForm({ open: true, mode: 'add' })}>
              <Plus className="h-4 w-4 mr-2" />Add Cycle
            </Button>
          )}
        </div>
      </div>

      {(error || notice) && (
        <div className={`rounded-lg px-4 py-3 text-sm flex items-start gap-2 ${
          error ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'}`}>
          {error ? <AlertTriangle className="h-4 w-4 mt-0.5" /> : <CheckCircle className="h-4 w-4 mt-0.5" />}
          <span>{error || notice}</span>
        </div>
      )}

      {/* Financial Year filter */}
      <div className="flex flex-wrap items-center gap-3">
        <select className="rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200"
          value={fyFilter} onChange={(e) => setFyFilter(e.target.value)}>
          <option value="">All Financial Years</option>
          {financialYears.map((fy) => (
            <option key={fy.id} value={String(fy.id)}>{fy.year_name}</option>
          ))}
        </select>
        <span className="text-xs text-gray-400">
          {cycles.length} cycle{cycles.length === 1 ? '' : 's'} · every cycle belongs to one financial year
        </span>
      </div>

      <Card
        title={`Quarterly Cycles — ${fyFilter
          ? (financialYears.find((f) => String(f.id) === fyFilter)?.year_name ?? 'Selected FY')
          : 'All Financial Years'}`}>
        {loading ? (
          <div className="flex items-center justify-center py-10">
            <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600" />
          </div>
        ) : cycles.length === 0 ? (
          <p className="text-center text-gray-500 py-8">
            No appraisal cycles yet.{canManage ? ' Click "Add Cycle" to create Q1–Q4 for a financial year.' : ''}
          </p>
        ) : (
          <div className="space-y-6">
            {groupCyclesByFy(cycles, financialYears, fyFilter).map((group) => (
              <div key={group.fyId}>
                <div className="flex items-center justify-between mb-1">
                  <Badge variant={group.isActive ? 'success' : 'default'}>
                    {group.fyName}{group.isActive ? ' · Active' : ''}
                    <span className="ml-1 text-xs opacity-75">({group.rows.length})</span>
                  </Badge>
                </div>
                <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 bg-white dark:bg-slate-800">
                    <thead className="bg-gray-50 dark:bg-slate-900/60">
                      <tr>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Cycle</th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Period</th>
                        <th className="px-4 py-3 text-left text-xs font-medium uppercase text-gray-500">Status</th>
                        {canManage && <th className="px-4 py-3 text-right text-xs font-medium uppercase text-gray-500">Actions</th>}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
                      {group.rows.map((c) => (
                        <tr key={c.id} className="hover:bg-gray-50 dark:hover:bg-slate-700/30">
                          <td className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{c.name}</td>
                          <td className="px-4 py-3 text-sm text-gray-500">{fmt(c.start_date)} – {fmt(c.end_date)}</td>
                          <td className="px-4 py-3"><Badge variant={statusVariant(c.status)}>{c.status}</Badge></td>
                          {canManage && (
                            <td className="px-4 py-3 text-right whitespace-nowrap">
                              <button onClick={() => setForm({ open: true, mode: 'edit', record: c })}
                                title="Edit cycle"
                                className="p-1.5 rounded hover:bg-blue-50 dark:hover:bg-blue-900/30 text-blue-600 mr-1">
                                <Pencil className="h-4 w-4" />
                              </button>
                              <button onClick={() => confirmDelete(c)} title="Delete cycle"
                                className="p-1.5 rounded hover:bg-red-50 dark:hover:bg-red-900/30 text-red-600">
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            ))}
          </div>
        )}
      </Card>

      {form.open && (
        <CycleFormModal
          mode={form.mode}
          record={form.mode === 'edit' ? form.record : undefined}
          financialYears={financialYears}
          onClose={() => setForm({ open: false })}
          onSubmit={(payload, id) => {
            const mode = form.mode;
            setForm({ open: false });
            runAction(
              () => (mode === 'add'
                ? appraisalCycleService.create(payload)
                : appraisalCycleService.update(id!, payload)),
              `Appraisal cycle ${mode === 'add' ? 'created' : 'updated'}.`,
            );
          }}
        />
      )}
    </div>
  );
}

// ========================================================================
// Cycle grouping helper
// ========================================================================

interface FyGroup { fyId: number | 'unknown'; fyName: string; isActive: boolean; rows: AppraisalCycle[] }

function groupCyclesByFy(cycles: AppraisalCycle[], fys: FinancialYearRef[], fyFilter: string): FyGroup[] {
  const groups = new Map<string, FyGroup>();
  for (const fy of fys) {
    groups.set(String(fy.id), { fyId: fy.id, fyName: fy.year_name, isActive: !!fy.is_active, rows: [] });
  }
  groups.set('unknown', { fyId: 'unknown', fyName: 'Unassigned', isActive: false, rows: [] });

  for (const c of cycles) {
    if (fyFilter && String(c.financial_year_id) !== fyFilter) continue;
    const key = c.financial_year_id ? String(c.financial_year_id) : 'unknown';
    (groups.get(key) ?? groups.get('unknown')!).rows.push(c);
  }
  return [...groups.values()].filter((g) => g.rows.length > 0);
}

// ========================================================================
// Add / Edit appraisal cycle modal
// ========================================================================

interface CycleFormModalProps {
  mode: 'add' | 'edit';
  record?: AppraisalCycle;
  financialYears: FinancialYearRef[];
  onClose: () => void;
  onSubmit: (payload: Record<string, unknown>, id?: number) => void;
}

function CycleFormModal({ mode, record, financialYears, onClose, onSubmit }: CycleFormModalProps) {
  const [name, setName] = useState(record?.name ?? '');
  const [fyId, setFyId] = useState<string>(record?.financial_year_id ? String(record.financial_year_id) : '');
  const [startDate, setStartDate] = useState(record?.start_date?.slice(0, 10) ?? '');
  const [endDate, setEndDate] = useState(record?.end_date?.slice(0, 10) ?? '');
  const [status, setStatus] = useState<string>(record?.status ?? 'active');
  const [err, setErr] = useState('');

  const selectedFy = financialYears.find((f) => String(f.id) === fyId);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return setErr('The cycle name is required.');
    if (!fyId) return setErr('Select the financial year this cycle belongs to.');
    if (!startDate) return setErr('The start date is required.');
    if (!endDate) return setErr('The end date is required.');
    if (new Date(endDate) < new Date(startDate)) return setErr('End date cannot be before start date.');
    if (selectedFy && (startDate < selectedFy.start_date.slice(0, 10) || endDate > selectedFy.end_date.slice(0, 10))) {
      return setErr(`The cycle dates must fall inside the ${selectedFy.year_name} financial year (${selectedFy.start_date.slice(0, 10)} to ${selectedFy.end_date.slice(0, 10)}).`);
    }
    setErr('');
    onSubmit({
      name: name.trim(),
      financial_year_id: Number(fyId),
      start_date: startDate,
      end_date: endDate,
      status,
    }, record?.id);
  };

  const inputCls =
    'w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm';

  return (
    <Modal isOpen={true} onClose={onClose} size="md"
      title={mode === 'add' ? 'Add Appraisal Cycle' : 'Edit Appraisal Cycle'}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Financial Year *</label>
          <select className={inputCls} value={fyId} onChange={(e) => {
            const next = e.target.value;
            setFyId(next);
            // Auto-suggest a quarterly name when none has been typed yet.
            const fy = financialYears.find((f) => String(f.id) === next);
            if (fy && !name.trim()) setName(`Q1 ${fy.year_name} Appraisal Cycle`);
          }}>
            <option value="">— Select financial year —</option>
            {financialYears.map((fy) => (
              <option key={fy.id} value={String(fy.id)}>
                {fy.year_name}{fy.is_active ? ' (Active)' : ''}
              </option>
            ))}
          </select>
          {selectedFy && (
            <p className="text-xs text-gray-400 mt-1">
              Cycle period must be within {selectedFy.start_date.slice(0, 10)} – {selectedFy.end_date.slice(0, 10)}
            </p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cycle Name *</label>
          <input className={inputCls} value={name} onChange={(e) => setName(e.target.value)}
            placeholder="e.g. Q1 2026/2027 Appraisal" required />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date *</label>
            <input type="date" className={inputCls} value={startDate}
              min={selectedFy?.start_date?.slice(0, 10)}
              max={selectedFy?.end_date?.slice(0, 10)}
              onChange={(e) => setStartDate(e.target.value)} required />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date *</label>
            <input type="date" className={inputCls} value={endDate}
              min={selectedFy?.start_date?.slice(0, 10)}
              max={selectedFy?.end_date?.slice(0, 10)}
              onChange={(e) => setEndDate(e.target.value)} required />
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
          <select className={inputCls} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="completed">Completed</option>
          </select>
        </div>

        {err && <p className="text-sm text-red-600">{err}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit">{mode === 'add' ? 'Add Cycle' : 'Save changes'}</Button>
        </div>
      </form>
    </Modal>
  );
}

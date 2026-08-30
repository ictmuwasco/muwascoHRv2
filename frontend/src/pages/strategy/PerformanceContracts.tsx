import { useState, useEffect, useCallback, Fragment } from 'react';
import { Link, useLocation } from 'react-router-dom';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import {
  FileText, Plus, Pencil, Trash2, Eye, RefreshCw,
  AlertTriangle, CheckCircle,
} from 'lucide-react';

interface Contract {
  id: number;
  strategic_plan_id: number;
  goal_id: number;
  target_id: number | null;
  name: string;
  kra: string | null;
  department_id: number;
  financial_year_id: number;
  created_at: string;
  department_name: string | null;
  goal_name: string | null;
  strategic_plan_name: string | null;
  target_name: string | null;
  financial_year_name: string | null;
  objective_count: number;
}

interface StrategyData {
  plans: { id: number; name: string }[];
  goals: { id: number; strategic_plan_id: number; name: string }[];
  targets: {
    id: number; goal_id: number; strategic_plan_id: number;
    department_id: number | null; name: string; department_name: string | null;
  }[];
  departments: { id: number; name: string }[];
  financial_years: { id: number; year_name: string }[];
}

interface ContractPayload {
  contracts: Contract[];
  can_manage: boolean;
  can_view_all: boolean;
}

type FormState =
  | { open: false }
  | { open: true; mode: 'add' }
  | { open: true; mode: 'edit'; record: Contract };

const TABS = [
  { to: '/strategy/strategic-plan', label: 'Strategic Plan' },
  { to: '/strategy/performance-contracts', label: 'Performance Contracts' },
  { to: '/strategy/workplans', label: 'Workplans' },
  { to: '/strategy/reports', label: 'Performance Reports' },
];

const fmt = (d: string | null) =>
  d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'N/A';

export default function PerformanceContracts() {
  const location = useLocation();
  const [payload, setPayload] = useState<ContractPayload | null>(null);
  const [strategy, setStrategy] = useState<StrategyData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [fyFilter, setFyFilter] = useState('');
  const [deptFilter, setDeptFilter] = useState('');
  const [detail, setDetail] = useState<Contract | null>(null);
  const [detailWorkplans, setDetailWorkplans] = useState<
    { id: number; objective: string; kpi: string; measure_unit: string;
      section_name: string | null; subsection_name: string | null }[]
  >([]);
  const [form, setForm] = useState<FormState>({ open: false });

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const params: Record<string, string> = {};
      if (fyFilter) params.financial_year_id = fyFilter;
      if (deptFilter) params.department_id = deptFilter;
      const [contractsRes, strategyRes] = await Promise.all([
        apiClient.get('/performance-contracts', { params }),
        apiClient.get('/strategic-plans'),
      ]);
      setPayload(contractsRes.data?.data ?? null);
      const sd = strategyRes.data?.data;
      if (sd) {
        setStrategy({
          plans: sd.plans ?? [],
          goals: sd.goals ?? [],
          targets: sd.targets ?? [],
          departments: sd.departments ?? [],
          financial_years: sd.financial_years ?? [],
        });
      }
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load performance contracts.');
    } finally {
      setLoading(false);
    }
  }, [fyFilter, deptFilter]);

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

  const confirmDelete = (row: Contract) => {
    if (!window.confirm(`Delete "${row.name}"? Contracts with workplan objectives or KPIs are protected.`)) return;
    runAction(
      () => apiClient.delete(`/performance-contracts/${row.id}`),
      `"${row.name}" deleted.`,
    );
  };

  const openDetail = async (id: number) => {
    try {
      const res = await apiClient.get(`/performance-contracts/${id}`);
      setDetail(res.data?.data ?? null);
      setDetailWorkplans(res.data?.data?.workplans ?? []);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load contract detail.');
    }
  };
if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  if (!payload) {
    return <Card title="Performance Contracts"><p className="text-gray-500">{error || 'No data available.'}</p></Card>;
  }

  const canManage = payload.can_manage;
  const contracts = payload.contracts;

  // Legacy-parity grouping: Strategic Plan → Goal Perspective → contract rows.
  const planGroups = new Map<number, Contract[]>();
  for (const c of contracts) {
    const key = c.strategic_plan_id;
    if (!planGroups.has(key)) planGroups.set(key, []);
    planGroups.get(key)!.push(c);
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Performance Contracts</h1>
          <p className="text-gray-500">
            Departmental contracts aligned to the strategic plan
            {!payload.can_view_all && ' · showing your department'}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={load}><RefreshCw className="h-4 w-4 mr-2" />Refresh</Button>
          {canManage && strategy && strategy.plans.length > 0 && (
            <Button onClick={() => setForm({ open: true, mode: 'add' })}>
              <Plus className="h-4 w-4 mr-2" />Add Performance Contract
            </Button>
          )}
        </div>
      </div>

      {/* Strategy sub-navigation */}
      <div className="flex space-x-1 border-b overflow-x-auto">
        {TABS.map((tab) => (
          <Link key={tab.to} to={tab.to}
            className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
              location.pathname === tab.to ? 'border-primary-600 text-primary-600' : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}>
            {tab.label}
          </Link>
        ))}
      </div>

      {(error || notice) && (
        <div className={`rounded-lg px-4 py-3 text-sm flex items-start gap-2 ${
          error ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'}`}>
          {error ? <AlertTriangle className="h-4 w-4 mt-0.5" /> : <CheckCircle className="h-4 w-4 mt-0.5" />}
          <span>{error || notice}</span>
        </div>
      )}

      {/* Filters */}
      <Card title="Filters">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Financial Year</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={fyFilter} onChange={(e) => setFyFilter(e.target.value)}>
              <option value="">All financial years</option>
              {(strategy?.financial_years ?? []).map((f) => (
                <option key={f.id} value={f.id}>{f.year_name}</option>
              ))}
            </select>
          </div>
          {canManage && (
            <div>
              <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
              <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
                value={deptFilter} onChange={(e) => setDeptFilter(e.target.value)}>
                <option value="">All departments</option>
                {(strategy?.departments ?? []).map((d) => (
                  <option key={d.id} value={d.id}>{d.name}</option>
                ))}
              </select>
            </div>
          )}
        </div>
      </Card>
{/* Grouped contract listing */}
      {contracts.length === 0 ? (
        <Card title="Performance Contracts (0)">
          <div className="text-center py-10">
            <FileText className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">No performance contracts found</p>
            {!canManage && (
              <p className="text-sm text-gray-400 mt-1">
                Contracts are created by HR / the PME department. You see your own department's contracts.
              </p>
            )}
            {canManage && strategy && strategy.plans.length === 0 && (
              <p className="text-sm text-gray-400 mt-1">Create a strategic plan first — contracts must align to one.</p>
            )}
          </div>
        </Card>
      ) : (
        [...planGroups.entries()].map(([planId, planContracts]) => {
          const planName = planContracts[0]?.strategic_plan_name ?? 'Unknown strategic plan';
          // Sub-group by goal perspective inside each plan (order preserved).
          const goalGroups: { goalId: number; rows: Contract[] }[] = [];
          for (const c of planContracts) {
            const last = goalGroups[goalGroups.length - 1];
            if (last && last.goalId === c.goal_id) {
              last.rows.push(c);
            } else {
              goalGroups.push({ goalId: c.goal_id, rows: [c] });
            }
          }

          return (
            <div key={planId} className="bg-white dark:bg-slate-800 rounded-xl border border-primary-600 dark:border-slate-700 shadow-md overflow-hidden">
              <div className="px-5 py-4 border-b-2 border-primary-600 dark:border-slate-700 flex items-center justify-between">
                <Badge variant="default" className="!text-sm !px-3 !py-1">{planName}</Badge>
                <span className="text-xs text-gray-500">{planContracts.length} contract(s)</span>
              </div>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                  <thead className="bg-gray-50 dark:bg-slate-900">
                    <tr>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[15%]">Goal Perspective</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[17%]">Organisation's Goal</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[12%]">Department</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[10%]">Financial Year</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[19%]">Departmental Performance Contract</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[11%]">KPI</th>
                      <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-[8%]">Created</th>
                      <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-[8%]">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-slate-700">
{goalGroups.map(({ goalId, rows }, groupIdx) => (
                      <Fragment key={goalId + '-' + groupIdx}>
                        {rows.map((row, idx) => {
                          const isFirstOfGoal = idx === 0;
                          const isLastRowOfGroup = idx === rows.length - 1;
                          const separator = isLastRowOfGroup && groupIdx < goalGroups.length - 1;
                          return (
                            <tr key={row.id}
                              className={`hover:bg-gray-50 dark:hover:bg-slate-700/50 ${separator ? 'border-b-[3px] border-b-black dark:border-b-white' : ''}`}>
                              <td className="px-4 py-3 align-top">
                                {isFirstOfGoal && (
                                  <div className="flex items-center gap-2">
                                    <Badge variant="success">{rows[0].goal_name ?? 'Unnamed Perspective'}</Badge>
                                    <span className="text-xs text-gray-400">{rows.length}</span>
                                  </div>
                                )}
                              </td>
                              <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">
                                {isFirstOfGoal ? (rows[0].target_name ?? '—') : ''}
                              </td>
                              <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">{row.department_name ?? '—'}</td>
                              <td className="px-4 py-3 align-top"><Badge variant="primary">{row.financial_year_name ?? '—'}</Badge></td>
                              <td className="px-4 py-3 align-top text-sm text-gray-900 dark:text-gray-100">{row.name}</td>
                              <td className="px-4 py-3 align-top text-sm text-gray-500 whitespace-pre-wrap">{row.kra || '—'}</td>
                              <td className="px-4 py-3 align-top text-sm text-gray-500">{fmt(row.created_at)}</td>
                              <td className="px-4 py-3 align-top">
                                <div className="flex items-center justify-center gap-1">
                                  <button className="p-1.5 rounded hover:bg-blue-50 text-blue-600" title="View workplans"
                                    onClick={() => openDetail(row.id)}>
                                    <Eye className="h-4 w-4" />
                                  </button>
                                  {canManage && (
                                    <>
                                      <button className="p-1.5 rounded hover:bg-blue-50 text-blue-600" title="Edit"
                                        onClick={() => setForm({ open: true, mode: 'edit', record: row })}>
                                        <Pencil className="h-4 w-4" />
                                      </button>
                                      <button className="p-1.5 rounded hover:bg-red-50 text-red-600" title="Delete"
                                        onClick={() => confirmDelete(row)}>
                                        <Trash2 className="h-4 w-4" />
                                      </button>
                                    </>
                                  )}
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                      </Fragment>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          );
        })
      )}
{/* Contract detail — workplan objectives */}
      {detail && (
        <Modal isOpen={true} onClose={() => setDetail(null)} size="xl" title={`Contract — ${detail.name}`}>
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
              <p><span className="font-medium">Strategic plan:</span> {detail.strategic_plan_name ?? 'N/A'}</p>
              <p><span className="font-medium">Goal perspective:</span> {detail.goal_name ?? 'N/A'}</p>
              <p><span className="font-medium">Organisation's goal:</span> {detail.target_name ?? '—'}</p>
              <p><span className="font-medium">Department:</span> {detail.department_name ?? '—'}</p>
              <p><span className="font-medium">Financial year:</span> {detail.financial_year_name ?? '—'}</p>
              <p><span className="font-medium">KPI (KRA):</span> {detail.kra || 'Not recorded'}</p>
            </div>
            <div className="border-t pt-3 dark:border-slate-700">
              <h4 className="font-semibold mb-2 text-gray-900 dark:text-gray-100">
                Workplan objectives ({detailWorkplans.length})
              </h4>
              {detailWorkplans.length === 0 ? (
                <p className="text-sm text-gray-500">No workplan objectives captured for this contract yet.</p>
              ) : (
                <ul className="space-y-2">
                  {detailWorkplans.map((w) => (
                    <li key={w.id} className="rounded-lg border border-gray-200 dark:border-slate-700 px-3 py-2 text-sm">
                      <p className="font-medium text-gray-900 dark:text-gray-100">{w.objective}</p>
                      <p className="text-gray-500">
                        KPI: {w.kpi || '—'} · Unit: {w.measure_unit || '—'}
                        {w.section_name ? ` · Section: ${w.section_name}` : ''}
                        {w.subsection_name ? ` · Subsection: ${w.subsection_name}` : ''}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </Modal>
      )}

      {/* Add / Edit contract */}
      {form.open && strategy && (
        <ContractFormModal
          mode={form.mode}
          record={form.mode === 'edit' ? form.record : undefined}
          plans={strategy.plans}
          goals={strategy.goals}
          targets={strategy.targets}
          departments={strategy.departments}
          financialYears={strategy.financial_years}
          onClose={() => setForm({ open: false })}
          onSubmit={(payload, id) => {
            const mode = form.mode;
            setForm({ open: false });
            runAction(
              () => (mode === 'add'
                ? apiClient.post('/performance-contracts', payload)
                : apiClient.put(`/performance-contracts/${id}`, payload)),
              `Performance contract ${mode === 'add' ? 'created' : 'updated'}.`,
            );
          }}
        />
      )}
    </div>
  );
}
// ========================================================================
// Add / Edit contract modal (cascading Goal Perspective → Organisation's Goal)
// ========================================================================

interface ContractFormModalProps {
  mode: 'add' | 'edit';
  record?: Contract;
  plans: { id: number; name: string }[];
  goals: { id: number; strategic_plan_id: number; name: string }[];
  targets: {
    id: number; goal_id: number; strategic_plan_id: number;
    department_id: number | null; name: string; department_name: string | null;
  }[];
  departments: { id: number; name: string }[];
  financialYears: { id: number; year_name: string }[];
  onClose: () => void;
  onSubmit: (payload: Record<string, unknown>, id?: number) => void;
}

function ContractFormModal({
  mode, record, plans, goals, targets, departments, financialYears, onClose, onSubmit,
}: ContractFormModalProps) {
  const [planId, setPlanId] = useState<number | ''>(record?.strategic_plan_id ?? '');
  const [goalId, setGoalId] = useState<number | ''>(record?.goal_id ?? '');
  const [targetId, setTargetId] = useState<number | ''>(record?.target_id ?? '');
  const [deptId, setDeptId] = useState<number | ''>(record?.department_id ?? '');
  const [fyId, setFyId] = useState<number | ''>(record?.financial_year_id ?? '');
  const [name, setName] = useState(record?.name ?? '');
  const [kra, setKra] = useState(record?.kra ?? '');
  const [err, setErr] = useState('');

  // Perspectives available for the chosen plan; org goals for the perspective.
  const perspectives = planId === ''
    ? goals
    : goals.filter((g) => g.strategic_plan_id === Number(planId));
  const orgGoals = goalId === ''
    ? []
    : targets.filter((t) => t.goal_id === Number(goalId));

  const changePlan = (value: number | '') => {
    setPlanId(value);
    // Reset dependent selections when the plan changes.
    setGoalId('');
    setTargetId('');
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!planId) return setErr('Select a strategic plan.');
    if (!goalId) return setErr('Select a goal perspective.');
    if (!targetId) return setErr("Select an organisation's goal.");
    if (!deptId) return setErr('Select a department.');
    if (!fyId) return setErr('Select a financial year.');
    if (!name.trim()) return setErr('The departmental objective is required.');
    if (!kra.trim()) return setErr('The KPI is required.');
    setErr('');
    onSubmit({
      strategic_plan_id: Number(planId),
      goal_id: Number(goalId),
      target_id: Number(targetId),
      department_id: Number(deptId),
      financial_year_id: Number(fyId),
      name: name.trim(),
      kra: kra.trim(),
    }, record?.id);
  };

  return (
    <Modal isOpen={true} onClose={onClose} size="xl"
      title={mode === 'add' ? 'Add Performance Contract' : 'Edit Performance Contract'}>
      <form onSubmit={submit} className="space-y-4">
<div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Strategic Plan *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={planId} onChange={(e) => changePlan(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">Select Strategic Plan</option>
              {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={deptId} onChange={(e) => setDeptId(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">Select Department</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Financial Year *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={fyId} onChange={(e) => setFyId(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">Select Financial Year</option>
              {financialYears.map((f) => <option key={f.id} value={f.id}>{f.year_name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Goal Perspective *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={goalId}
              disabled={planId === ''}
              onChange={(e) => { setGoalId(e.target.value ? Number(e.target.value) : ''); setTargetId(''); }}
              required>
              <option value="">{planId === '' ? 'Select a strategic plan first' : 'Select Goal Perspective'}</option>
              {perspectives.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </div>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organisation's Goal *</label>
          <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={targetId}
            disabled={goalId === ''}
            onChange={(e) => setTargetId(e.target.value ? Number(e.target.value) : '')}
            required>
            <option value="">{goalId === '' ? 'Select a goal perspective first' : "Select Organisation's Goal"}</option>
            {orgGoals.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}{t.department_name ? ` [${t.department_name}]` : ''}
              </option>
            ))}
          </select>
          <p className="text-xs text-gray-400 mt-1">
            The goal perspective is recorded automatically from the selected organisational goal.
          </p>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Departmental Objective *</label>
          <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={name} onChange={(e) => setName(e.target.value)}
            placeholder="Performance contract name" required />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">KPI (KRA) *</label>
          <textarea rows={3} className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={kra} onChange={(e) => setKra(e.target.value)}
            placeholder="Key result area / indicator for this contract" required />
        </div>

        {err && <p className="text-sm text-red-600">{err}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit">{mode === 'add' ? 'Add' : 'Save changes'}</Button>
        </div>
      </form>
    </Modal>
  );
}
import { useState, useEffect, useCallback } from 'react';
import { Link, useLocation } from 'react-router-dom';
import apiClient from '../../api/client';
import Card from '../../components/ui/Card';
import Table from '../../components/ui/Table';
import Badge from '../../components/ui/Badge';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import {
  Plus, Target, CheckCircle, Pencil, Trash2, CalendarDays,
  AlertTriangle, RefreshCw
} from 'lucide-react';

interface Plan {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  image: string | null;
  goal_count: number;
  target_count: number;
  contract_count: number;
  workplan_count: number;
}

interface Goal {
  id: number;
  strategic_plan_id: number;
  name: string;
  strategic_plan_name: string | null;
  target_count: number;
}

interface StrategicTarget {
  id: number;
  goal_id: number;
  strategic_plan_id: number;
  department_id: number | null;
  name: string;
  description: string | null;
  baseline_value: string | null;
  target_value: string | null;
  unit: string | null;
  goal_name: string | null;
  strategic_plan_name: string | null;
  department_name: string | null;
  contract_count: number;
}

interface Overview {
  active_financial_year: { id: number; year_name: string; start_date: string; end_date: string } | null;
  plans: Plan[];
  goals: Goal[];
  targets: StrategicTarget[];
  departments: { id: number; name: string }[];
  financial_years: { id: number; year_name: string; start_date: string; end_date: string; is_active: number }[];
  can_manage: boolean;
}

type ModalState =
  | { type: 'none' }
  | { type: 'plan'; mode: 'add' | 'edit'; record?: Plan }
  | { type: 'goal'; mode: 'add' | 'edit'; record?: Goal }
  | { type: 'target'; mode: 'add' | 'edit'; record?: StrategicTarget };

const planStatus = (start: string, end: string): { label: string; variant: 'success' | 'primary' | 'default' } => {
  const year = new Date().getFullYear();
  const s = new Date(start).getFullYear();
  const e = new Date(end).getFullYear();
  if (s <= year && e >= year) return { label: 'Current', variant: 'success' };
  if (year < s) return { label: 'Future', variant: 'primary' };
  return { label: 'Past', variant: 'default' };
};

const fmt = (d: string | null) =>
  d ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : 'N/A';

export default function StrategicPlan() {
  const location = useLocation();
  const [data, setData] = useState<Overview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [modal, setModal] = useState<ModalState>({ type: 'none' });

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await apiClient.get('/strategic-plans');
      setData(res.data?.data ?? null);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load the strategic plan.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(); }, [load]);

  const run = async (fn: () => Promise<any>, successMsg: string) => {
    setError(''); setNotice('');
    try {
      await fn();
      setNotice(successMsg);
      setModal({ type: 'none' });
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || 'The request could not be completed.');
    }
  };

  const confirmDelete = async (label: string, fn: () => Promise<any>) => {
    if (!window.confirm(`Delete ${label}? Records that still have dependent data are protected.`)) return;
    await run(fn, `${label} deleted.`);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600"></div>
      </div>
    );
  }

  if (!data) {
    return (
      <Card title="Strategic Plan">
        <p className="text-gray-500">{error || 'No strategic plan data available.'}</p>
      </Card>
    );
  }

  const canManage = data.can_manage;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">Strategic Plan</h1>
          <p className="text-gray-500">Organisation strategy, goals and organisational goals</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={load}><RefreshCw className="h-4 w-4 mr-2" />Refresh</Button>
          {canManage && (
            <Button onClick={() => setModal({ type: 'plan', mode: 'add' })}>
              <Plus className="h-4 w-4 mr-2" />New Strategic Plan
            </Button>
          )}
        </div>
      </div>
{/* Strategy sub-navigation */}
      <div className="flex space-x-1 border-b overflow-x-auto">
        {[
          { to: '/strategy/strategic-plan', label: 'Strategic Plan' },
          { to: '/strategy/performance-contracts', label: 'Performance Contracts' },
          { to: '/strategy/workplans', label: 'Workplans' },
          { to: '/strategy/reports', label: 'Performance Reports' },
        ].map((tab) => (
          <Link
            key={tab.to}
            to={tab.to}
            className={`px-4 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${
              location.pathname === tab.to
                ? 'border-primary-600 text-primary-600'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            {tab.label}
          </Link>
        ))}
      </div>

      {/* Alerts */}
      {(error || notice) && (
        <div className={`rounded-lg px-4 py-3 text-sm flex items-start gap-2 ${
          error ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
                : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'}`}>
          {error ? <AlertTriangle className="h-4 w-4 mt-0.5" /> : <CheckCircle className="h-4 w-4 mt-0.5" />}
          <span className="whitespace-pre-wrap">{error || notice}</span>
        </div>
      )}

      {/* Active financial year banner */}
      <div className="rounded-xl bg-primary-50 dark:bg-slate-800 border border-primary-200 dark:border-slate-700 px-5 py-4 flex items-start gap-3">
        <CalendarDays className="h-5 w-5 text-primary-600 mt-0.5" />
        <div>
          <p className="text-sm font-semibold text-gray-900 dark:text-gray-100">
            Active financial year: {data.active_financial_year?.year_name ?? 'Not configured'}
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {data.active_financial_year
              ? `${fmt(data.active_financial_year.start_date)} – ${fmt(data.active_financial_year.end_date)} · Financial years run 1 July – 30 June`
              : 'Set an active financial year under HR Admin → Financial Year.'}
          </p>
        </div>
      </div>

      {/* Plans */}
      <Card title={`Strategic Plans (${data.plans.length})`} subtitle="Historic plans are retained — they are never removed automatically.">
        {data.plans.length === 0 ? (
          <div className="text-center py-10">
            <Target className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">No strategic plans found</p>
            {canManage && (
              <Button className="mt-3" onClick={() => setModal({ type: 'plan', mode: 'add' })}>
                <Plus className="h-4 w-4 mr-2" />Add the first strategic plan
              </Button>
            )}
          </div>
        ) : (
          <Table
            columns={[
              { key: 'name', label: 'Plan' },
              { key: 'period', label: 'Period', render: (_v, row: Plan) => `${fmt(row.start_date)} → ${fmt(row.end_date)}` },
              {
                key: 'status',
                label: 'Status',
                render: (_v, row: Plan) => {
                  const st = planStatus(row.start_date, row.end_date);
                  return <Badge variant={st.variant}>{st.label}</Badge>;
                },
              },
              { key: 'goal_count', label: 'Goals' },
              { key: 'target_count', label: 'Org. Goals' },
              { key: 'contract_count', label: 'Contracts' },
              { key: 'workplan_count', label: 'Workplans' },
              ...(canManage ? [{
                key: 'actions',
                label: 'Actions',
                render: (_v: any, row: Plan) => (
                  <div className="flex items-center gap-2">
                    <button className="p-1.5 rounded hover:bg-blue-50 text-blue-600"
                      onClick={() => setModal({ type: 'plan', mode: 'edit', record: row })} title="Edit">
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button className="p-1.5 rounded hover:bg-red-50 text-red-600"
                      onClick={() => confirmDelete(`"${row.name}"`, () =>
                        apiClient.delete(`/strategic-plans/${row.id}`))} title="Delete">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                ),
              }] : []),
            ]}
            data={data.plans}
          />
        )}
      </Card>
{/* Goals (strategic objectives / perspectives) */}
      <Card
        title={`Goals (${data.goals.length})`}
        subtitle="Strategic objectives that organise the organisational goals."
      >
        <div className="mb-4">
          {canManage && data.plans.length > 0 && (
            <Button variant="outline" onClick={() => setModal({ type: 'goal', mode: 'add' })}>
              <Plus className="h-4 w-4 mr-2" />Add Goal
            </Button>
          )}
        </div>
        {data.goals.length === 0 ? (
          <div className="text-center py-10">
            <Target className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">No goals found</p>
            <p className="text-sm text-gray-400">
              {data.plans.length === 0 ? 'Create a strategic plan first.' : 'Add a goal to start structuring the strategy.'}
            </p>
          </div>
        ) : (
          <Table
            columns={[
              { key: 'name', label: 'Goal / Perspective' },
              { key: 'strategic_plan_name', label: 'Strategic Plan', render: (v) => v ?? 'N/A' },
              { key: 'target_count', label: 'Organisational Goals' },
              ...(canManage ? [{
                key: 'actions',
                label: 'Actions',
                render: (_v: any, row: Goal) => (
                  <div className="flex items-center gap-2">
                    <button className="p-1.5 rounded hover:bg-blue-50 text-blue-600"
                      onClick={() => setModal({ type: 'goal', mode: 'edit', record: row })} title="Edit">
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button className="p-1.5 rounded hover:bg-red-50 text-red-600"
                      onClick={() => confirmDelete(`"${row.name}"`, () =>
                        apiClient.delete(`/goals/${row.id}`))} title="Delete">
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                ),
              }] : []),
            ]}
            data={data.goals}
          />
        )}
      </Card>
{/* Organisational goals (strategic targets) */}
      <Card
        title={`Organisational Goals (${data.targets.length})`}
        subtitle="Strategic targets that translate each goal into measurable objectives, owned by a department or C-SUITE."
      >
        <div className="mb-4">
          {canManage && data.goals.length > 0 && (
            <Button variant="outline" onClick={() => setModal({ type: 'target', mode: 'add' })}>
              <Plus className="h-4 w-4 mr-2" />Add Organisational Goal
            </Button>
          )}
        </div>
        {data.targets.length === 0 ? (
          <div className="text-center py-10">
            <Target className="h-12 w-12 text-gray-300 mx-auto mb-3" />
            <p className="text-gray-500 font-medium">No organisational goals found</p>
            <p className="text-sm text-gray-400">
              {data.goals.length === 0 ? 'Add goals first, then attach organisational goals.' : 'Attach an organisational goal to one of the existing goals.'}
            </p>
          </div>
        ) : (
          <Table
            columns={[
              { key: 'goal_name', label: 'Goal / Perspective' },
              { key: 'name', label: 'Organisational Goal' },
              {
                key: 'department_name',
                label: 'Department',
                render: (v) => (v
                  ? <Badge variant="primary">{v}</Badge>
                  : <Badge variant="default">C-SUITE</Badge>),
              },
              {
                key: 'measure',
                label: 'Baseline → Target',
                render: (_v, row: StrategicTarget) =>
                  row.target_value || row.baseline_value
                    ? `${row.baseline_value || '—'} → ${row.target_value || '—'}${row.unit ? ` ${row.unit}` : ''}`
                    : 'Not set',
              },
              { key: 'contract_count', label: 'Contracts' },
              ...(canManage ? [{
                key: 'actions',
                label: 'Actions',
                render: (_v: any, row: StrategicTarget) => (
                  <div className="flex items-center gap-2">
                    <button className="p-1.5 rounded hover:bg-blue-50 text-blue-600"
                      onClick={() => setModal({ type: 'target', mode: 'edit', record: row })} title="Edit">
                      <Pencil className="h-4 w-4" />
                    </button>
                    <button
                      disabled={Number(row.contract_count) > 0}
                      title={Number(row.contract_count) > 0
                        ? `Referenced by ${row.contract_count} performance contract(s)`
                        : 'Delete'}
                      className={`p-1.5 rounded ${Number(row.contract_count) > 0
                        ? 'text-gray-300 cursor-not-allowed'
                        : 'text-red-600 hover:bg-red-50'}`}
                      onClick={() => confirmDelete(`"${row.name}"`, () =>
                        apiClient.delete(`/targets/${row.id}`))}>
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>
                ),
              }] : []),
            ]}
            data={data.targets}
          />
        )}
      </Card>

      {/* ---------------- Modals ---------------- */}
      {(modal.type === 'plan') && (
        <PlanModal
          mode={modal.mode}
          record={modal.record}
          onClose={() => setModal({ type: 'none' })}
          onSave={(payload, id) => run(
            () => (modal.mode === 'add'
              ? apiClient.post('/strategic-plans', payload)
              : apiClient.put(`/strategic-plans/${id}`, payload)),
            `Strategic plan ${modal.mode === 'add' ? 'created' : 'updated'}.`
          )}
        />
      )}

      {(modal.type === 'goal') && (
        <GoalModal
          mode={modal.mode}
          record={modal.record}
          plans={data.plans}
          defaultPlanId={data.plans[0]?.id ?? ''}
          onClose={() => setModal({ type: 'none' })}
          onSave={(payload) => run(
            () => (modal.mode === 'add'
              ? apiClient.post(`/strategic-plans/${payload.strategic_plan_id}/goals`, payload)
              : apiClient.put(`/goals/${modal.record?.id}`, payload)),
            `Goal ${modal.mode === 'add' ? 'added' : 'updated'}.`
          )}
        />
      )}

      {(modal.type === 'target') && (
        <TargetModal
          mode={modal.mode}
          record={modal.record}
          plans={data.plans}
          goals={data.goals}
          departments={data.departments}
          onClose={() => setModal({ type: 'none' })}
          onSave={(payload) => run(
            () => (modal.mode === 'add'
              ? apiClient.post(`/strategic-plans/${payload.strategic_plan_id}/targets`, payload)
              : apiClient.put(`/targets/${modal.record?.id}`, payload)),
            `Organisational goal ${modal.mode === 'add' ? 'added' : 'updated'}.`
          )}
        />
      )}
    </div>
  );
}
// ========================================================================
// Modal components
// ========================================================================

interface PlanModalProps {
  mode: 'add' | 'edit';
  record?: Plan;
  onClose: () => void;
  onSave: (payload: { name: string; start_date: string; end_date: string }, id?: number) => void;
}

function PlanModal({ mode, record, onClose, onSave }: PlanModalProps) {
  const [name, setName] = useState(record?.name ?? '');
  const [start, setStart] = useState(record?.start_date ?? '');
  const [end, setEnd] = useState(record?.end_date ?? '');
  const [err, setErr] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) return setErr('Plan name is required.');
    if (!start || !end) return setErr('Start and end dates are required.');
    if (end < start) return setErr('End date must be on or after the start date.');
    setErr('');
    onSave({ name: name.trim(), start_date: start, end_date: end }, record?.id);
  };

  return (
    <Modal isOpen={true} onClose={onClose} title={mode === 'add' ? 'Add Strategic Plan' : 'Edit Strategic Plan'}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Plan Name *</label>
          <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Strategic Plan 2025-2030" required />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Start Date *</label>
            <input type="date" className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={start} onChange={(e) => setStart(e.target.value)} required />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">End Date *</label>
            <input type="date" className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={end} onChange={(e) => setEnd(e.target.value)} required />
          </div>
        </div>
        {err && <p className="text-sm text-red-600">{err}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit">{mode === 'add' ? 'Create' : 'Save changes'}</Button>
        </div>
      </form>
    </Modal>
  );
}
interface GoalModalProps {
  mode: 'add' | 'edit';
  record?: Goal;
  plans: Plan[];
  defaultPlanId: number | '';
  onClose: () => void;
  onSave: (payload: { strategic_plan_id: number; name: string }) => void;
}

function GoalModal({ mode, record, plans, defaultPlanId, onClose, onSave }: GoalModalProps) {
  const [planId, setPlanId] = useState<number | ''>(record?.strategic_plan_id ?? defaultPlanId);
  const [name, setName] = useState(record?.name ?? '');
  const [err, setErr] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!planId) return setErr('Select the strategic plan this goal belongs to.');
    if (!name.trim()) return setErr('Goal name is required.');
    setErr('');
    onSave({ strategic_plan_id: Number(planId), name: name.trim() });
  };

  return (
    <Modal isOpen={true} onClose={onClose} title={mode === 'add' ? 'Add Goal' : 'Edit Goal'}>
      <form onSubmit={submit} className="space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Strategic Plan *</label>
          <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={planId} onChange={(e) => setPlanId(e.target.value ? Number(e.target.value) : '')} required>
            <option value="">Select a strategic plan…</option>
            {plans.map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Goal Name *</label>
          <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={name} onChange={(e) => setName(e.target.value)}
            placeholder="e.g. Financial Perspective" required />
        </div>
        {err && <p className="text-sm text-red-600">{err}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit">{mode === 'add' ? 'Add goal' : 'Save changes'}</Button>
        </div>
      </form>
    </Modal>
  );
}
interface TargetModalProps {
  mode: 'add' | 'edit';
  record?: StrategicTarget;
  plans: Plan[];
  goals: Goal[];
  departments: { id: number; name: string }[];
  onClose: () => void;
  onSave: (payload: Record<string, unknown>) => void;
}

function TargetModal({ mode, record, plans, goals, departments, onClose, onSave }: TargetModalProps) {
  const [goalId, setGoalId] = useState<number | ''>(record?.goal_id ?? '');
  const [planId, setPlanId] = useState<number | ''>(record?.strategic_plan_id ?? plans[0]?.id ?? '');
  const [departmentId, setDepartmentId] = useState<number | ''>(record?.department_id ?? '');
  const [name, setName] = useState(record?.name ?? '');
  const [description, setDescription] = useState(record?.description ?? '');
  const [baseline, setBaseline] = useState(record?.baseline_value ?? '');
  const [targetValue, setTargetValue] = useState(record?.target_value ?? '');
  const [unit, setUnit] = useState(record?.unit ?? '');
  const [err, setErr] = useState('');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!goalId) return setErr('Select the goal / perspective.');
    if (!planId) return setErr('Select the strategic plan.');
    if (!name.trim()) return setErr('Organisational goal is required.');
    setErr('');
    onSave({
      goal_id: Number(goalId),
      strategic_plan_id: Number(planId),
      department_id: departmentId === '' ? null : Number(departmentId),
      name: name.trim(),
      description: description.trim(),
      baseline_value: baseline.trim(),
      target_value: targetValue.trim(),
      unit: unit.trim(),
    });
  };

  return (
    <Modal isOpen={true} onClose={onClose} size="xl"
      title={mode === 'add' ? 'Add Organisational Goal' : 'Edit Organisational Goal'}>
      <form onSubmit={submit} className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Goal / Perspective *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={goalId} onChange={(e) => setGoalId(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">Select…</option>
              {goals.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Strategic Plan *</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={planId} onChange={(e) => setPlanId(e.target.value ? Number(e.target.value) : '')} required>
              <option value="">Select…</option>
              {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
            <select className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={departmentId} onChange={(e) => setDepartmentId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">— C-SUITE —</option>
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Organisational Goal *</label>
          <textarea rows={3} className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
            value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Baseline</label>
            <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={baseline} onChange={(e) => setBaseline(e.target.value)} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Target</label>
            <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={targetValue} onChange={(e) => setTargetValue(e.target.value)} />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Unit</label>
            <input className="w-full rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 text-sm"
              value={unit} onChange={(e) => setUnit(e.target.value)} placeholder="e.g. %, KES m" />
          </div>
        </div>
        {err && <p className="text-sm text-red-600">{err}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit">{mode === 'add' ? 'Add organisational goal' : 'Save changes'}</Button>
        </div>
      </form>
    </Modal>
  );
}
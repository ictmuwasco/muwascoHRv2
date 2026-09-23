import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Plus, RefreshCw, Search, Pencil, Trash2 } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import {
  kpiService,
  type Kpi,
  type KpiEmployee,
  type KpiActivity,
  type KpiListPayload,
} from '../../api/services/kpiService';

/**
 * Sectional Objectives / KPIs — the scoring units consumed by the appraisal
 * workflow (emulates the legacy kpi.php on the modern API).
 *
 * Scoping is decided entirely by the backend (hr_manager is the apex role and
 * sees everything; heads are scoped to their own unit). This page renders
 * whatever the API allows the caller to see and do.
 */

const TABS = [
  { to: '/strategy/strategic-plan', label: 'Strategic Plan' },
  { to: '/strategy/performance-contracts', label: 'Performance Contracts' },
  { to: '/strategy/workplans', label: 'Workplans' },
  { to: '/strategy/kpis', label: 'KPIs' },
  { to: '/strategy/reports', label: 'Performance Reports' },
];

const parseIds = (raw: string | null | undefined): number[] =>
  (raw || '')
    .split(',')
    .map((s) => parseInt(s.trim(), 10))
    .filter((n) => !Number.isNaN(n) && n > 0);

/** Unit-based activity <-> employee matching (department -> section -> subsection). */
function activityMatchesEmployee(act: KpiActivity, emp: KpiEmployee): boolean {
  if (act.department_id && emp.department_id && act.department_id !== emp.department_id)
    return false;
  if (act.section_id && act.section_id !== (emp.section_id || 0)) return false;
  if (act.subsection_id && act.subsection_id !== (emp.subsection_id || 0)) return false;
  return true;
}

type ModalState =
  { open: false } | { open: true; mode: 'add' } | { open: true; mode: 'edit'; record: Kpi };

const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';
const labelCls =
  'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';

export default function Kpis() {
  const location = useLocation();
  const { user } = useAuth();
  const [data, setData] = useState<KpiListPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState<ModalState>({ open: false });
  const [saving, setSaving] = useState(false);

  // Form state
  const [formName, setFormName] = useState('');
  const [formMaxScore, setFormMaxScore] = useState(5);
  const [formEmpIds, setFormEmpIds] = useState<number[]>([]);
  const [formActIds, setFormActIds] = useState<number[]>([]);
  const [formActive, setFormActive] = useState(true);
  const [formRecurrent, setFormRecurrent] = useState(false);

  const userId = Number((user as any)?.id ?? (user as any)?.user_id ?? 0);

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const res = await kpiService.list();
      setData(res.data ?? null);
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to load KPIs.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const employees = data?.employees ?? [];
  const activities = data?.activities ?? [];
  const cycles = data?.cycles ?? [];
  const objectives = data?.objectives ?? [];
  const canManage = !!data?.can_manage;
  const role = data?.scope.role || '';

  const empName = useMemo(() => {
    const m = new Map<number, string>();
    employees.forEach((e) => m.set(e.id, `${e.first_name} ${e.last_name}`.trim()));
    return m;
  }, [employees]);

  const activityLabel = useCallback(
    (id: number) => {
      const act = activities.find((a) => a.id === id);
      if (!act) return null;
      let label = act.objective;
      if (act.parent_objective) label += ` -> ${act.parent_objective}`;
      return label;
    },
    [activities],
  );

  const cycleNames = useMemo(() => {
    const m = new Map<number, string>();
    cycles.forEach((c) => m.set(c.id, c.name));
    return m;
  }, [cycles]);

  const canEditRow = (k: Kpi) => canManage || (!!k.created_by && k.created_by === userId);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return objectives;
    return objectives.filter((k) =>
      parseIds(k.assigned_to_employee_ids).some((id) =>
        (empName.get(id) || '').toLowerCase().includes(q),
      ),
    );
  }, [objectives, search, empName]);

  const totalMarks = filtered.reduce((s, k) => s + Number(k.max_score || 0), 0);

  // Activities shown in the modal: those matching ANY selected employee, plus
  // already-linked ones (kept visible in edit mode so they can be unlinked).
  const modalActivities = useMemo(() => {
    if (!modal.open) return [];
    const selected = formEmpIds
      .map((id) => employees.find((e) => e.id === id))
      .filter((e): e is KpiEmployee => !!e);
    if (selected.length === 0) return [];
    return activities.filter(
      (a) => formActIds.includes(a.id) || selected.some((emp) => activityMatchesEmployee(a, emp)),
    );
  }, [modal.open, formEmpIds, formActIds, employees, activities]);

  const openAdd = () => {
    setFormName('');
    setFormMaxScore(5);
    setFormEmpIds([]);
    setFormActIds([]);
    setFormActive(true);
    setFormRecurrent(false);
    setError('');
    setModal({ open: true, mode: 'add' });
  };

  const openEdit = (k: Kpi) => {
    setFormName(k.name);
    setFormMaxScore(k.max_score);
    setFormEmpIds(parseIds(k.assigned_to_employee_ids));
    setFormActIds(parseIds(k.activity_ids));
    setFormActive(!!k.is_active);
    setFormRecurrent(!!k.is_recurrent);
    setError('');
    setModal({ open: true, mode: 'edit', record: k });
  };

  const toggleEmp = (id: number) =>
    setFormEmpIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  const toggleAct = (id: number) =>
    setFormActIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));

  const save = async () => {
    if (!formName.trim()) {
      setError('KPI name is required.');
      return;
    }
    if (formEmpIds.length === 0) {
      setError('Select at least one employee.');
      return;
    }
    if (formActIds.length === 0) {
      setError('Select at least one workplan activity.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const payload = {
        name: formName.trim(),
        max_score: formMaxScore,
        activity_ids: formActIds,
        assigned_to_employee_ids: formEmpIds,
        is_active: formActive,
        is_recurrent: formRecurrent,
      };
      if (modal.open && modal.mode === 'edit') {
        await kpiService.update(modal.record.id, payload);
      } else {
        await kpiService.create(payload);
      }
      setModal({ open: false });
      setNotice('KPI saved successfully.');
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to save the KPI.');
    } finally {
      setSaving(false);
    }
  };

  const remove = async (k: Kpi) => {
    if (!window.confirm(`Deactivate "${k.name}"? Scoring history is preserved.`)) return;
    try {
      await kpiService.remove(k.id);
      setNotice('KPI deactivated.');
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to deactivate the KPI.');
    }
  };

  const toggleField = async (k: Kpi, field: 'is_active' | 'is_recurrent', value: boolean) => {
    try {
      await kpiService.update(k.id, { [field]: value } as any);
      await load();
    } catch (err: any) {
      setError(err.response?.data?.message || 'Failed to update the KPI.');
    }
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600" />
      </div>
    );
  }

  const activeCount = objectives.filter((k) => k.is_active).length;
  const recurrentCount = objectives.filter((k) => k.is_recurrent).length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">
            Key Performance Indicators
          </h1>
          <p className="text-gray-500 dark:text-gray-400">
            Create and manage KPIs linked to workplan activities — the scoring units for appraisals.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={load}>
            <RefreshCw className="h-4 w-4 mr-2" />
            Refresh
          </Button>
          {canManage && (
            <Button onClick={openAdd}>
              <Plus className="h-4 w-4 mr-2" />
              Add KPI
            </Button>
          )}
        </div>
      </div>

      {/* Strategy & Performance module tabs */}
      <div className="flex space-x-1 border-b overflow-x-auto">
        {TABS.map((tab) => (
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

      {(error || notice) && (
        <div
          className={`rounded-lg px-4 py-3 text-sm flex items-start gap-2 ${
            error
              ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300'
              : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'
          }`}
        >
          {error ? '⚠' : '✓'} <span className="flex-1">{error || notice}</span>
          <button onClick={() => (error ? setError('') : setNotice(''))} className="font-bold">
            ×
          </button>
        </div>
      )}

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        {[
          { label: 'Total KPIs', value: objectives.length },
          { label: 'Active', value: activeCount },
          { label: 'Recurrent', value: recurrentCount },
          { label: 'Employees in scope', value: employees.length },
        ].map((s) => (
          <div
            key={s.label}
            className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl p-4 text-center"
          >
            <div className="text-2xl font-bold text-primary-600 dark:text-primary-400">
              {s.value}
            </div>
            <div className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
              {s.label}
            </div>
          </div>
        ))}
      </div>

      {/* Search + table */}
      <div className="bg-white dark:bg-slate-800 border border-gray-200 dark:border-slate-700 rounded-xl overflow-hidden">
        <div className="p-4 border-b border-gray-200 dark:border-slate-700 flex items-center gap-2">
          <div className="relative flex-1 max-w-sm">
            <Search className="absolute left-3 top-2.5 h-4 w-4 text-gray-400" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by assigned employee..."
              className="w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 pl-9 pr-3 py-2 text-sm text-gray-700 dark:text-gray-200"
            />
          </div>
          {search.trim() !== '' && (
            <span className="text-sm text-gray-500 dark:text-gray-400">
              Total Marks:{' '}
              <strong className="text-gray-900 dark:text-gray-100">{totalMarks}</strong>
            </span>
          )}
        </div>

        {filtered.length === 0 ? (
          <div className="p-10 text-center text-gray-500 dark:text-gray-400">
            <p className="text-3xl mb-2">📊</p>
            <h3 className="font-semibold text-gray-900 dark:text-gray-100">No KPIs Found</h3>
            <p className="text-sm mt-1">
              {canManage
                ? 'Create your first KPI by clicking "Add KPI" and linking it to workplan activities.'
                : role === 'officer' || role === 'employee'
                  ? 'No KPIs have been assigned to you yet.'
                  : 'No KPIs have been created for your unit yet.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
              <thead className="bg-gray-50 dark:bg-slate-900">
                <tr>
                  {[
                    'KPI Name',
                    'Linked Objectives',
                    'Department',
                    'Assigned Employees',
                    'Max Score',
                    'Active',
                    'Recurrent',
                    'Actions',
                  ].map((h) => (
                    <th
                      key={h}
                      className="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider whitespace-nowrap"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                {filtered.map((k) => {
                  const actIds = parseIds(k.activity_ids);
                  const empIds = parseIds(k.assigned_to_employee_ids);
                  const empNames = empIds.map((id) => empName.get(id) || `#${id}`).join(', ');
                  const edit = canEditRow(k);
                  return (
                    <tr
                      key={k.id}
                      className="hover:bg-gray-50 dark:hover:bg-slate-700/50 align-top"
                    >
                      <td className="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {k.name}
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-xs">
                        <div className="space-y-1">
                          {actIds.length === 0 && <span className="text-gray-400">—</span>}
                          {actIds.map((id) => {
                            const act = activities.find((a) => a.id === id);
                            return (
                              <div key={id} className="flex flex-wrap items-center gap-1">
                                <span>{activityLabel(id) || `#${id}`}</span>
                                {act &&
                                  parseIds(act.cycle_ids).map((cid) => (
                                    <span
                                      key={cid}
                                      className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300"
                                    >
                                      {cycleNames.get(cid) || `Q${cid}`}
                                    </span>
                                  ))}
                              </div>
                            );
                          })}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                        {k.department_name || '—'}
                      </td>
                      <td
                        className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300 max-w-[220px]"
                        title={empNames}
                      >
                        {empNames || '—'}
                      </td>
                      <td className="px-4 py-3 text-sm text-center font-bold text-gray-900 dark:text-gray-100">
                        {k.max_score}
                      </td>
                      <td className="px-4 py-3 text-center">
                        {edit ? (
                          <label className="relative inline-flex items-center cursor-pointer">
                            <input
                              type="checkbox"
                              checked={!!k.is_active}
                              onChange={(e) => toggleField(k, 'is_active', e.target.checked)}
                              className="sr-only peer"
                            />
                            <div className="w-9 h-5 bg-gray-300 dark:bg-slate-600 peer-checked:bg-green-500 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-4" />
                          </label>
                        ) : (
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                              k.is_active
                                ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                : 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-gray-200'
                            }`}
                          >
                            {k.is_active ? 'Yes' : 'No'}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-center">
                        {edit ? (
                          <label className="relative inline-flex items-center cursor-pointer">
                            <input
                              type="checkbox"
                              checked={!!k.is_recurrent}
                              onChange={(e) => toggleField(k, 'is_recurrent', e.target.checked)}
                              className="sr-only peer"
                            />
                            <div className="w-9 h-5 bg-gray-300 dark:bg-slate-600 peer-checked:bg-primary-600 rounded-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:after:translate-x-4" />
                          </label>
                        ) : (
                          <span
                            className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${
                              k.is_recurrent
                                ? 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-200'
                                : 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-gray-200'
                            }`}
                          >
                            {k.is_recurrent ? 'Yes' : 'No'}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        {edit ? (
                          <div className="flex items-center gap-1">
                            <button
                              onClick={() => openEdit(k)}
                              className="p-1.5 rounded text-gray-500 hover:text-primary-600 hover:bg-gray-100 dark:hover:bg-slate-700"
                              title="Edit"
                            >
                              <Pencil className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => remove(k)}
                              className="p-1.5 rounded text-gray-500 hover:text-red-600 hover:bg-gray-100 dark:hover:bg-slate-700"
                              title="Delete"
                            >
                              <Trash2 className="h-4 w-4" />
                            </button>
                          </div>
                        ) : (
                          <span className="text-xs italic text-gray-400">View only</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Add / Edit modal */}
      <Modal
        isOpen={modal.open}
        onClose={() => setModal({ open: false })}
        title={modal.open && modal.mode === 'edit' ? 'Edit KPI' : 'Add New KPI'}
        size="2xl"
      >
        {modal.open && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className={labelCls}>KPI Name *</label>
                <input
                  className={inputCls}
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  placeholder="e.g. Customer Satisfaction Score"
                />
              </div>
              <div>
                <label className={labelCls}>Max Score *</label>
                <input
                  type="number"
                  min={1}
                  className={inputCls}
                  value={formMaxScore}
                  onChange={(e) => setFormMaxScore(Number(e.target.value) || 1)}
                />
              </div>
            </div>

            {error && <p className="text-sm text-red-600">{error}</p>}

            <div>
              <div className="flex items-center justify-between mb-1">
                <label className={labelCls + ' mb-0'}>Assign to Employees *</label>
                <div className="flex items-center gap-3 text-xs">
                  <button
                    type="button"
                    className="underline text-primary-600"
                    onClick={() => setFormEmpIds(employees.map((e) => e.id))}
                  >
                    Select All
                  </button>
                  <button
                    type="button"
                    className="underline text-gray-500"
                    onClick={() => setFormEmpIds([])}
                  >
                    Deselect All
                  </button>
                  <span className="text-gray-400">{formEmpIds.length} selected</span>
                </div>
              </div>
              <div className="max-h-56 overflow-y-auto border border-gray-200 dark:border-slate-600 rounded-md divide-y divide-gray-100 dark:divide-slate-700">
                {employees.length === 0 && (
                  <p className="p-3 text-sm text-gray-400">No employees available in your scope.</p>
                )}
                {employees.map((e) => (
                  <label
                    key={e.id}
                    className="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={formEmpIds.includes(e.id)}
                      onChange={() => toggleEmp(e.id)}
                      className="h-4 w-4 accent-primary-600"
                    />
                    <span className="text-sm font-medium text-gray-900 dark:text-gray-100 whitespace-nowrap">
                      {e.first_name} {e.last_name}
                    </span>
                    <span className="text-xs text-gray-400 hidden md:inline">
                      {(e.employee_type || '').replace(/_/g, ' ')}
                    </span>
                    <span className="text-xs text-gray-400 ml-auto hidden md:inline truncate max-w-[200px]">
                      {[e.department_name, e.section_name].filter(Boolean).join(' · ')}
                    </span>
                  </label>
                ))}
              </div>
            </div>

            <div>
              <div className="flex items-center justify-between mb-1">
                <label className={labelCls + ' mb-0'}>Linked Objectives *</label>
                <div className="flex items-center gap-3 text-xs">
                  <button
                    type="button"
                    className="underline text-primary-600"
                    onClick={() => setFormActIds(modalActivities.map((a) => a.id))}
                  >
                    Select All
                  </button>
                  <button
                    type="button"
                    className="underline text-gray-500"
                    onClick={() => setFormActIds([])}
                  >
                    Deselect All
                  </button>
                  <span className="text-gray-400">{formActIds.length} selected</span>
                </div>
              </div>
              <p className="text-xs text-gray-400 mb-1">
                Objectives shown are scoped to the selected employees' unit.
              </p>
              <div className="max-h-56 overflow-y-auto border border-gray-200 dark:border-slate-600 rounded-md divide-y divide-gray-100 dark:divide-slate-700">
                {formEmpIds.length === 0 && (
                  <p className="p-3 text-sm text-gray-400">Select at least one employee first.</p>
                )}
                {formEmpIds.length > 0 && modalActivities.length === 0 && (
                  <p className="p-3 text-sm text-gray-400">No matching workplan activities.</p>
                )}
                {modalActivities.map((a) => (
                  <label
                    key={a.id}
                    className="flex items-start gap-3 px-3 py-2 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer"
                  >
                    <input
                      type="checkbox"
                      checked={formActIds.includes(a.id)}
                      onChange={() => toggleAct(a.id)}
                      className="h-4 w-4 mt-0.5 accent-primary-600"
                    />
                    <span className="flex-1 min-w-0">
                      <span className="block text-sm text-gray-900 dark:text-gray-100">
                        {a.objective}
                      </span>
                      {a.parent_objective && (
                        <span className="block text-xs text-gray-400">
                          under: {a.parent_objective}
                        </span>
                      )}
                      <span className="flex flex-wrap gap-1 mt-1">
                        {(a.contract_name || a.department_name) && (
                          <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-gray-300">
                            {a.contract_name || a.department_name}
                          </span>
                        )}
                        {a.section_name && (
                          <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-gray-300">
                            {a.section_name}
                          </span>
                        )}
                        {parseIds(a.cycle_ids).map((cid) => (
                          <span
                            key={cid}
                            className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300"
                          >
                            {cycleNames.get(cid) || `Q${cid}`}
                          </span>
                        ))}
                      </span>
                    </span>
                  </label>
                ))}
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-6">
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input
                  type="checkbox"
                  checked={formActive}
                  onChange={(e) => setFormActive(e.target.checked)}
                  className="h-4 w-4 accent-primary-600"
                />
                Active — included in current appraisal cycles
              </label>
              <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input
                  type="checkbox"
                  checked={formRecurrent}
                  onChange={(e) => setFormRecurrent(e.target.checked)}
                  className="h-4 w-4 accent-primary-600"
                />
                Recurrent — auto-added to future cycles
              </label>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button variant="outline" onClick={() => setModal({ open: false })}>
                Cancel
              </Button>
              <Button onClick={save} disabled={saving}>
                {saving
                  ? 'Saving...'
                  : modal.open && modal.mode === 'edit'
                    ? 'Save Changes'
                    : 'Add KPI'}
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}

import { useCallback, useEffect, useMemo, useState } from 'react';
import { Plus, RefreshCw, Search, Pencil, Trash2 } from 'lucide-react';
import { useAuth } from '../../context/AuthContext';
import Button from '../../components/ui/Button';
import Modal from '../../components/ui/Modal';
import StrategyTabs from '../../components/strategy/StrategyTabs';
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

const parseIds = (raw: string | null | undefined): number[] =>
  (raw || '')
    .split(',')
    .map((s) => parseInt(s.trim(), 10))
    .filter((n) => !Number.isNaN(n) && n > 0);

/** Match an activity to an employee's assignable organizational unit. */
function activityMatchesEmployee(act: KpiActivity, emp: KpiEmployee): boolean {
  if (
    act.department_id &&
    emp.department_id &&
    Number(act.department_id) !== Number(emp.department_id)
  )
    return false;
  const employeeType = (emp.employee_type || '').toLowerCase();
  if (employeeType === 'section_head' || employeeType === 'section_manager') {
    if (!act.section_id || Number(act.section_id) !== Number(emp.section_id || 0)) return false;
    if (act.subsection_id) return false;
  }
  if (act.section_id && Number(act.section_id) !== Number(emp.section_id || 0)) return false;
  if (act.subsection_id && Number(act.subsection_id) !== Number(emp.subsection_id || 0))
    return false;
  return true;
}

type ModalState =
  | { open: false }
  | { open: true; mode: 'add' }
  | { open: true; mode: 'edit'; record: Kpi };

const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';
const labelCls =
  'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';

export default function Kpis() {
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
  const [formEmpIds, setFormEmpIds] = useState<number[]>([]);
  const [formActIds, setFormActIds] = useState<number[]>([]);
  const [formObjectiveMarks, setFormObjectiveMarks] = useState<Record<number, number>>({});
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
  const linkedActivities = data?.linked_activities ?? [];
  const cycles = data?.cycles ?? [];
  const objectives = data?.objectives ?? [];
  const canManage = !!data?.can_manage;
  const role = data?.scope.role || '';
  const employeeScopeHint =
    role === 'dept_head' || role === 'hr_manager'
      ? 'Department heads can assign KPIs to section heads only. Section heads manage KPI assignments for subsection heads and staff.'
      : role === 'section_head'
        ? 'Select direct employees in your section. Subsection heads assign KPIs to employees in their own subsection.'
        : role === 'sub_section_head'
          ? 'Select employees assigned to your subsection.'
          : '';

  const activityById = useMemo(() => {
    const byId = new Map<number, KpiActivity>();
    [...activities, ...linkedActivities].forEach((activity) =>
      byId.set(Number(activity.id), activity),
    );
    return byId;
  }, [activities, linkedActivities]);

  const empName = useMemo(() => {
    const m = new Map<number, string>();
    employees.forEach((e) => m.set(Number(e.id), `${e.first_name} ${e.last_name}`.trim()));
    return m;
  }, [employees]);

  const activityLabel = useCallback(
    (id: number) => {
      const act = activityById.get(id);
      if (!act) return null;
      let label = act.objective;
      if (act.parent_objective) label += ` -> ${act.parent_objective}`;
      return label;
    },
    [activityById],
  );

  const cycleNames = useMemo(() => {
    const m = new Map<number, string>();
    cycles.forEach((c) => m.set(Number(c.id), c.name));
    return m;
  }, [cycles]);

  const assignedEmployeeNames = useCallback(
    (k: Kpi): string[] => {
      const names = (k.assigned_employee_names ?? []).map((name) => name.trim()).filter(Boolean);
      if (names.length > 0) return names;

      // Compatibility fallback for an older API response. Never expose an
      // internal employee ID when a name is not available.
      return parseIds(k.assigned_to_employee_ids).map(
        (id) => empName.get(id) || 'Employee unavailable',
      );
    },
    [empName],
  );

  const canEditRow = (k: Kpi) => canManage || (!!k.created_by && Number(k.created_by) === userId);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return objectives;
    return objectives.filter((k) =>
      assignedEmployeeNames(k).some((name) => name.toLowerCase().includes(q)),
    );
  }, [objectives, search, assignedEmployeeNames]);

  const totalMarks = filtered.reduce((s, k) => s + Number(k.max_score || 0), 0);
  const formMaxScore = formActIds.reduce(
    (total, activityId) => total + (formObjectiveMarks[activityId] || 0),
    0,
  );

  const selectedEmployees = useMemo(
    () =>
      formEmpIds
        .map((id) => employees.find((e) => Number(e.id) === id))
        .filter((e): e is KpiEmployee => !!e),
    [formEmpIds, employees],
  );

  // Activities shown in the modal: those matching ANY selected employee, plus
  // already-linked ones (kept visible in edit mode so they can be unlinked).
  const modalActivities = useMemo(() => {
    if (!modal.open || selectedEmployees.length === 0) return [];
    const linkedActivityIds = new Set(formActIds);
    const activityMap = new Map<number, KpiActivity>();
    [...activities, ...linkedActivities.filter((activity) => linkedActivityIds.has(Number(activity.id)))].forEach(
      (activity) => activityMap.set(Number(activity.id), activity),
    );
    return Array.from(activityMap.values()).filter(
      (a) =>
        formActIds.includes(Number(a.id)) ||
        selectedEmployees.some((emp) => activityMatchesEmployee(a, emp)),
    );
  }, [modal.open, formActIds, selectedEmployees, activities, linkedActivities]);
  const activityScopeHint =
    role === 'dept_head' || role === 'hr_manager'
      ? 'Only activities assigned to the selected section(s) are shown. Department-wide activities must be cascaded to a section before they can be linked to a section head KPI.'
      : "Objectives shown are scoped to the selected employees' unit.";
  const noMatchingActivitiesMessage =
    role === 'dept_head' || role === 'hr_manager'
      ? 'No section-specific workplan activities match the selected section head(s). Create or cascade activities to those section(s) first.'
      : 'No matching workplan activities.';

  const openAdd = () => {
    setFormName('');
    setFormEmpIds([]);
    setFormActIds([]);
    setFormObjectiveMarks({});
    setFormActive(true);
    setFormRecurrent(false);
    setError('');
    setModal({ open: true, mode: 'add' });
  };

  const openEdit = (k: Kpi) => {
    setFormName(k.name);
    setFormEmpIds(parseIds(k.assigned_to_employee_ids));
    const activityIds = parseIds(k.activity_ids);
    setFormActIds(activityIds);
    const baseMark = activityIds.length > 0 ? Math.floor(k.max_score / activityIds.length) : 0;
    let remainingMarks = Math.max(0, k.max_score - baseMark * activityIds.length);
    setFormObjectiveMarks(
      Object.fromEntries(
        activityIds.map((id) => {
          const marks = baseMark + (remainingMarks > 0 ? 1 : 0);
          remainingMarks -= remainingMarks > 0 ? 1 : 0;
          return [id, marks];
        }),
      ),
    );
    setFormActive(!!k.is_active);
    setFormRecurrent(!!k.is_recurrent);
    setError('');
    setModal({ open: true, mode: 'edit', record: k });
  };

  const toggleEmp = (id: number) =>
    setFormEmpIds((ids) => (ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id]));
  const toggleAct = (id: number) => {
    setFormActIds((ids) =>
      ids.includes(id) ? ids.filter((activityId) => activityId !== id) : [...ids, id],
    );
    setFormObjectiveMarks((marks) => {
      if (formActIds.includes(id)) {
        return Object.fromEntries(
          Object.entries(marks).filter(([activityId]) => Number(activityId) !== id),
        );
      }
      return { ...marks, [id]: marks[id] ?? 0 };
    });
  };

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
    if (formMaxScore <= 0) {
      setError('Assign marks to at least one linked objective.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const payload = {
        name: formName.trim(),
        max_score: formMaxScore,
        activity_ids: formActIds,
        objective_marks: Object.fromEntries(
          formActIds.map((activityId) => [activityId, formObjectiveMarks[activityId] || 0]),
        ),
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

      {/* Strategy & Performance module tabs (shared — includes KPIs link) */}
      <StrategyTabs />

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
                  const empNames = assignedEmployeeNames(k);
                  const empNamesLabel = empNames.join(', ');
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
                            const act = activityById.get(id);
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
                        title={empNamesLabel}
                      >
                        {empNamesLabel || '—'}
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
                <label className={labelCls}>Aggregate Max Score *</label>
                <input
                  type="number"
                  min={0}
                  className={inputCls}
                  value={formMaxScore}
                  readOnly
                  aria-readonly="true"
                />
                <p className="mt-1 text-xs text-gray-500">
                  Automatically calculated from the marks assigned to linked objectives.
                </p>
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
                    onClick={() => setFormEmpIds(employees.map((e) => Number(e.id)))}
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
              {employeeScopeHint && (
                <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">{employeeScopeHint}</p>
              )}
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
                      checked={formEmpIds.includes(Number(e.id))}
                      onChange={() => toggleEmp(Number(e.id))}
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
                    onClick={() => {
                      const activityIds = modalActivities.map((a) => Number(a.id));
                      setFormActIds(activityIds);
                      setFormObjectiveMarks((marks) =>
                        Object.fromEntries(activityIds.map((id) => [id, marks[id] ?? 0])),
                      );
                    }}
                  >
                    Select All
                  </button>
                  <button
                    type="button"
                    className="underline text-gray-500"
                    onClick={() => {
                      setFormActIds([]);
                      setFormObjectiveMarks({});
                    }}
                  >
                    Deselect All
                  </button>
                  <span className="text-gray-400">{formActIds.length} selected</span>
                </div>
              </div>
              <p className="text-xs text-gray-400 mb-1">{activityScopeHint}</p>
              <div className="max-h-56 overflow-y-auto border border-gray-200 dark:border-slate-600 rounded-md divide-y divide-gray-100 dark:divide-slate-700">
                {formEmpIds.length === 0 && (
                  <p className="p-3 text-sm text-gray-400">Select at least one employee first.</p>
                )}
                {formEmpIds.length > 0 && modalActivities.length === 0 && (
                  <p className="p-3 text-sm text-gray-400">{noMatchingActivitiesMessage}</p>
                )}
                {modalActivities.map((a) => (
                  <div
                    key={a.id}
                    className="flex items-start gap-3 px-3 py-2 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer"
                  >
                    <input
                      id={`kpi-objective-${a.id}`}
                      type="checkbox"
                      checked={formActIds.includes(Number(a.id))}
                      onChange={() => toggleAct(Number(a.id))}
                      className="h-4 w-4 mt-0.5 accent-primary-600"
                    />
                    <div className="flex flex-1 min-w-0 flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                      <label htmlFor={`kpi-objective-${a.id}`} className="flex-1 min-w-0">
                        <span className="block text-sm text-gray-900 dark:text-gray-100">
                          {a.objective}
                        </span>
                        {a.parent_objective && (
                          <span className="block text-xs text-gray-400">
                            under: {a.parent_objective}
                          </span>
                        )}
                        <span className="flex flex-wrap gap-1 mt-1">
                          {formActIds.includes(Number(a.id)) &&
                            !selectedEmployees.some((emp) => activityMatchesEmployee(a, emp)) && (
                              <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                Existing link is outside the selected unit — unselect to remove
                              </span>
                            )}
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
                      </label>
                      {formActIds.includes(Number(a.id)) && (
                        <label className="flex shrink-0 items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                          Marks
                          <input
                            type="number"
                            min={0}
                            step={1}
                            value={formObjectiveMarks[Number(a.id)] ?? 0}
                            onChange={(event) => {
                              const parsed = Number.parseInt(event.target.value, 10);
                              setFormObjectiveMarks((marks) => ({
                                ...marks,
                                [Number(a.id)]: Number.isFinite(parsed) ? Math.max(0, parsed) : 0,
                              }));
                            }}
                            className="w-20 rounded-md border border-gray-300 bg-white px-2 py-1 text-sm text-gray-700 dark:border-slate-600 dark:bg-slate-800 dark:text-gray-200"
                            aria-label={`Marks for ${a.objective}`}
                          />
                        </label>
                      )}
                    </div>
                  </div>
                ))}
              </div>
              <p className="mt-2 text-xs text-gray-500">
                Aggregate KPI Max Score: <strong>{formMaxScore}</strong>
              </p>
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

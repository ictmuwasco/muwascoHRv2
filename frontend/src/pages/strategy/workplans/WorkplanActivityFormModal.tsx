import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Button from '../../../components/ui/Button';
import { workplanService } from '../../../api/services/workplanService';
import type {
  AssignableEmployee, UnitRef, WorkplanObjective,
} from '../../../api/services/workplanService';
import type { AppraisalCycle } from '../../../api/services/appraisalCycleService';
import { appraisalCycleService, cycleLabel } from '../../../api/services/appraisalCycleService';

export interface StrategyRefs {
  contracts: { id: number; name: string; goal_id: number; target_id: number | null; department_id?: number | null; department_name?: string | null }[];
  goals: { id: number; name: string }[];
  targets: { id: number; name: string; goal_id?: number }[];
}

interface Props {
  isOpen: boolean;
  mode: 'add' | 'edit';
  record?: WorkplanObjective | null;
  refs: StrategyRefs;
  sections: UnitRef[];
  subsections: UnitRef[];
  employees: AssignableEmployee[];
  /** Organisation-level (MD) activities may anchor to goals without a contract. */
  allowContractless: boolean;
  showSection: boolean;
  showSubsection: boolean;
  showOfficer: boolean;
  showIntegratedFlag: boolean;
  /** Legacy MD flow: goal perspective + organisational goal + quarters drive the form. */
  mdMode?: boolean;
  cycles: AppraisalCycle[];
  presetContractId?: number | null;
  departmentId?: number | null;
  onClose(): void;
  onSaved(message: string): void;
  onError(message: string): void;
}

const MEASURE_SUGGESTIONS = ['Percentage', 'Number', 'Days', 'KES', 'Rate', 'Score'];

const labelCls = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';
const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';

/**
 * Create / edit dialog for workplan activities. The backend enforces all
 * cascade ownership rules; this form simply collects the planning data and
 * never lets the caller pick units outside the lists supplied by the API
 * (which are already scoped to the caller's organisational reach).
 */
export default function WorkplanActivityFormModal({
  isOpen, mode, record, refs, sections, subsections, employees,
  allowContractless, showSection, showSubsection, showOfficer, showIntegratedFlag,
  mdMode = false, cycles = [], presetContractId, departmentId, onClose, onSaved, onError,
}: Props) {
  const [objective, setObjective] = useState('');
  const [kpi, setKpi] = useState('');
  const [measure, setMeasure] = useState('');
  const [contractId, setContractId] = useState('');
  const [goalId, setGoalId] = useState('');
  const [targetId, setTargetId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [subsectionId, setSubsectionId] = useState('');
  const [officerId, setOfficerId] = useState('');
  const [pStart, setPStart] = useState('');
  const [pEnd, setPEnd] = useState('');
  const [budget, setBudget] = useState('');
  const [notes, setNotes] = useState('');
  const [cycleIds, setCycleIds] = useState<number[]>([]);
  const [integrated, setIntegrated] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    if (mode === 'edit' && record) {
      setObjective(record.objective);
      setKpi(record.kpi);
      setMeasure(record.measure_unit);
      setContractId(record.performance_contract_id ? String(record.performance_contract_id) : '');
      setGoalId(record.goal_id ? String(record.goal_id) : '');
      setTargetId(record.strategic_target_id ? String(record.strategic_target_id) : '');
      setSectionId(record.section_id ? String(record.section_id) : '');
      setSubsectionId(record.subsection_id ? String(record.subsection_id) : '');
      setOfficerId(record.responsible_officer_id ? String(record.responsible_officer_id) : '');
      setPStart(record.planned_start_date?.slice(0, 10) ?? '');
      setPEnd(record.planned_end_date?.slice(0, 10) ?? '');
      setBudget(record.budget_amount ? String(record.budget_amount) : '');
      setNotes(record.resource_notes ?? '');
      setCycleIds((record.cycle_ids ?? '')
        .split(',')
        .map((n) => parseInt(n, 10))
        .filter((n) => !Number.isNaN(n) && n > 0));
      setIntegrated(!!record.is_integrated);
    } else {
      setObjective(''); setKpi(''); setMeasure('');
      setContractId(presetContractId ? String(presetContractId) : '');
      setGoalId(''); setTargetId(''); setSectionId(''); setSubsectionId(''); setOfficerId('');
      setPStart(''); setPEnd(''); setBudget(''); setNotes(''); setCycleIds([]);
      setIntegrated(false);
    }
  }, [isOpen, mode, record, presetContractId]);

  // Refresh appraisal cycles whenever the form opens so the quarter picker is
  // never empty even if the page-level reference fetch failed earlier.
  const [liveCycles, setLiveCycles] = useState<AppraisalCycle[] | null>(null);
  useEffect(() => {
    if (!isOpen) return;
    let alive = true;
    appraisalCycleService.list()
      .then((r) => { if (alive) setLiveCycles(r.data?.cycles ?? []); })
      .catch(() => { /* keep whatever the parent already supplied */ });
    return () => { alive = false; };
  }, [isOpen]);

  // Department-pinned contract list: a head's form only ever offers the
  // performance contracts that belong to their own department.
  const visibleContracts = departmentId == null
    ? refs.contracts
    : refs.contracts.filter((c) => c.department_id === departmentId);
  const allCycles = liveCycles ?? cycles;

  const submit = async () => {
    // Legacy parity: departmental rows carry description + cycles; KPI/measure
    // optional. MD assignments are driven by goal + organisational goal + quarters.
    if (!mdMode && (!objective.trim() || !kpi.trim() || !measure.trim())) {
      onError('Objective, KPI and measure unit are required.');
      return;
    }
    if (mdMode) {
      if (!objective.trim()) {
        // Legacy MD assignments carried no free-text objective - only goal,
        // organisational goal and quarters. Description stays optional.
      } else {
        // keep the typed objective when provided
      }
    }
    if (mdMode && (!goalId || !targetId)) {
      onError('Select the Goal Perspective AND the Organisational Goal.');
      return;
    }
    if (cycleIds.length === 0) {
      onError('Select at least one appraisal cycle (quarter).');
      return;
    }
    if (!contractId && !allowContractless) {
      onError('Please select the source performance contract.');
      return;
    }
    setSaving(true);
    try {
      const payload: Record<string, any> = {
        objective: objective.trim(),
        kpi: kpi.trim(),
        measure_unit: measure.trim(),
        performance_contract_id: contractId ? Number(contractId) : null,
        goal_id: goalId ? Number(goalId) : null,
        strategic_target_id: targetId ? Number(targetId) : null,
        section_id: showSection && sectionId ? Number(sectionId) : null,
        subsection_id: showSubsection && subsectionId ? Number(subsectionId) : null,
        responsible_officer_id: showOfficer && officerId ? Number(officerId) : null,
        planned_start_date: pStart || null,
        planned_end_date: pEnd || null,
        budget_amount: budget === '' ? 0 : Number(budget),
        resource_notes: notes.trim() || null,
        cycle_ids: cycleIds.join(','),
      };
      if (showIntegratedFlag) payload.is_integrated = integrated ? 1 : 0;

      if (mode === 'edit' && record) {
        await workplanService.update(record.id, payload);
        onSaved('Activity updated successfully.');
      } else {
        await workplanService.create(payload);
        onSaved('Activity created successfully.');
      }
      onClose();
    } catch (err: any) {
      onError(err.response?.data?.message || 'Failed to save the activity.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose}
      title={mode === 'edit' ? 'Edit Activity' : 'New Workplan Activity'} size="xl">
      <div className="space-y-4">
        <div>
          <label className={labelCls}>Activity / Objective *</label>
          <textarea rows={2} className={inputCls} value={objective}
            onChange={(e) => setObjective(e.target.value)}
            placeholder={mdMode
              ? 'Optional — legacy MD goal assignments leave this blank'
              : 'Describe the activity to be performed'} />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label className={labelCls}>KPI / Indicator *</label>
            <input className={inputCls} value={kpi} onChange={(e) => setKpi(e.target.value)} />
          </div>
          <div>
            <label className={labelCls}>Measure Unit *</label>
            <input className={inputCls} list="wp-measures" value={measure}
              onChange={(e) => setMeasure(e.target.value)} />
            <datalist id="wp-measures">
              {MEASURE_SUGGESTIONS.map((m) => <option key={m} value={m} />)}
            </datalist>
          </div>
          <div>
            <label className={labelCls}>Target Date</label>
            <input type="date" className={inputCls} value={pEnd} onChange={(e) => setPEnd(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div className={allowContractless ? 'md:col-span-2' : 'md:col-span-2'}>
            <label className={labelCls}>
              Source Performance Contract {allowContractless ? '(optional)' : '*'}
            </label>
            <select className={inputCls} value={contractId} onChange={(e) => {
              setContractId(e.target.value);
              const c = visibleContracts.find((x) => String(x.id) === e.target.value);
              setGoalId(c?.goal_id ? String(c.goal_id) : '');
              setTargetId(c?.target_id ? String(c.target_id) : '');
            }}>
              {allowContractless && <option value="">— Organisation-level (no contract) —</option>}
              {visibleContracts.map((c) => (
                <option key={c.id} value={String(c.id)}>
                  {c.name}{c.department_name ? ` — ${c.department_name}` : ''}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className={labelCls}>Start Date</label>
            <input type="date" className={inputCls} value={pStart} onChange={(e) => setPStart(e.target.value)} />
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className={labelCls}>Strategic Goal</label>
            <select className={inputCls} value={goalId} onChange={(e) => { setGoalId(e.target.value); setTargetId(''); }}>
              <option value="">— Select goal perspective —</option>
              {refs.goals.map((g) => <option key={g.id} value={String(g.id)}>{g.name}</option>)}
            </select>
          </div>
          <div>
            <label className={labelCls}>Strategic Target</label>
            <select className={inputCls} value={targetId} onChange={(e) => setTargetId(e.target.value)}>
              <option value="">— Select target —</option>
              {refs.targets
                .filter((t) => !goalId || !t.goal_id || String(t.goal_id) === goalId)
                .map((t) => <option key={t.id} value={String(t.id)}>{t.name}</option>)}
            </select>
          </div>
        </div>

        {(showSection || showSubsection || showOfficer) && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            {showSection && (
              <div>
                <label className={labelCls}>Responsible Section</label>
                <select className={inputCls} value={sectionId}
                  onChange={(e) => { setSectionId(e.target.value); setSubsectionId(''); }}>
                  <option value="">— Whole department —</option>
                  {sections.map((s) => <option key={s.id} value={String(s.id)}>{s.name}</option>)}
                </select>
              </div>
            )}
            {showSubsection && (
              <div>
                <label className={labelCls}>Responsible Subsection</label>
                <select className={inputCls} value={subsectionId} onChange={(e) => setSubsectionId(e.target.value)}>
                  <option value="">— None —</option>
                  {subsections
                    .filter((ss) => !sectionId || !ss.section_id || String(ss.section_id) === sectionId)
                    .map((ss) => <option key={ss.id} value={String(ss.id)}>{ss.name}</option>)}
                </select>
              </div>
            )}
            {showOfficer && (
              <div>
                <label className={labelCls}>Responsible Employee</label>
                <select className={inputCls} value={officerId} onChange={(e) => setOfficerId(e.target.value)}>
                  <option value="">— Unassigned —</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={String(emp.id)}>
                      {emp.name}{emp.position ? ` (${emp.position})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
        )}

        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label className={labelCls}>Budget (KES)</label>
            <input type="number" min="0" step="0.01" className={inputCls} value={budget}
              onChange={(e) => setBudget(e.target.value)} />
          </div>
          <div className="md:col-span-2">
            <label className={labelCls}>Appraisal Cycle(s) *</label>
            <div className="flex flex-wrap gap-x-4 gap-y-2 rounded-lg border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 min-h-[42px] items-center">
              {allCycles.length === 0 ? (
                <span className="text-sm text-gray-400">No appraisal cycles yet — HR Admin must create quarterly cycles first.</span>
              ) : (
                allCycles.map((c) => (
                  <label key={c.id} className="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200">
                    <input type="checkbox" checked={cycleIds.includes(c.id)}
                      onChange={(e) => setCycleIds((prev) =>
                        e.target.checked ? [...prev, c.id] : prev.filter((n) => n !== c.id))} />
                    {cycleLabel(c)}
                  </label>
                ))
              )}
            </div>
          </div>
          {showIntegratedFlag && (
            <label className="flex items-end gap-2 pb-2 text-sm text-gray-700 dark:text-gray-200">
              <input type="checkbox" checked={integrated} onChange={(e) => setIntegrated(e.target.checked)} />
              Show in organisation integrated view
            </label>
          )}
        </div>

        <div>
          <label className={labelCls}>Resource Notes</label>
          <textarea rows={2} className={inputCls} value={notes} onChange={(e) => setNotes(e.target.value)}
            placeholder="Funding, dependencies, remarks…" />
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t dark:border-slate-700">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} disabled={saving}>
            {saving ? 'Saving…' : mode === 'edit' ? 'Save Changes' : 'Create Activity'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

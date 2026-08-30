import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Button from '../../../components/ui/Button';
import { GitBranch } from 'lucide-react';
import { workplanService } from '../../../api/services/workplanService';
import type {
  AssignableEmployee, UnitRef, WorkplanObjective,
} from '../../../api/services/workplanService';
import type { AppraisalCycle } from '../../../api/services/appraisalCycleService';
import { appraisalCycleService, cycleLabel } from '../../../api/services/appraisalCycleService';

interface Props {
  isOpen: boolean;
  parent: WorkplanObjective | null;
  /** Caller's reachable placement lists (already role-scoped by the API). */
  contracts: { id: number; name: string; department_id?: number | null; department_name?: string | null }[];
  sections: UnitRef[];
  subsections: UnitRef[];
  employees: AssignableEmployee[];
  /** Active appraisal cycles the child activity is scheduled against. */
  cycles: AppraisalCycle[];
  departmentId?: number | null;
  onClose(): void;
  onCascaded(message: string): void;
  onError(message: string): void;
}

const labelCls = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';
const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';

/**
 * One-click downward cascade. Creates a validated CHILD activity linked to
 * the selected parent (parent_objective_id lineage) — never a detached
 * duplicate. The target level is inferred from the parent:
 *   organisation -> department (pick contract)
 *   department   -> section    (pick section)
 *   section      -> subsection (pick subsection)
 *   subsection   -> employee task (assign supervised employee)
 */
export default function CascadeActivityDialog({
  isOpen, parent, contracts, sections, subsections, employees, cycles = [], departmentId,
  onClose, onCascaded, onError,
}: Props) {
  const [liveCycles, setLiveCycles] = useState<AppraisalCycle[] | null>(null);
  const [objective, setObjective] = useState('');
  const [kpi, setKpi] = useState('');
  const [measure, setMeasure] = useState('');
  const [contractId, setContractId] = useState('');
  const [sectionId, setSectionId] = useState('');
  const [subsectionId, setSubsectionId] = useState('');
  const [officerId, setOfficerId] = useState('');
  const [pEnd, setPEnd] = useState('');
  const [budget, setBudget] = useState('');
  const [notes, setNotes] = useState('');
  const [cycleIds, setCycleIds] = useState<number[]>([]);
  const [saving, setSaving] = useState(false);

  const parentLevel = parent?.level ?? null;

  useEffect(() => {
    if (!isOpen) return;
    setObjective(''); setKpi(''); setMeasure('');
    setContractId(''); setSectionId(''); setSubsectionId(''); setOfficerId('');
    setPEnd(''); setBudget(''); setNotes(''); setCycleIds([]);
  }, [isOpen, parent?.id]);

  // Refresh appraisal cycles whenever the form opens so the quarter picker is
  // never empty even if the page-level reference fetch failed earlier.
  useEffect(() => {
    if (!isOpen) return;
    let alive = true;
    appraisalCycleService.list()
      .then((r) => { if (alive) setLiveCycles(r.data?.cycles ?? []); })
      .catch(() => { /* keep whatever the parent already supplied */ });
    return () => { alive = false; };
  }, [isOpen]);

  if (!isOpen || !parent) return null;

  const visibleContracts = departmentId == null
    ? contracts
    : contracts.filter((c) => c.department_id === departmentId);
  const allCycles = liveCycles ?? cycles;

  const submit = async () => {
    if (!objective.trim() || !kpi.trim() || !measure.trim()) {
      onError('Objective, KPI and measure unit are required.');
      return;
    }
    if (parentLevel === 'organisation' && !contractId) {
      onError('Select the departmental performance commitment this work supports.');
      return;
    }
    if (parentLevel === 'department' && !sectionId) {
      onError('Select the responsible section.');
      return;
    }
    if (parentLevel === 'section' && !subsectionId) {
      onError('Select the responsible subsection.');
      return;
    }

    setSaving(true);
    try {
      await workplanService.cascade(parent.id, {
        objective: objective.trim(),
        kpi: kpi.trim(),
        measure_unit: measure.trim(),
        performance_contract_id: parentLevel === 'organisation'
          ? Number(contractId)
          : (parent.performance_contract_id ?? null),
        section_id: sectionId ? Number(sectionId) : null,
        subsection_id: subsectionId ? Number(subsectionId) : null,
        responsible_officer_id: officerId ? Number(officerId) : null,
        planned_end_date: pEnd || null,
        budget_amount: budget === '' ? 0 : Number(budget),
        resource_notes: notes.trim() || null,
        // Empty string -> backend inherits the parent's cycles.
        cycle_ids: cycleIds.join(','),
      });
      onCascaded(`Activity cascaded to the next level successfully (#${parent.id} → new activity).`);
      onClose();
    } catch (err: any) {
      onError(err.response?.data?.message || 'Failed to cascade the activity.');
    } finally {
      setSaving(false);
    }
  };

  const targetLabel =
    parentLevel === 'organisation' ? 'Departmental Commitment (Performance Contract)'
      : parentLevel === 'department' ? 'Responsible Section *'
        : parentLevel === 'section' ? 'Responsible Subsection *'
          : 'Assign to Supervised Employee';

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Cascade Activity Downward" size="lg">
      <div className="space-y-4">
        <div className="rounded-md bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-900/60 px-3 py-2 text-sm text-indigo-800 dark:text-indigo-200">
          <p className="flex items-center gap-1.5 font-medium">
            <GitBranch className="h-4 w-4" />Cascading from:
          </p>
          <p className="mt-0.5 line-clamp-2">{parent.objective}</p>
          <p className="mt-1 text-xs opacity-80">
            A linked child activity will be created at the next level — the parent stays intact for traceability.
          </p>
        </div>

        <div className={`grid grid-cols-1 gap-3 ${parentLevel === 'subsection' ? '' : 'md:grid-cols-2'}`}>
          <div>
            <label className={labelCls}>Child Activity / Task *</label>
            <textarea rows={2} className={inputCls} value={objective}
              onChange={(e) => setObjective(e.target.value)}
              placeholder="Break the parent activity into this level's actionable work" />
          </div>
          <div className="space-y-3">
            <div>
              <label className={labelCls}>KPI / Indicator *</label>
              <input className={inputCls} value={kpi} onChange={(e) => setKpi(e.target.value)} />
            </div>
            <div>
              <label className={labelCls}>Measure Unit *</label>
              <input className={inputCls} value={measure} onChange={(e) => setMeasure(e.target.value)}
                placeholder="Percentage, Number…" />
            </div>
          </div>
        </div>

        {parentLevel !== 'subsection' && (
          <div>
            <label className={labelCls}>{targetLabel}</label>
            {parentLevel === 'organisation' && (
              <select className={inputCls} value={contractId} onChange={(e) => setContractId(e.target.value)}>
                <option value="">— Select commitment —</option>
                {visibleContracts.map((c) => (
                  <option key={c.id} value={String(c.id)}>
                    {c.name}{c.department_name ? ` — ${c.department_name}` : ''}
                  </option>
                ))}
              </select>
            )}
            {parentLevel === 'department' && (
              <select className={inputCls} value={sectionId} onChange={(e) => setSectionId(e.target.value)}>
                <option value="">— Select section —</option>
                {sections.map((s) => <option key={s.id} value={String(s.id)}>{s.name}</option>)}
              </select>
            )}
            {parentLevel === 'section' && (
              <select className={inputCls} value={subsectionId} onChange={(e) => setSubsectionId(e.target.value)}>
                <option value="">— Select subsection —</option>
                {subsections.map((ss) => <option key={ss.id} value={String(ss.id)}>{ss.name}</option>)}
              </select>
            )}
          </div>
        )}

        {parentLevel === 'subsection' && (
          <div>
            <label className={labelCls}>{targetLabel}</label>
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

        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label className={labelCls}>Deadline</label>
            <input type="date" className={inputCls} value={pEnd} onChange={(e) => setPEnd(e.target.value)} />
          </div>
          <div>
            <label className={labelCls}>Budget (KES)</label>
            <input type="number" min="0" step="0.01" className={inputCls} value={budget}
              onChange={(e) => setBudget(e.target.value)} />
          </div>
        </div>

        <div>
          <label className={labelCls}>Notes / Remarks</label>
          <textarea rows={2} className={inputCls} value={notes} onChange={(e) => setNotes(e.target.value)}
            placeholder="Guidance for the receiving unit…" />
        </div>

        <div>
          <label className={labelCls}>Appraisal Cycle(s)</label>
          <div className="flex flex-wrap gap-x-4 gap-y-2 rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-2 min-h-[42px] items-center">
            {allCycles.length === 0 ? (
              <span className="text-sm text-gray-400">No appraisal cycles yet — will inherit the parent's quarters.</span>
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
          <p className="text-xs text-gray-400 mt-1">Leave all unchecked to keep the parent activity's quarters.</p>
        </div>

        <div className="flex justify-end gap-2 pt-2 border-t dark:border-slate-700">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} disabled={saving}>
            {saving ? 'Cascading…' : 'Cascade Activity'}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

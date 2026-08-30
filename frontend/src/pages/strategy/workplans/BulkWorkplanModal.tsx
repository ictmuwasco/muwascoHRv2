import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Button from '../../../components/ui/Button';
import { Plus } from 'lucide-react';
import { workplanService } from '../../../api/services/workplanService';
import type {
  AssignableEmployee, UnitRef, WorkplanObjective,
} from '../../../api/services/workplanService';
import type { AppraisalCycle } from '../../../api/services/appraisalCycleService';
import { appraisalCycleService, cycleLabel } from '../../../api/services/appraisalCycleService';

interface Props {
  isOpen: boolean;
  contracts: { id: number; name: string; goal_name?: string | null; department_id?: number | null; department_name?: string | null }[];
  sections: UnitRef[];
  subsections: UnitRef[];
  employees: AssignableEmployee[];
  cycles: AppraisalCycle[];
  departmentId?: number | null;
  onClose(): void;
  onSaved(message: string): void;
  onError(message: string): void;
}

const labelCls = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';
const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';

interface RowState {
  objective: string;
  section_id: string;
  subsection_id: string;
  cycle_ids: number[];
  planned_end_date: string;
}

/**
 * Legacy-parity "Create Workplan" form for Department Heads:
 * ONE performance contract -> MANY activity rows, each targeted at its own
 * section (and optionally a subsection) with its own appraisal quarters.
 */
export default function BulkWorkplanModal({
  isOpen, contracts, sections, subsections, employees, cycles = [], departmentId,
  onClose, onSaved, onError,
}: Props) {
  const [liveCycles, setLiveCycles] = useState<AppraisalCycle[] | null>(null);
  const [contractId, setContractId] = useState('');
  const [rows, setRows] = useState<RowState[]>([]);
  const [saving, setSaving] = useState(false);

  // Pin the contract picker to the caller's own department.
  const visibleContracts = departmentId == null
    ? contracts
    : contracts.filter((c) => c.department_id === departmentId);
  const allCycles = liveCycles ?? cycles;

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

  useEffect(() => {
    if (!isOpen) return;
    setContractId('');
    setRows([]);
  }, [isOpen]);

  if (!isOpen) return null;

  const addRow = () => setRows((r) => [...r, {
    objective: '', section_id: '', subsection_id: '', cycle_ids: [], planned_end_date: '',
  }]);
  const updateRow = (idx: number, patch: Partial<RowState>) =>
    setRows((r) => r.map((row, i) => (i === idx ? { ...row, ...patch } : row)));
  const removeRow = (idx: number) => setRows((r) => r.filter((_, i) => i !== idx));

  const submit = async () => {
    const cid = Number(contractId);
    if (!cid) { onError('Select the departmental performance contract.'); return; }
    if (rows.length === 0) { onError('Add at least one activity.'); return; }

    const items: Record<string, any>[] = [];
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (!row.objective.trim()) { onError(`Activity #${i + 1}: description is required.`); return; }
      if (!row.section_id) { onError(`Activity #${i + 1}: choose the responsible section.`); return; }
      if (row.cycle_ids.length === 0) { onError(`Activity #${i + 1}: select at least one appraisal cycle.`); return; }
      items.push({
        objective: row.objective.trim(),
        kpi: '',
        measure_unit: '',
        section_id: Number(row.section_id),
        subsection_id: row.subsection_id ? Number(row.subsection_id) : null,
        cycle_ids: row.cycle_ids.join(','),
        planned_end_date: row.planned_end_date || null,
      });
    }

    setSaving(true);
    try {
      await workplanService.bulkCreate({ performance_contract_id: cid, items });
      onSaved(`${items.length} activit${items.length === 1 ? 'y' : 'ies'} saved under the selected contract.`);
      onClose();
    } catch (err: any) {
      onError(err.response?.data?.message || 'Failed to save the activities.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Create Departmental Workplan" size="xl">
      <div className="space-y-4">
        <div>
          <label className={labelCls}>Departmental Performance Contract *</label>
          <select className={inputCls} value={contractId} onChange={(e) => setContractId(e.target.value)}>
            <option value="">— Select contract —</option>
            {visibleContracts.map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.name}{c.goal_name ? ` — ${c.goal_name}` : ''}
              </option>
            ))}
          </select>
          <p className="text-xs text-gray-400 mt-1">
            Every activity below is created under this contract, then routed to its own section.
          </p>
        </div>

        {rows.map((row, idx) => (
          <div key={idx} className="rounded-lg border border-gray-200 dark:border-slate-700 p-3 space-y-3 bg-gray-50/60 dark:bg-slate-900/40">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold uppercase tracking-wide text-primary-600">Activity #{idx + 1}</span>
              <button type="button" onClick={() => removeRow(idx)}
                className="text-xs text-red-600 hover:underline">Remove</button>
            </div>

            <textarea rows={2} className={inputCls} value={row.objective}
              onChange={(e) => updateRow(idx, { objective: e.target.value })}
              placeholder="What must be delivered…" />

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label className={labelCls}>Responsible Section *</label>
                <select className={inputCls} value={row.section_id}
                  onChange={(e) => updateRow(idx, { section_id: e.target.value, subsection_id: '' })}>
                  <option value="">— Select section —</option>
                  {sections.map((s) => <option key={s.id} value={String(s.id)}>{s.name}</option>)}
                </select>
              </div>
              <div>
                <label className={labelCls}>Sub-section (optional)</label>
                <select className={inputCls} value={row.subsection_id}
                  onChange={(e) => updateRow(idx, { subsection_id: e.target.value })}
                  disabled={!row.section_id}>
                  <option value="">— None —</option>
                  {subsections
                    .filter((ss) => String(ss.section_id ?? '') === row.section_id)
                    .map((ss) => <option key={ss.id} value={String(ss.id)}>{ss.name}</option>)}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label className={labelCls}>Appraisal Cycle(s) *</label>
                <div className="flex flex-wrap gap-x-4 gap-y-2 rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 min-h-[42px] items-center">
                  {allCycles.length === 0 ? (
                    <span className="text-sm text-gray-400">No appraisal cycles yet — ask HR Admin to create quarterly cycles.</span>
                  ) : (
                    allCycles.map((c) => (
                      <label key={c.id} className="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" checked={row.cycle_ids.includes(c.id)}
                          onChange={(e) => updateRow(idx, {
                            cycle_ids: e.target.checked
                              ? [...row.cycle_ids, c.id]
                              : row.cycle_ids.filter((n) => n !== c.id),
                          })} />
                        {cycleLabel(c)}
                      </label>
                    ))
                  )}
                </div>
              </div>
              <div>
                <label className={labelCls}>Target Date</label>
                <input type="date" className={inputCls} value={row.planned_end_date}
                  onChange={(e) => updateRow(idx, { planned_end_date: e.target.value })} />
              </div>
            </div>
          </div>
        ))}

        <button type="button" onClick={addRow}
          className="w-full rounded-lg border border-dashed border-primary-300 dark:border-primary-800 py-2.5 text-sm font-medium text-primary-600 hover:bg-primary-50/50 dark:hover:bg-slate-800 flex items-center justify-center gap-1.5">
          <Plus className="h-4 w-4" />Add another activity
        </button>

        <div className="flex justify-end gap-2 pt-2 border-t dark:border-slate-700">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} disabled={saving || visibleContracts.length === 0}>
            {saving ? 'Saving…' : `Save ${rows.length || ''} activit${rows.length === 1 ? 'y' : 'ies'}`}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

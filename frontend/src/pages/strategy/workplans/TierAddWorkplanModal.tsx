import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Button from '../../../components/ui/Button';
import { Plus } from 'lucide-react';
import { workplanService } from '../../../api/services/workplanService';
import type { AssignableEmployee, UnitRef } from '../../../api/services/workplanService';
import type { AppraisalCycle } from '../../../api/services/appraisalCycleService';
import { appraisalCycleService, cycleLabel } from '../../../api/services/appraisalCycleService';

interface Props {
  isOpen: boolean;
  /** section-head or subsection-head view drives which cascade fields show. */
  kind: 'section' | 'subsection';
  /** Admin / management-added activities the head plans their own work against. */
  sources: { id: number; objective: string }[];
  /** Section head cascades to these (only shown when the section HAS subsections). */
  subsections: UnitRef[];
  /** Employees the head can assign the activity to (their own unit's staff). */
  employees: AssignableEmployee[];
  /** Own section id (section heads only; subsection heads are server-pinned). */
  sectionId?: number | null;
  cycles: AppraisalCycle[];
  onClose(): void;
  onSaved(message: string): void;
  onError(message: string): void;
}

const labelCls = 'block text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1';
const inputCls =
  'w-full rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';

interface RowState {
  objective: string;
  kpi: string;
  measure: string;
  officer_id: string;
  subsection_id: string;
  target_date: string;
  cycle_ids: number[];
}

/**
 * Section / Sub-section head activity form. Instead of picking a performance
 * contract, the head fetches the management-added (source / parent) activities
 * cascaded into their unit, then adds one or more of their own activities under
 * the selected source. Section heads can optionally cascade to a subsection
 * (the subsection field only appears when their section actually has one).
 */
export default function TierAddWorkplanModal({
  isOpen, kind, sources, subsections, employees, sectionId, cycles = [],
  onClose, onSaved, onError,
}: Props) {
  const [liveCycles, setLiveCycles] = useState<AppraisalCycle[] | null>(null);
  const [sourceId, setSourceId] = useState('');
  const [rows, setRows] = useState<RowState[]>([]);
  const [saving, setSaving] = useState(false);

  const hasSubsections = subsections.length > 0;
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
    setSourceId('');
    setRows([]);
  }, [isOpen]);

  if (!isOpen) return null;

  const addRow = () => setRows((r) => [...r, {
    objective: '', kpi: '', measure: '', officer_id: '', subsection_id: '', target_date: '', cycle_ids: [],
  }]);
  const updateRow = (idx: number, patch: Partial<RowState>) =>
    setRows((r) => r.map((row, i) => (i === idx ? { ...row, ...patch } : row)));
  const removeRow = (idx: number) => setRows((r) => r.filter((_, i) => i !== idx));
const submit = async () => {
    if (!sourceId) { onError('Select the source activity added by management first.'); return; }
    if (rows.length === 0) { onError('Add at least one activity.'); return; }

    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      if (!row.objective.trim()) { onError(`Activity #${i + 1}: the activity description is required.`); return; }
      if (row.cycle_ids.length === 0) { onError(`Activity #${i + 1}: select at least one appraisal cycle (quarter).`); return; }
    }

    setSaving(true);
    try {
      const items = rows.map((row) => {
        const payload: Record<string, any> = {
          objective: row.objective.trim(),
          kpi: row.kpi.trim(),
          measure_unit: row.measure.trim(),
          performance_contract_id: null,
          parent_objective_id: Number(sourceId),
          responsible_officer_id: row.officer_id ? Number(row.officer_id) : null,
          planned_end_date: row.target_date || null,
          cycle_ids: row.cycle_ids.join(','),
        };
        if (kind === 'section') {
          if (sectionId) payload.section_id = Number(sectionId);
          if (hasSubsections && row.subsection_id) payload.subsection_id = Number(row.subsection_id);
        }
        return payload;
      });

      // One parent activity -> many head-planned activities, each created under it.
      for (const payload of items) {
        await workplanService.create(payload);
      }
      onSaved(`${items.length} activit${items.length === 1 ? 'y' : 'ies'} added under the selected source.`);
      onClose();
    } catch (err: any) {
      onError(err.response?.data?.message || 'Failed to save the activities.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose}
      title={kind === 'section' ? 'Add Section Workplan' : 'Add Subsection Workplan'} size="xl">
      <div className="space-y-4">
        <div>
          <label className={labelCls}>Source Activity (added by management) *</label>
          {sources.length === 0 ? (
            <p className="text-sm text-amber-600 dark:text-amber-400 rounded-md border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-3 py-2">
              No management-added activities are available yet. Wait for your supervisor to cascade work to your unit.
            </p>
          ) : (
            <select className={inputCls} value={sourceId} onChange={(e) => setSourceId(e.target.value)}>
              <option value="">— Select source activity —</option>
              {sources.map((s) => (
                <option key={s.id} value={String(s.id)}>{s.objective}</option>
              ))}
            </select>
          )}
          <p className="text-xs text-gray-400 mt-1">
            New activities below are created under this source and still roll their progress up the cascade.
          </p>
        </div>

        {rows.map((row, idx) => (
          <div key={idx} className="rounded-lg border border-gray-200 dark:border-slate-700 p-3 space-y-3 bg-gray-50/60 dark:bg-slate-900/40">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold uppercase tracking-wide text-primary-600">Activity #{idx + 1}</span>
              <button type="button" onClick={() => removeRow(idx)}
                className="text-xs text-red-600 hover:underline">Remove</button>
            </div>

            <div>
              <label className={labelCls}>Activity / Objective *</label>
              <textarea rows={2} className={inputCls} value={row.objective}
                onChange={(e) => updateRow(idx, { objective: e.target.value })}
                placeholder="What must be delivered…" />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className={labelCls}>KPI / Indicator</label>
                <input className={inputCls} value={row.kpi}
                  onChange={(e) => updateRow(idx, { kpi: e.target.value })} />
              </div>
              <div>
                <label className={labelCls}>Measure Unit</label>
                <input className={inputCls} value={row.measure}
                  onChange={(e) => updateRow(idx, { measure: e.target.value })}
                  placeholder="Percentage, Number…" />
              </div>
              <div>
                <label className={labelCls}>Responsible Officer</label>
                <select className={inputCls} value={row.officer_id}
                  onChange={(e) => updateRow(idx, { officer_id: e.target.value })}>
                  <option value="">— Unassigned —</option>
                  {employees.map((emp) => (
                    <option key={emp.id} value={String(emp.id)}>
                      {emp.name}{emp.position ? ` (${emp.position})` : ''}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label className={labelCls}>Target Date (Timeline)</label>
                <input type="date" className={inputCls} value={row.target_date}
                  onChange={(e) => updateRow(idx, { target_date: e.target.value })} />
              </div>
              {kind === 'section' && hasSubsections && (
                <div className={hasSubsections ? 'md:col-span-2' : ''}>
                  <label className={labelCls}>Cascade to Subsection (optional)</label>
                  <select className={inputCls} value={row.subsection_id}
                    onChange={(e) => updateRow(idx, { subsection_id: e.target.value })}>
                    <option value="">— Keep at section level —</option>
                    {subsections.map((ss) => <option key={ss.id} value={String(ss.id)}>{ss.name}</option>)}
                  </select>
                </div>
              )}
            </div>

            <div>
              <label className={labelCls}>Appraisal Cycle(s) / Quarters *</label>
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
          </div>
        ))}

        <button type="button" onClick={addRow}
          className="w-full rounded-lg border border-dashed border-primary-300 dark:border-primary-800 py-2.5 text-sm font-medium text-primary-600 hover:bg-primary-50/50 dark:hover:bg-slate-800 flex items-center justify-center gap-1.5">
          <Plus className="h-4 w-4" />Add another activity
        </button>

        <div className="flex justify-end gap-2 pt-2 border-t dark:border-slate-700">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={submit} disabled={saving || sources.length === 0}>
            {saving ? 'Saving…' : `Save ${rows.length || ''} activit${rows.length === 1 ? 'y' : 'ies'}`}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
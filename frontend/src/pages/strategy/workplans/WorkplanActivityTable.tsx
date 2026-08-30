import { useState } from 'react';
import Badge from '../../../components/ui/Badge';
import { GitBranch, Pencil, Trash2, Network, History } from 'lucide-react';
import type { WorkplanObjective } from '../../../api/services/workplanService';
import { fmtDate, isOverdue, levelLabel, statusMeta } from './workplanMeta';

export interface ProgressPatch { status?: string; progress_percent?: number }

interface Props {
  rows: WorkplanObjective[];
  canManage: boolean;
  showOfficer?: boolean;
  onEdit?(row: WorkplanObjective): void;
  onCascade?(row: WorkplanObjective): void;
  onTrace(row: WorkplanObjective): void;
  onHistory(row: WorkplanObjective): void;
  onDelete?(row: WorkplanObjective): void;
  onSaveProgress?(row: WorkplanObjective, patch: ProgressPatch): void;
}

const STATUS_OPTIONS: Record<string, string> = {
  not_started: 'Not Started',
  in_progress: 'In Progress',
  completed: 'Completed',
  at_risk: 'At Risk',
  off_track: 'Off Track',
};

/** Inline progress editor - drags commit onBlur so history isn't spammed. */
function ProgressControl({ row, canManage, onSave }: {
  row: WorkplanObjective; canManage: boolean; onSave?: Props['onSaveProgress'];
}) {
  const [draft, setDraft] = useState<number | null>(null);
  const value = Math.max(0, Math.min(100, draft ?? row.progress_percent));
  const meta = statusMeta(row.status);
  const commit = () => {
    if (draft !== null && draft !== row.progress_percent && onSave) onSave(row, { progress_percent: draft });
    setDraft(null);
  };
  return (
    <div className="space-y-1 min-w-[160px]">
      <div className="flex items-center gap-2">
        <div className="w-14 h-1.5 bg-gray-200 dark:bg-slate-600 rounded-full overflow-hidden flex-none">
          <div className={`h-full ${row.status === 'completed' ? 'bg-green-500' : value >= 50 ? 'bg-blue-500' : 'bg-amber-500'}`}
            style={{ width: `${value}%` }} />
        </div>
        <span className="text-xs font-medium text-gray-600 dark:text-gray-300">{value}%</span>
        <Badge variant={meta.variant}>{meta.label}</Badge>
      </div>
      {canManage && onSave && (
        <div className="flex items-center gap-2">
          <select className="text-xs rounded border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-1 py-0.5"
            value={row.status} onChange={(e) => onSave(row, { status: e.target.value })}>
            {Object.entries(STATUS_OPTIONS).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
          </select>
          <input type="range" min={0} max={100} step={5} value={value}
            onChange={(e) => setDraft(Number(e.target.value))}
            onMouseUp={commit} onTouchEnd={commit} onBlur={commit}
            title="Drag to adjust progress" className="w-20 align-middle" />
        </div>
      )}
    </div>
  );
}

const th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
const td = 'px-4 py-3 align-top text-sm';

/**
 * The activity list shared by every tier: shows lineage badges
 * (Cascaded vs Local), responsible unit, timeline, inline progress and
 * per-row actions (edit / cascade / traceability / history / delete).
 */
export default function WorkplanActivityTable({
  rows, canManage, showOfficer, onEdit, onCascade, onTrace, onHistory, onDelete, onSaveProgress,
}: Props) {
  if (rows.length === 0) {
    return (
      <div className="rounded-lg border border-dashed border-gray-300 dark:border-slate-600 p-8 text-center text-sm text-gray-500 dark:text-gray-400">
        No activities match the current filters yet.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
      <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 bg-white dark:bg-slate-800">
        <thead className="bg-gray-50 dark:bg-slate-900/60">
          <tr>
            <th className={th}>Activity</th>
            <th className={th}>KPI / Measure</th>
            <th className={th}>{showOfficer ? 'Responsible' : 'Responsible Unit'}</th>
            <th className={th}>Timeline</th>
            <th className={th}>Progress &amp; Status</th>
            <th className={`${th} text-right`}>Actions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-slate-700">
          {rows.map((row) => {
            const overdue = isOverdue(row);
            const owner = row.subsection_name || row.section_name || row.department_name || 'Organisation';
            return (
              <tr key={row.id} className="hover:bg-gray-50/70 dark:hover:bg-slate-700/30">
                <td className={`${td} max-w-md`}>
                  <p className="font-medium text-gray-800 dark:text-gray-100">{row.objective}</p>
                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <span className="rounded bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-300">
                      {levelLabel(row.level)}
                    </span>
                    {row.parent_objective_id ? (
                      <span title={`Cascaded from: ${row.parent_objective ?? `#${row.parent_objective_id}`}`}
                        className="inline-flex items-center gap-1 rounded bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:text-indigo-300">
                        <GitBranch className="h-3 w-3" />Cascaded
                      </span>
                    ) : (
                      <span className="rounded bg-teal-50 dark:bg-teal-900/40 px-1.5 py-0.5 text-[10px] font-medium text-teal-700 dark:text-teal-300">Local</span>
                    )}
                    {(row.children_count ?? 0) > 0 && (
                      <span className="rounded bg-purple-50 dark:bg-purple-900/40 px-1.5 py-0.5 text-[10px] font-medium text-purple-700 dark:text-purple-300">
                        {row.children_count} cascaded down
                      </span>
                    )}
                    {row.contract_name && (
                      <span className="text-[11px] text-gray-400 dark:text-gray-500 truncate max-w-[180px]">{row.contract_name}</span>
                    )}
                  </div>
                </td>
                <td className={td}>
                  <p className="text-gray-700 dark:text-gray-200">{row.kpi}</p>
                  {row.measure_unit && <p className="text-xs text-gray-400 dark:text-gray-500">Unit: {row.measure_unit}</p>}
                </td>
                <td className={td}>
                  <p className="text-gray-700 dark:text-gray-200">{owner}</p>
                  {showOfficer && row.officer_name && (
                    <p className="text-xs text-gray-400 dark:text-gray-500">{row.officer_name}</p>
                  )}
                </td>
                <td className={td}>
                  <p className="text-gray-600 dark:text-gray-300 whitespace-nowrap">{fmtDate(row.planned_end_date)}</p>
                  {overdue ? (
                    <Badge variant="danger">Overdue</Badge>
                  ) : row.planned_start_date ? (
                    <p className="text-xs text-gray-400 dark:text-gray-500">from {fmtDate(row.planned_start_date)}</p>
                  ) : null}
                </td>
                <td className={td}>
                  <ProgressControl row={row} canManage={canManage} onSave={onSaveProgress} />
                </td>
                <td className={`${td} text-right whitespace-nowrap`}>
                  <div className="inline-flex items-center gap-1">
                    {onCascade && canManage && (
                      <button onClick={() => onCascade(row)} title="Cascade downward"
                        className="rounded p-1.5 text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/30">
                        <GitBranch className="h-4 w-4" />
                      </button>
                    )}
                    {onEdit && canManage && (
                      <button onClick={() => onEdit(row)} title="Edit activity"
                        className="rounded p-1.5 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/30">
                        <Pencil className="h-4 w-4" />
                      </button>
                    )}
                    <button onClick={() => onTrace(row)} title="Trace lineage"
                      className="rounded p-1.5 text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/30">
                      <Network className="h-4 w-4" />
                    </button>
                    <button onClick={() => onHistory(row)} title="Progress history"
                      className="rounded p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-slate-700">
                      <History className="h-4 w-4" />
                    </button>
                    {onDelete && canManage && (
                      <button onClick={() => onDelete(row)} title="Delete activity"
                        className="rounded p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                        <Trash2 className="h-4 w-4" />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

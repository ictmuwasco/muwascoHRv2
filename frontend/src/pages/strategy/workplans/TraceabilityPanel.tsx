import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Badge from '../../../components/ui/Badge';
import { ArrowDown, Network } from 'lucide-react';
import { workplanService } from '../../../api/services/workplanService';
import type { TraceabilityNode, TraceabilityResponse } from '../../../api/services/workplanService';
import { fmtDate, levelLabel, statusMeta } from './workplanMeta';

interface Props {
  objectiveId: number | null;
  onClose(): void;
}

function LineageCard({ node, highlight }: { node: TraceabilityNode; highlight?: boolean }) {
  const meta = statusMeta(node.status);
  return (
    <div className={`rounded-lg border px-3 py-2 ${highlight
      ? 'border-primary-300 dark:border-primary-700 bg-primary-50/70 dark:bg-primary-900/20'
      : 'border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800'}`}>
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm font-medium text-gray-800 dark:text-gray-100">{node.objective}</p>
        <Badge variant={meta.variant}>{meta.label}</Badge>
      </div>
      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
        <span className="rounded bg-gray-100 dark:bg-slate-700 px-1.5 py-0.5 font-medium uppercase tracking-wide text-gray-500 dark:text-gray-300">
          {levelLabel(node.level)}
        </span>
        {node.owner && <span>Unit: {node.owner}</span>}
        {node.contract_name && <span>Contract: {node.contract_name}</span>}
        <span>{node.progress_percent}% complete</span>
        {(node as any).planned_end_date && <span>Due {fmtDate((node as any).planned_end_date)}</span>}
        {(node as any).officer_name && <span>Officer: {(node as any).officer_name}</span>}
      </div>
      <div className="mt-1.5 h-1 rounded-full bg-gray-200 dark:bg-slate-600 overflow-hidden">
        <div className="h-full bg-primary-500" style={{ width: `${node.progress_percent}%` }} />
      </div>
    </div>
  );
}

/** Recursive descendant tree under the focused activity. */
function DescendantTree({ nodes, depth = 0 }: { nodes: TraceabilityNode[]; depth?: number }) {
  if (nodes.length === 0) return null;
  return (
    <ul className={depth === 0 ? 'space-y-2' : 'mt-2 ml-4 space-y-2 border-l-2 border-dashed border-gray-200 dark:border-slate-600 pl-3'}>
      {nodes.map((child) => (
        <li key={child.id}>
          <LineageCard node={child} />
          {child.children && child.children.length > 0 && (
            <DescendantTree nodes={child.children} depth={depth + 1} />
          )}
        </li>
      ))}
    </ul>
  );
}

/**
 * Drill-down lineage view for one activity:
 * Strategic Plan → Goal → Target → Contract → ancestors → this activity →
 * the full descendant tree down to employee tasks.
 */
export default function TraceabilityPanel({ objectiveId, onClose }: Props) {
  const [data, setData] = useState<TraceabilityResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (objectiveId === null) { setData(null); setError(''); return; }
    let cancelled = false;
    setLoading(true);
    setError('');
    workplanService.traceability(objectiveId)
      .then((res) => { if (!cancelled) setData(res.data ?? null); })
      .catch((err: any) => { if (!cancelled) setError(err.response?.data?.message || 'Failed to load traceability.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [objectiveId]);

  const ctx = data?.context;

  const chainTop = ctx ? [
    { label: 'Strategic Plan', value: ctx.strategic_plan },
    { label: 'Organisation Goal', value: ctx.goal },
    { label: 'Strategic Target', value: ctx.target },
    {
      label: 'Performance Contract',
      value: ctx.performance_contract
        ? `${ctx.performance_contract}${ctx.financial_year ? ` · FY ${ctx.financial_year}` : ''}`
        : null,
    },
  ].filter((c) => c.value) : [];

  return (
    <Modal isOpen={objectiveId !== null} onClose={onClose} title="Activity Lineage & Traceability" size="xl">
      {loading && (
        <div className="flex items-center justify-center py-10">
          <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600" />
        </div>
      )}

      {!loading && error && (
        <div className="rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300">{error}</div>
      )}

      {!loading && data && (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2">
            {chainTop.map((c) => (
              <span key={c.label}
                className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-blue-50 dark:bg-blue-900/30 px-3 py-1 text-xs font-medium text-blue-800 dark:text-blue-200">
                <Network className="h-3 w-3 flex-none" />
                <span className="opacity-70">{c.label}:</span>
                <span className="truncate">{c.value}</span>
              </span>
            ))}
            {ctx?.department && (
              <span className="rounded-full bg-gray-100 dark:bg-slate-700 px-3 py-1 text-xs font-medium text-gray-600 dark:text-gray-300">
                Owning Department: {ctx.department}
              </span>
            )}
          </div>

          {data.ancestors.length > 0 && (
            <div className="space-y-1">
              <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">Cascaded from</p>
              {data.ancestors.map((a, i) => (
                <div key={a.id}>
                  <LineageCard node={a} />
                  <div className="flex justify-center py-0.5"><ArrowDown className="h-4 w-4 text-gray-300 dark:text-slate-500" /></div>
                </div>
              ))}
            </div>
          )}

          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-primary-500">This activity</p>
            <LineageCard node={{
              id: data.objective.id,
              objective: data.objective.objective,
              level: data.objective.level,
              status: data.objective.status,
              progress_percent: data.objective.progress_percent,
              owner: data.objective.owner,
            } as TraceabilityNode} highlight />
          </div>

          <div>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-purple-500">
              Cascaded down to ({countDescendants(data.descendants)} activities)
            </p>
            {data.descendants.length === 0 ? (
              <p className="text-sm text-gray-500 dark:text-gray-400">
                Nothing has been cascaded from this activity yet.
              </p>
            ) : (
              <DescendantTree nodes={data.descendants} />
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}

function countDescendants(nodes: TraceabilityNode[]): number {
  return nodes.reduce((acc, n) => acc + 1 + countDescendants(n.children ?? []), 0);
}

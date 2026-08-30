import { useEffect, useState } from 'react';
import Modal from '../../../components/ui/Modal';
import Badge from '../../../components/ui/Badge';
import { workplanService } from '../../../api/services/workplanService';
import type { ProgressHistoryUpdate } from '../../../api/services/workplanService';
import { fmtDate, statusMeta } from './workplanMeta';

interface Props {
  objectiveId: number | null;
  objectiveTitle?: string | null;
  onClose(): void;
}

/**
 * Chronological progress / status / cascade history for one activity,
 * sourced from the dedicated `workplan_logs` audit trail.
 */
export default function HistoryModal({ objectiveId, objectiveTitle, onClose }: Props) {
  const [updates, setUpdates] = useState<ProgressHistoryUpdate[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (objectiveId === null) { setUpdates([]); setError(''); return; }
    let cancelled = false;
    setLoading(true);
    setError('');
    workplanService.progressHistory(objectiveId)
      .then((res) => { if (!cancelled) setUpdates(res.data?.updates ?? []); })
      .catch((err: any) => { if (!cancelled) setError(err.response?.data?.message || 'Failed to load history.'); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [objectiveId]);

  return (
    <Modal isOpen={objectiveId !== null} onClose={onClose}
      title={`Progress History${objectiveTitle ? ` — ${objectiveTitle.slice(0, 60)}` : ''}`} size="lg">
      {loading && (
        <div className="flex items-center justify-center py-10">
          <div className="animate-spin rounded-full h-10 w-10 border-b-2 border-primary-600" />
        </div>
      )}

      {!loading && error && (
        <div className="rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-700 dark:text-red-300">{error}</div>
      )}

      {!loading && !error && updates.length === 0 && (
        <p className="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No updates recorded yet.</p>
      )}

      {!loading && updates.length > 0 && (
        <ol className="relative border-l border-gray-200 dark:border-slate-600 ml-3 space-y-4">
          {updates.map((h) => {
            const meta = h.status ? statusMeta(h.status) : null;
            return (
              <li key={h.id} className="ml-4">
                <span className="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-white dark:border-slate-800 bg-primary-500" />
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-sm font-medium capitalize text-gray-800 dark:text-gray-100">
                    {(h.action_type || 'update').replace(/_/g, ' ')}
                  </span>
                  {meta && <Badge variant={meta.variant}>{meta.label}</Badge>}
                  {h.progress_percent !== null && h.progress_percent !== undefined && (
                    <span className="text-xs text-gray-500 dark:text-gray-400">{h.progress_percent}%</span>
                  )}
                  <span className="text-xs text-gray-400 dark:text-gray-500">
                    {fmtDate(h.created_at)}{h.actor_name ? ` · ${h.actor_name}` : ''}
                  </span>
                </div>
                {h.description && (
                  <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{h.description}</p>
                )}
              </li>
            );
          })}
        </ol>
      )}
    </Modal>
  );
}

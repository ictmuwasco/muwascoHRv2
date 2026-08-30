import { useCallback, useEffect, useRef, useState } from 'react';
import {
  workplanService,
  type WorkplanList,
  type WorkplanSummary,
} from '../../../api/services/workplanService';

export type TierView = 'md' | 'department' | 'section' | 'subsection';

/**
 * Shared data plumbing for every workplan tier page: role-scoped activity
 * list (paginated + filtered) plus the dashboard aggregates, with reload
 * support after create/edit/cascade mutations.
 */
export function useWorkplanTier(view: TierView) {
  const [list, setList] = useState<WorkplanList | null>(null);
  const [summary, setSummary] = useState<WorkplanSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [status, setStatus] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [parentFilter, setParentFilter] = useState('');
  const [fyId, setFyId] = useState('');
  const [page, setPage] = useState(1);

  // Guard against out-of-order responses when filters / pages change quickly:
  // only the most recently started request may commit list / summary state.
  const requestIdRef = useRef(0);

  const load = useCallback(async () => {
    const requestId = ++requestIdRef.current;
    setLoading(true);
    setError('');
    try {
      const params: Record<string, any> = { view, page, per_page: 20 };
      if (status) params.status = status;
      if (search) params.q = search;
      if (parentFilter) params.parent_id = parentFilter;
      if (fyId) params.financial_year_id = fyId;
      const [listRes, sumRes] = await Promise.all([
        workplanService.list(params),
        workplanService.summary(view, fyId || undefined),
      ]);
      if (requestId !== requestIdRef.current) return; // a newer request has started
      setList(listRes.data ?? null);
      setSummary(sumRes.data ?? null);
    } catch (err: any) {
      if (requestId !== requestIdRef.current) return;
      setError(err.response?.data?.message || 'Failed to load the workplan.');
    } finally {
      if (requestId === requestIdRef.current) setLoading(false);
    }
  }, [view, status, search, parentFilter, fyId, page]);

  useEffect(() => {
    load();
  }, [load]);

  // Any filter change restarts pagination from the first page.
  useEffect(() => {
    setPage(1);
  }, [status, search, parentFilter, fyId]);

  const applySearch = () => setSearch(searchInput.trim());
  const clearNotice = () => setNotice('');

  return {
    list,
    summary,
    loading,
    error,
    notice,
    setNotice,
    setError,
    status,
    setStatus,
    searchInput,
    setSearchInput,
    applySearch,
    parentFilter,
    setParentFilter,
    fyId,
    setFyId,
    page,
    setPage,
    reload: load,
  };
}

/** Download the caller-scoped workplan CSV export. */
export async function downloadWorkplanCsv(): Promise<void> {
  const blob = await workplanService.exportCsv();
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `workplans_${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  window.URL.revokeObjectURL(url);
}

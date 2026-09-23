/**
 * Shared, session-scoped cache for strategy reference data.
 *
 * Previously every TierWorkplanPage mount fetched /strategic-plans and
 * /performance-contracts separately, and every modal re-fetched
 * /appraisal-cycles on open. This hook fetches once and memoizes for the
 * lifetime of the browser tab (with a lightweight staleness check).
 *
 * All reference data is keyed to the authenticated user's session — it is
 * never shared across accounts, even in a shared-tab scenario.
 */
import { useEffect, useState } from 'react';
import apiClient from '../../../api/client';
import { appraisalCycleService } from '../../../api/services/appraisalCycleService';
import type { AppraisalCycle, FinancialYearRef } from '../../../api/services/appraisalCycleService';

export interface StrategyReference {
  contracts: {
    id: number;
    name: string;
    goal_id: number;
    target_id: number | null;
    department_id?: number | null;
    department_name?: string | null;
  }[];
  goals: { id: number; name: string }[];
  targets: { id: number; name: string; goal_id?: number }[];
  departments: { id: number; name: string }[];
  financial_years: { id: number; year_name: string }[];
  cycles: AppraisalCycle[];
  financial_year_refs: FinancialYearRef[];
}

// Module-level cache — lives for the browser tab session.
let cachedReference: StrategyReference | null = null;
let cachedPromise: Promise<StrategyReference> | null = null;
const STALE_MS = 10 * 60 * 1000; // 10 minutes
let cachedAt = 0;

function useStrategyReference() {
  const [data, setData] = useState<StrategyReference | null>(cachedReference);
  const [loading, setLoading] = useState(!cachedReference);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (cachedReference && Date.now() - cachedAt < STALE_MS) {
      return; // fresh cache
    }
    // Reuse in-flight promise (dedupe concurrent callers).
    const promise =
      cachedPromise ??
      (async () => {
        const [spRes, pcRes, cycRes] = await Promise.all([
          apiClient.get('/strategic-plans'),
          apiClient.get('/performance-contracts'),
          appraisalCycleService.list(),
        ]);
        const sd = spRes.data?.data ?? {};
        const cycData = cycRes.data ?? {};
        return {
          contracts: (pcRes.data?.data?.contracts ?? []).map((c: any) => ({
            id: c.id,
            name: c.name,
            goal_id: c.goal_id,
            target_id: c.target_id,
            department_id: c.department_id ?? null,
            department_name: c.department_name ?? null,
          })),
          goals: sd.goals ?? [],
          targets: sd.targets ?? [],
          departments: sd.departments ?? [],
          financial_years: sd.financial_years ?? [],
          financial_year_refs: cycData.financial_years ?? [],
          cycles: cycData.cycles ?? [],
        } as StrategyReference;
      })();
    cachedPromise = promise;
    setLoading(true);
    setError(null);
    promise
      .then((ref) => {
        cachedReference = ref;
        cachedAt = Date.now();
        cachedPromise = null;
        setData(ref);
      })
      .catch((err: any) => {
        setError(err?.message || 'Failed to load reference data.');
      })
      .finally(() => setLoading(false));
  }, []);

  return {
    data,
    loading,
    error,
    refetch: () => {
      cachedReference = null;
      cachedPromise = null;
      cachedAt = 0;
      setData(null);
      setError(null);
    },
  };
}

export default useStrategyReference;

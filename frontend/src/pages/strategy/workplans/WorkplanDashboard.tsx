import { Target } from 'lucide-react';
import type { WorkplanSummary } from '../../../api/services/workplanService';
import { fmtDate } from './workplanMeta';

function StatCard({ label, value, tone = '', hint }: {
  label: string; value: string | number; tone?: string; hint?: string;
}) {
  return (
    <div className="rounded-lg border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${tone || 'text-gray-900 dark:text-gray-100'}`}>{value}</p>
      {hint && <p className="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{hint}</p>}
    </div>
  );
}

/**
 * Dashboard header for one workplan tier: workplan period, organisational
 * unit, completion gauge, status breakdown, the cascaded-vs-local split and
 * budget allocation.
 */
export default function WorkplanDashboard({ summary }: { summary: WorkplanSummary | null }) {
  if (!summary) return null;
  const t = summary.totals;
  const fy = summary.active_financial_year;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <span className="inline-flex items-center gap-1.5 font-medium text-gray-700 dark:text-gray-200">
          <Target className="h-4 w-4 text-primary-600" />{summary.unit_label}
        </span>
        {fy && (
          <span className="text-gray-500 dark:text-gray-400">
            Workplan period: <strong className="text-gray-700 dark:text-gray-200">{fy.year_name}</strong>{' '}
            ({fmtDate(fy.start_date)} â€“ {fmtDate(fy.end_date)})
          </span>
        )}
      </div>

      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div className="rounded-lg border border-primary-200 dark:border-primary-900/50 bg-primary-50 dark:bg-primary-900/20 p-4 col-span-2 md:col-span-1">
          <p className="text-xs font-medium uppercase tracking-wide text-primary-700 dark:text-primary-300">Completion</p>
          <p className="mt-1 text-2xl font-bold text-primary-700 dark:text-primary-200">{t.completion_rate}%</p>
          <div className="mt-2 h-1.5 rounded-full bg-primary-100 dark:bg-primary-900/60 overflow-hidden">
            <div className="h-full bg-primary-600" style={{ width: `${Math.min(100, t.completion_rate)}%` }} />
          </div>
          <p className="mt-1 text-xs text-primary-600/80 dark:text-primary-300/70">{t.total_activities} activities</p>
        </div>
        <StatCard label="Completed" value={t.completed} tone="text-green-600 dark:text-green-400"
          hint={`${t.total_activities ? Math.round((t.completed / Math.max(1, t.total_activities)) * 100) : 0}% of total`} />
        <StatCard label="In Progress" value={t.in_progress} tone="text-blue-600 dark:text-blue-400" />
        <StatCard label="Delayed" value={t.at_risk + t.off_track} tone="text-amber-600 dark:text-amber-400"
          hint={`${t.at_risk} at risk Â· ${t.off_track} off track`} />
        <StatCard label="Overdue" value={t.overdue_count} tone="text-red-600 dark:text-red-400" hint="Past end date" />
        <StatCard label="Awaiting Action" value={t.awaiting_action} tone="text-purple-600 dark:text-purple-400"
          hint="Cascaded, not started" />
      </div>

      <div className="flex flex-wrap items-center gap-3 text-xs">
        <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 dark:bg-indigo-900/30 px-3 py-1 font-medium text-indigo-700 dark:text-indigo-300">
          Cascaded from supervisor: <strong>{t.cascaded_count}</strong>
        </span>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-teal-50 dark:bg-teal-900/30 px-3 py-1 font-medium text-teal-700 dark:text-teal-300">
          Locally created: <strong>{t.local_count}</strong>
        </span>
        {t.budget_total > 0 && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 dark:bg-slate-700 px-3 py-1 font-medium text-gray-600 dark:text-gray-300">
            Budget allocated: KES {Number(t.budget_total).toLocaleString()}
          </span>
        )}
      </div>
    </div>
  );
}

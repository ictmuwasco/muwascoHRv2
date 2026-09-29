import { useState } from 'react';
import { Award, BarChart3, ChevronDown, ChevronUp, TrendingUp, Users } from 'lucide-react';
import Card from '../ui/Card';
import type { AnalyticsUnit, AppraisalAnalytics, FilterScopeLevel } from '../../api/services/appraisalReportService';

/**
 * Which unit breakdowns are worth showing for a given scope tier.
 *
 * A section head's "by department" chart has exactly one bar - their own
 * department - and says nothing the headline score does not already say. Only
 * the tiers BELOW the caller's own are informative, so the list is keyed off the
 * scope level the server already reports.
 */
const BREAKDOWNS: Record<FilterScopeLevel, Array<{ key: 'departments' | 'sections' | 'subsections'; label: string }>> = {
  organisation: [
    { key: 'departments', label: 'By department' },
    { key: 'sections', label: 'By section' },
    { key: 'subsections', label: 'By subsection' },
  ],
  department: [
    { key: 'sections', label: 'By section' },
    { key: 'subsections', label: 'By subsection' },
  ],
  section: [{ key: 'subsections', label: 'By subsection' }],
  // Owns one subsection: there is nothing beneath it to break down.
  subsection: [],
};

/** Same red/amber/green bands as the rest of the appraisal UI. */
const analyticsBand = (pct: number) =>
  pct < 50
    ? 'text-red-600 dark:text-red-400'
    : pct < 75
      ? 'text-amber-600 dark:text-amber-400'
      : 'text-green-600 dark:text-green-400';

const barColour = (pct: number) =>
  pct < 50 ? 'bg-red-500' : pct < 75 ? 'bg-amber-500' : 'bg-green-500';

/** Headline score with its raw totals underneath. */
const Overall = ({ analytics }: { analytics: AppraisalAnalytics }) => {
  const { percentage, total_score, total_max_score } = analytics.overall;
  return (
    <div className="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
      <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Overall score</p>
      {percentage === null ? (
        <p className="mt-1 text-sm text-gray-500">Nothing in scope has been scored yet.</p>
      ) : (
        <>
          <p className={`mt-1 text-3xl font-bold ${analyticsBand(percentage)}`}>{percentage}%</p>
          <p className="mt-1 text-xs text-gray-500">
            {total_score} of {total_max_score} points
          </p>
        </>
      )}
      <p className="mt-2 text-xs text-gray-400">
        {analytics.appraisals} appraisal{analytics.appraisals === 1 ? '' : 's'} in view
        {analytics.scored < analytics.appraisals && ` · ${analytics.appraisals - analytics.scored} not yet scored`}
      </p>
    </div>
  );
};

/** A single "best performing X" callout. */
const Best = ({
  icon,
  title,
  unit,
  empty,
}: {
  icon: React.ReactNode;
  title: string;
  unit: AnalyticsUnit | null;
  empty: string;
}) => (
  <div className="rounded-lg border border-gray-200 p-4 dark:border-slate-700">
    <p className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">
      <span className="text-gray-400">{icon}</span>
      {title}
    </p>
    {unit ? (
      <>
        <p className="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-gray-100" title={unit.name}>
          {unit.name}
        </p>
        {unit.detail && <p className="text-xs text-gray-400">Staff No {unit.detail}</p>}
        <p className={`mt-1 text-lg font-bold ${analyticsBand(unit.percentage)}`}>{unit.percentage}%</p>
        <p className="text-xs text-gray-500">
          across {unit.count} appraisal{unit.count === 1 ? '' : 's'}
        </p>
      </>
    ) : (
      <p className="mt-1 text-sm text-gray-500">{empty}</p>
    )}
  </div>
);


/** One ranked unit, with a proportional bar so tiers are comparable at a glance. */
const UnitRow = ({ unit, best }: { unit: AnalyticsUnit; best: number }) => (
  <li className="py-2">
    <div className="flex items-baseline justify-between gap-3">
      <span className="truncate text-sm text-gray-700 dark:text-gray-300" title={unit.name}>
        {unit.name}
        {unit.detail && <span className="ml-1 text-xs text-gray-400">({unit.detail})</span>}
      </span>
      <span className={`shrink-0 text-sm font-semibold ${analyticsBand(unit.percentage)}`}>{unit.percentage}%</span>
    </div>
    <div className="mt-1 flex items-center gap-2">
      {/* Scaled against the top unit, not 100%, so a tier where everyone scores
          60% still shows the spread between its own best and worst. */}
      <div className="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-slate-700">
        <div
          className={`h-full rounded-full ${barColour(unit.percentage)}`}
          style={{ width: `${best > 0 ? Math.max(2, (unit.percentage / best) * 100) : 0}%` }}
        />
      </div>
      <span className="w-16 shrink-0 text-right text-xs text-gray-400">
        {unit.count} row{unit.count === 1 ? '' : 's'}
      </span>
    </div>
  </li>
);

const Breakdown = ({ label, units }: { label: string; units: AnalyticsUnit[] }) => {
  if (!units.length) return null;
  // Bars are relative to the leader in THIS list, so each tier is judged on its
  // own spread rather than against an unrelated number.
  const best = Math.max(...units.map((u) => u.percentage));
  return (
    <div>
      <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">{label}</h3>
      <ul className="mt-1 divide-y divide-gray-100 dark:divide-slate-700/60">
        {units.map((u) => (
          <UnitRow key={`${u.id ?? 'n'}-${u.name}`} unit={u} best={best} />
        ))}
      </ul>
    </div>
  );
};

interface Props {
  analytics: AppraisalAnalytics | null;
  loading: boolean;
  error: string;
  scopeLevel: FilterScopeLevel;
  /** Start expanded: the analytics are the point, not a footnote. */
  defaultOpen?: boolean;
}

const CompletedAppraisalAnalytics = ({ analytics, loading, error, scopeLevel, defaultOpen = true }: Props) => {
  const [open, setOpen] = useState(defaultOpen);

  // A subsection head gets no breakdown lists at all, so the panel is only worth
  // showing for the headline plus the two "best" callouts.
  const breakdowns = BREAKDOWNS[scopeLevel] ?? [];

  return (
    <Card>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 text-left"
      >
        <BarChart3 className="h-4 w-4 text-gray-500" aria-hidden="true" />
        <span className="text-sm font-medium text-gray-900 dark:text-gray-100">Performance analytics</span>
        {open ? (
          <ChevronUp className="ml-auto h-4 w-4 text-gray-400" aria-hidden="true" />
        ) : (
          <ChevronDown className="ml-auto h-4 w-4 text-gray-400" aria-hidden="true" />
        )}
      </button>

      {open && (
        <div className="mt-4 space-y-4">
          {error ? (
            <p className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
          ) : loading || !analytics ? (
            <p className="py-6 text-center text-sm text-gray-500">Loading analytics…</p>
          ) : !analytics.available ? (
            <p className="text-sm text-gray-500">
              Performance analytics cover a whole unit, so they are not available for your own appraisals.
            </p>
          ) : (
            <>
              {/* Honest about partial data: better to say the figures cover the
                  first 100 rows than to present them as organisation-wide. */}
              {analytics.truncated && (
                <p className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700">
                  More records match than can be aggregated at once, so these figures cover the most recent 100.
                  Narrow the filters for an exact total.
                </p>
              )}

              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <Overall analytics={analytics} />
                <Best
                  icon={<Award className="h-3.5 w-3.5" />}
                  title="Best performing employee"
                  unit={analytics.top_employee}
                  empty="No scored appraisals in scope yet."
                />
                <Best
                  icon={<TrendingUp className="h-3.5 w-3.5" />}
                  title="Best performing quarter"
                  unit={analytics.top_cycle}
                  empty="No scored cycles in scope yet."
                />
              </div>

              {breakdowns.length > 0 && (
                <div className="grid grid-cols-1 gap-6 border-t border-gray-200 pt-4 md:grid-cols-2 dark:border-slate-700">
                  {breakdowns.map((b) => (
                    <Breakdown key={b.key} label={b.label} units={analytics[b.key]} />
                  ))}
                </div>
              )}

              <p className="flex items-start gap-1.5 text-xs text-gray-400">
                <Users className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                Averages are weighted by points scored (total score ÷ total possible), not an average of
                per-appraisal percentages. Units with no scored appraisals are omitted.
              </p>
            </>
          )}
        </div>
      )}
    </Card>
  );
};

export default CompletedAppraisalAnalytics;

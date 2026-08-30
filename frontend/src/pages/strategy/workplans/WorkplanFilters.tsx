import Button from '../../../components/ui/Button';
import { Search } from 'lucide-react';
import type { ReactNode } from 'react';

const STATUS_OPTIONS = [
  { value: '', label: 'All Statuses' },
  { value: 'not_started', label: 'Not Started' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'at_risk', label: 'At Risk' },
  { value: 'off_track', label: 'Off Track' },
];

export const PARENT_FILTER_OPTIONS = [
  { value: '', label: 'All Activities' },
  { value: 'any', label: 'Cascaded to me' },
  { value: 'none', label: 'Locally created' },
];

interface Props {
  status: string;
  onStatusChange(v: string): void;
  searchInput: string;
  onSearchInputChange(v: string): void;
  onApplySearch(): void;
  parentFilter?: string;
  onParentFilterChange?(v: string): void;
  financialYearId?: string;
  onFinancialYearChange?(v: string): void;
  financialYears?: { id: number; year_name: string }[];
  actions?: ReactNode;
}

/** Toolbar row shared by all four tier pages. */
export default function WorkplanFilters({
  status, onStatusChange, searchInput, onSearchInputChange, onApplySearch,
  parentFilter, onParentFilterChange, financialYearId, onFinancialYearChange,
  financialYears, actions,
}: Props) {
  const selectCls =
    'rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-gray-700 dark:text-gray-200';

  return (
    <div className="flex flex-col lg:flex-row lg:items-center gap-3 justify-between">
      <div className="flex flex-wrap items-center gap-2">
        <select className={selectCls} value={status} onChange={(e) => onStatusChange(e.target.value)}>
          {STATUS_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>

        {onParentFilterChange && (
          <select className={selectCls} value={parentFilter ?? ''} onChange={(e) => onParentFilterChange(e.target.value)}>
            {PARENT_FILTER_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        )}

        {onFinancialYearChange && financialYears && financialYears.length > 0 && (
          <select className={selectCls} value={financialYearId ?? ''} onChange={(e) => onFinancialYearChange(e.target.value)}>
            <option value="">All Financial Years</option>
            {financialYears.map((fy) => <option key={fy.id} value={String(fy.id)}>{fy.year_name}</option>)}
          </select>
        )}

        <div className="relative">
          <input
            type="search"
            placeholder="Search activities or KPIs…"
            className="w-64 rounded-md border border-gray-300 dark:border-slate-600 bg-white dark:bg-slate-800 pl-9 pr-3 py-2 text-sm text-gray-700 dark:text-gray-200"
            value={searchInput}
            onChange={(e) => onSearchInputChange(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && onApplySearch()}
          />
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-gray-400" />
        </div>
        <Button variant="outline" onClick={onApplySearch}>Search</Button>
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** Shared presentation metadata for workplan statuses and cascade levels. */

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'primary' | 'default';

export const STATUS_META: Record<
  string,
  { label: string; variant: BadgeVariant }
> = {
  not_started: { label: 'Not Started', variant: 'default' },
  in_progress: { label: 'In Progress', variant: 'primary' },
  completed: { label: 'Completed', variant: 'success' },
  at_risk: { label: 'At Risk', variant: 'warning' },
  off_track: { label: 'Off Track', variant: 'danger' },
};

export const statusMeta = (status: string | null | undefined) =>
  STATUS_META[status ?? 'not_started'] ?? STATUS_META.not_started;

export const LEVEL_LABELS: Record<string, string> = {
  organisation: 'Organisation',
  department: 'Department',
  section: 'Section',
  subsection: 'Subsection',
};

export const levelLabel = (level: string | null | undefined) =>
  LEVEL_LABELS[level ?? ''] ?? level ?? '—';

export const fmtDate = (d: string | null | undefined) =>
  d
    ? new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
    : 'N/A';

/** True when a planned end date has passed without completion. */
export const isOverdue = (row: { planned_end_date?: string | null; status?: string }) => {
  if (!row.planned_end_date || row.status === 'completed') return false;
  return new Date(row.planned_end_date).getTime() < Date.now();
};

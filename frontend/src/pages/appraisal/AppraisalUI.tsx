import type { ReactNode } from 'react';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';
import Badge from '../../components/ui/Badge';
import type { AppraisalIndicator } from '../../api/services/appraisalService';

export const formatAppraisalDate = (value?: string | null) =>
  value ? new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

export const appraisalStatusVariant = (status: string): 'success' | 'warning' | 'danger' | 'default' => {
  if (status === 'completed' || status === 'submitted') return 'success';
  if (status === 'awaiting_employee' || status === 'pending_dept_approval') return 'warning';
  if (status === 'under_review' || status === 'rejected') return 'danger';
  return 'default';
};

export function AppraisalStatus({ status, label }: { status: string; label?: string }) {
  return <Badge variant={appraisalStatusVariant(status)}>{label || status.replace(/_/g, ' ')}</Badge>;
}

export function AppraisalScoreTable({ indicators }: { indicators: AppraisalIndicator[] }) {
  return <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
    <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700 text-sm">
      <thead className="bg-gray-50 dark:bg-slate-900"><tr>{['Activity / KPI', 'Maximum', 'Score', 'Supervisor comment'].map((heading) => <th key={heading} className="px-3 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500">{heading}</th>)}</tr></thead>
      <tbody className="divide-y divide-gray-100 dark:divide-slate-800">{indicators.map((indicator) => <tr key={indicator.id}>
        <td className="px-3 py-3"><p className="font-medium text-gray-800 dark:text-gray-100">{indicator.name}</p><p className="mt-0.5 text-xs text-gray-400">{indicator.activities.map((activity) => activity.name).join(' · ') || 'No linked activity'}</p></td>
        <td className="px-3 py-3 text-gray-600 dark:text-gray-300">{indicator.max_score}</td>
        <td className="px-3 py-3 font-semibold text-gray-900 dark:text-gray-100">{indicator.score ?? '—'}</td>
        <td className="px-3 py-3 text-gray-600 dark:text-gray-300">{indicator.appraiser_comment || 'No comment'}</td>
      </tr>)}</tbody>
    </table>
  </div>;
}

export function AppraisalNotice({ type, children }: { type: 'info' | 'success' | 'warning' | 'error'; children: ReactNode }) {
  const classes = { info: 'border-blue-200 bg-blue-50 text-blue-800 dark:bg-blue-900/20 dark:text-blue-200', success: 'border-green-200 bg-green-50 text-green-800 dark:bg-green-900/20 dark:text-green-200', warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:bg-amber-900/20 dark:text-amber-200', error: 'border-red-200 bg-red-50 text-red-800 dark:bg-red-900/20 dark:text-red-200' };
  const Icon = type === 'success' ? CheckCircle2 : type === 'error' || type === 'warning' ? AlertTriangle : CheckCircle2;
  return <div className={`flex items-start gap-2 rounded-lg border p-3 text-sm ${classes[type]}`}><Icon className="mt-0.5 h-4 w-4 shrink-0" /> <div>{children}</div></div>;
}

export function FeedbackForm({ value, satisfied, saving, onChange, onSatisfied, onSubmit, onCancel }: { value: string; satisfied: number; saving: boolean; onChange: (value: string) => void; onSatisfied: (value: number) => void; onSubmit: () => void; onCancel: () => void }) {
  return <div className="space-y-4 rounded-lg border border-primary-200 bg-primary-50/40 p-4 dark:border-primary-900 dark:bg-primary-900/10">
    <div><h3 className="font-semibold text-gray-900 dark:text-gray-100">Your feedback is required</h3><p className="mt-1 text-sm text-gray-500">Tell us whether you are satisfied and share any comments.</p></div>
    <div className="grid gap-3 md:grid-cols-2">
      <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${satisfied === 1 ? 'border-green-400 bg-green-50 dark:bg-green-900/20' : 'border-gray-200 dark:border-slate-700'}`}><input type="radio" className="mt-1 accent-green-600" checked={satisfied === 1} onChange={() => onSatisfied(1)} /><span><span className="flex items-center gap-1 font-medium text-green-700 dark:text-green-300"><CheckCircle2 className="h-4 w-4" /> Satisfied</span><span className="mt-1 block text-xs text-gray-500">The appraisal will proceed for approval.</span></span></label>
      <label className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${satisfied === 0 ? 'border-red-400 bg-red-50 dark:bg-red-900/20' : 'border-gray-200 dark:border-slate-700'}`}><input type="radio" className="mt-1 accent-red-600" checked={satisfied === 0} onChange={() => onSatisfied(0)} /><span><span className="flex items-center gap-1 font-medium text-red-700 dark:text-red-300"><XCircle className="h-4 w-4" /> Not satisfied</span><span className="mt-1 block text-xs text-gray-500">Your appraisal will be escalated for review.</span></span></label>
    </div>
    <textarea value={value} onChange={(event) => onChange(event.target.value)} rows={4} className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 dark:border-slate-600 dark:bg-slate-800 dark:text-gray-100" placeholder="Share achievements, development areas, or concerns…" />
    <div className="flex justify-end gap-2"><ButtonLike secondary onClick={onCancel}>Cancel</ButtonLike><button onClick={onSubmit} disabled={saving} className="rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">{saving ? 'Submitting…' : 'Submit feedback'}</button></div>
  </div>;
}

function ButtonLike({ children, onClick, secondary }: { children: ReactNode; onClick: () => void; secondary?: boolean }) {
  return <button onClick={onClick} className={`rounded-md px-4 py-2 text-sm font-medium ${secondary ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-gray-200' : 'bg-primary-600 text-white'}`}>{children}</button>;
}

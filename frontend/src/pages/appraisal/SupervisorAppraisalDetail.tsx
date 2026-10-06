import { useMemo, useState, type ReactNode } from 'react';
import { CheckCircle2, Save, ShieldAlert, XCircle } from 'lucide-react';
import type { AppraisalDetail } from '../../api/services/appraisalService';
import { AppraisalNotice, AppraisalScoreTable, formatAppraisalDate } from './AppraisalUI';

interface Props {
  detail: AppraisalDetail;
  canScore: boolean;
  canApprove: boolean;
  saving: boolean;
  deciding: boolean;
  onSave: (
    scores: Record<number, number>,
    comments: Record<number, string>,
    supervisorComment: string,
  ) => void;
  onDecision: (decision: string, comment: string) => void;
  onClose: () => void;
}

export default function SupervisorAppraisalDetail({
  detail,
  canScore,
  canApprove,
  saving,
  deciding,
  onSave,
  onDecision,
  onClose,
}: Props) {
  const [scores, setScores] = useState<Record<number, string>>(() =>
    Object.fromEntries(
      detail.indicators.map((item) => [item.id, item.score == null ? '' : String(item.score)]),
    ),
  );
  const [comments, setComments] = useState<Record<number, string>>(() =>
    Object.fromEntries(detail.indicators.map((item) => [item.id, item.appraiser_comment || ''])),
  );
  const [supervisorComment, setSupervisorComment] = useState(detail.supervisors_comment || '');
  const [decision, setDecision] = useState('approve');
  const [decisionComment, setDecisionComment] = useState('');
  const total = useMemo(
    () => detail.indicators.reduce((sum, item) => sum + (Number(scores[item.id]) || 0), 0),
    [detail.indicators, scores],
  );
  const max = useMemo(
    () => detail.indicators.reduce((sum, item) => sum + Number(item.max_score), 0),
    [detail.indicators],
  );
  const editable =
    canScore &&
    detail.can_edit_scores === true &&
    ['draft', 'awaiting_employee'].includes(detail.status);
  const reviewable =
    canApprove && ['pending_dept_approval', 'under_review'].includes(detail.status);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-slate-700 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {detail.employee_name}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {detail.employee_code} · {detail.employee_type?.replace(/_/g, ' ')}
          </p>
          <p className="mt-1 text-xs text-gray-400">
            {detail.cycle_name} · {formatAppraisalDate(detail.start_date)} –{' '}
            {formatAppraisalDate(detail.end_date)}
          </p>
        </div>
        <div className="text-left sm:text-right">
          <p className="text-2xl font-bold text-primary-600">
            {max > 0 ? ((total / max) * 100).toFixed(1) : '0.0'}%
          </p>
          <p className="text-xs text-gray-400">
            {total} / {max} points
          </p>
        </div>
      </div>
      {detail.employee_comment && (
        <AppraisalNotice type={Number(detail.employee_satisfied) === 0 ? 'warning' : 'info'}>
          <strong>Employee feedback:</strong>
          <br />
          {detail.employee_comment}
          <br />
          <span className="text-xs">
            {detail.employee_satisfied
              ? 'Employee marked the appraisal satisfied.'
              : 'Employee marked the appraisal not satisfied.'}
          </span>
        </AppraisalNotice>
      )}
      {editable ? (
        <ScoreEditor
          indicators={detail.indicators}
          scores={scores}
          comments={comments}
          supervisorComment={supervisorComment}
          onScore={(id, value) => setScores((current) => ({ ...current, [id]: value }))}
          onComment={(id, value) => setComments((current) => ({ ...current, [id]: value }))}
          onSupervisorComment={setSupervisorComment}
        />
      ) : (
        <AppraisalScoreTable indicators={detail.indicators} />
      )}
      {editable && (
        <div className="flex justify-end">
          <button
            disabled={saving}
            onClick={() =>
              onSave(
                Object.fromEntries(
                  Object.entries(scores).map(([id, value]) => [Number(id), Number(value)]),
                ),
                comments,
                supervisorComment,
              )
            }
            className="inline-flex items-center rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50"
          >
            <Save className="mr-2 h-4 w-4" />
            {saving ? 'Saving…' : 'Save scores & notify employee'}
          </button>
        </div>
      )}
      {reviewable && (
        <DecisionPanel
          deciding={deciding}
          decision={decision}
          comment={decisionComment}
          onDecision={setDecision}
          onComment={setDecisionComment}
          onSubmit={() => onDecision(decision, decisionComment)}
        />
      )}
      <div className="flex justify-end border-t border-gray-200 pt-4 dark:border-slate-700">
        <button
          onClick={onClose}
          className="rounded-md border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:text-gray-200"
        >
          Close
        </button>
      </div>
    </div>
  );
}
function ScoreEditor({
  indicators,
  scores,
  comments,
  supervisorComment,
  onScore,
  onComment,
  onSupervisorComment,
}: {
  indicators: AppraisalDetail['indicators'];
  scores: Record<number, string>;
  comments: Record<number, string>;
  supervisorComment: string;
  onScore: (id: number, value: string) => void;
  onComment: (id: number, value: string) => void;
  onSupervisorComment: (value: string) => void;
}) {
  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-slate-700">
      <table className="min-w-full divide-y divide-gray-200 text-sm dark:divide-slate-700">
        <thead className="bg-gray-50 dark:bg-slate-900">
          <tr>
            {['Activity / KPI', 'Maximum', 'Score', 'Comment'].map((heading) => (
              <th
                key={heading}
                className="px-3 py-2 text-left text-xs uppercase tracking-wide text-gray-500"
              >
                {heading}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
          {indicators.map((item) => (
            <tr key={item.id}>
              <td className="px-3 py-3">
                <p className="font-medium">{item.name}</p>
                <p className="text-xs text-gray-400">
                  {item.activities.map((activity) => activity.name).join(' · ') ||
                    'No linked activity'}
                </p>
              </td>
              <td className="px-3 py-3">{item.max_score}</td>
              <td className="px-3 py-3">
                <input
                  type="number"
                  min={0}
                  max={item.max_score}
                  step="0.01"
                  value={scores[item.id] ?? ''}
                  onChange={(event) => onScore(item.id, event.target.value)}
                  className="w-20 rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800"
                />
              </td>
              <td className="px-3 py-3">
                <textarea
                  rows={2}
                  value={comments[item.id] ?? ''}
                  onChange={(event) => onComment(item.id, event.target.value)}
                  className="min-w-[220px] rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800"
                  placeholder="Add KPI comment"
                />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      <div className="border-t border-gray-200 p-4 dark:border-slate-700">
        <label className="text-sm font-medium">
          Supervisor comment
          <span className="text-red-500">*</span>
        </label>
        <textarea
          rows={3}
          value={supervisorComment}
          onChange={(event) => onSupervisorComment(event.target.value)}
          className="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
          placeholder="Write the overall performance comment"
        />
      </div>
    </div>
  );
}

function DecisionPanel({
  deciding,
  decision,
  comment,
  onDecision,
  onComment,
  onSubmit,
}: {
  deciding: boolean;
  decision: string;
  comment: string;
  onDecision: (value: string) => void;
  onComment: (value: string) => void;
  onSubmit: () => void;
}) {
  return (
    <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-900/20">
      <div className="flex items-center gap-2">
        <ShieldAlert className="h-5 w-5 text-amber-700" />
        <h4 className="font-semibold text-amber-900 dark:text-amber-100">Reviewer decision</h4>
      </div>
      <div className="mt-3 grid gap-2 md:grid-cols-3">
        <DecisionButton
          active={decision === 'approve'}
          onClick={() => onDecision('approve')}
          label="Approve"
          icon={<CheckCircle2 className="h-4 w-4" />}
          tone="green"
        />
        <DecisionButton
          active={decision === 'reject'}
          onClick={() => onDecision('reject')}
          label="Reject"
          icon={<XCircle className="h-4 w-4" />}
          tone="red"
        />
        <DecisionButton
          active={decision === 'return_for_revision'}
          onClick={() => onDecision('return_for_revision')}
          label="Return for revision"
          tone="amber"
        />
        <DecisionButton
          active={decision === 'requires_meeting'}
          onClick={() => onDecision('requires_meeting')}
          label="Requires meeting"
          tone="amber"
        />
      </div>
      <textarea
        rows={3}
        value={comment}
        onChange={(event) => onComment(event.target.value)}
        className="mt-3 w-full rounded-md border border-amber-300 px-3 py-2 text-sm dark:border-amber-700 dark:bg-slate-800"
        placeholder="Decision rationale (required for rejection or revision)"
      />
      <div className="mt-3 flex justify-end">
        <button
          disabled={deciding}
          onClick={onSubmit}
          className="rounded-md bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
        >
          {deciding ? 'Recording…' : 'Record decision'}
        </button>
      </div>
    </div>
  );
}

function DecisionButton({
  active,
  onClick,
  label,
  icon,
  tone,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  icon?: ReactNode;
  tone: 'green' | 'red' | 'amber';
}) {
  const colors = {
    green: 'border-green-400 bg-green-50 text-green-800',
    red: 'border-red-400 bg-red-50 text-red-800',
    amber: 'border-amber-400 bg-amber-50 text-amber-800',
  };
  return (
    <button
      onClick={onClick}
      className={`flex items-center justify-center gap-2 rounded-md border p-2 text-sm font-medium ${active ? colors[tone] : 'border-gray-200 text-gray-500'}`}
    >
      {icon}
      {label}
    </button>
  );
}

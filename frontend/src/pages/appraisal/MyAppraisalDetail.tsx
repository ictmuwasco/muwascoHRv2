import type { AppraisalDetail, AppraisalWorkflowRow } from '../../api/services/appraisalService';
import { AppraisalNotice, AppraisalScoreTable, FeedbackForm, formatAppraisalDate } from './AppraisalUI';

interface Props {
  row: AppraisalWorkflowRow;
  detail: AppraisalDetail;
  comment: string;
  satisfied: number;
  saving: boolean;
  onComment: (value: string) => void;
  onSatisfied: (value: number) => void;
  onSubmit: () => void;
  onCancel: () => void;
}

export default function MyAppraisalDetail({ row, detail, comment, satisfied, saving, onComment, onSatisfied, onSubmit, onCancel }: Props) {
  const canFeedback = row.status === 'awaiting_employee' && !row.employee_comment_date;
  return <div className="space-y-5">
    <AppraisalScoreTable indicators={detail.indicators} />
    {detail.supervisors_comment && <div className="rounded-lg bg-blue-50 p-4 dark:bg-blue-900/20"><p className="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Supervisor comment</p><p className="mt-1 whitespace-pre-wrap text-sm text-blue-900 dark:text-blue-100">{detail.supervisors_comment}</p></div>}
    {row.status === 'under_review' && <AppraisalNotice type="warning">Your appraisal was escalated because you indicated that you were not satisfied. A reviewer will assess your feedback and notify you of the outcome.</AppraisalNotice>}
    {row.employee_comment_date && <div className="rounded-lg border border-gray-200 p-4 dark:border-slate-700"><p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Your submitted feedback</p><p className="mt-1 whitespace-pre-wrap text-sm">{row.employee_comment}</p><p className="mt-2 text-xs text-gray-400">Submitted {formatAppraisalDate(row.employee_comment_date)}</p></div>}
    {canFeedback && <FeedbackForm value={comment} satisfied={satisfied} saving={saving} onChange={onComment} onSatisfied={onSatisfied} onSubmit={onSubmit} onCancel={onCancel} />}
  </div>;
}

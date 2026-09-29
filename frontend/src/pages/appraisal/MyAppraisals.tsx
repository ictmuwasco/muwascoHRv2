import { useCallback, useEffect, useState } from 'react';
import { ChevronDown, FileText, RefreshCw } from 'lucide-react';
import Card from '../../components/ui/Card';
import Button from '../../components/ui/Button';
import { appraisalService, type AppraisalDetail, type AppraisalWorkflowRow } from '../../api/services/appraisalService';
import { AppraisalStatus, formatAppraisalDate } from './AppraisalUI';
import MyAppraisalDetail from './MyAppraisalDetail';

export default function MyAppraisals() {
  const [rows, setRows] = useState<AppraisalWorkflowRow[]>([]);
  const [selected, setSelected] = useState<AppraisalDetail | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [comment, setComment] = useState('');
  const [satisfied, setSatisfied] = useState(1);

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try { setRows(await appraisalService.my()); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to load your appraisals.'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  const openDetail = async (id: number) => {
    if (expanded === id) { setExpanded(null); setSelected(null); return; }
    setExpanded(id); setDetailLoading(true); setError('');
    try { setSelected(await appraisalService.myDetail(id)); setComment(''); setSatisfied(1); }
    catch (err: any) { setError(err.response?.data?.message || 'Unable to load appraisal details.'); }
    finally { setDetailLoading(false); }
  };

  const submitFeedback = async (id: number) => {
    if (!comment.trim()) { setError('Please enter your feedback before submitting.'); return; }
    const prompt = satisfied === 0 ? 'Submitting this feedback will escalate your appraisal for management review. Continue?' : 'Submit your feedback and send this appraisal for approval?';
    if (!window.confirm(prompt)) return;
    setSaving(true); setError('');
    try {
      await appraisalService.submitFeedback(id, { employee_comment: comment.trim(), employee_satisfied: satisfied });
      setNotice(satisfied === 0 ? 'Your feedback was submitted and the appraisal was escalated.' : 'Your feedback was submitted successfully.');
      setSelected(null); setExpanded(null); await load();
    } catch (err: any) { setError(err.response?.data?.message || 'Unable to submit feedback.'); }
    finally { setSaving(false); }
  };

  if (loading) return <div className="flex h-64 items-center justify-center"><div className="h-12 w-12 animate-spin rounded-full border-b-2 border-primary-600" /></div>;
  return <div className="space-y-6">
    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"><div><h1 className="text-2xl font-bold text-gray-900 dark:text-gray-100">My Performance Appraisals</h1><p className="text-gray-500 dark:text-gray-400">Review your supervisor's assessment and submit your feedback.</p></div><Button variant="outline" onClick={load}><RefreshCw className="mr-2 h-4 w-4" />Refresh</Button></div>
    {(error || notice) && <div className={`flex items-start gap-2 rounded-lg px-4 py-3 text-sm ${error ? 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300'}`}><span className="flex-1">{error || notice}</span><button onClick={() => { setError(''); setNotice(''); }} className="font-bold">×</button></div>}
    {rows.length === 0 ? <Card><div className="py-12 text-center text-gray-500"><FileText className="mx-auto mb-3 h-10 w-10 text-gray-400" /><h2 className="font-semibold text-gray-900 dark:text-gray-100">No appraisals yet</h2><p className="mt-1 text-sm">Your appraisal will appear here when your supervisor starts the review process.</p></div></Card> : <div className="space-y-4">{rows.map((row) => {
      const open = expanded === row.id;
      return <Card key={row.id} className="overflow-hidden"><button className="flex w-full items-start justify-between gap-4 p-5 text-left" onClick={() => openDetail(row.id)}><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-gray-900 dark:text-gray-100">{row.cycle_name || 'Appraisal cycle'}</h2><AppraisalStatus status={row.status} label={row.status_label} /></div><p className="mt-1 text-sm text-gray-500">{formatAppraisalDate(row.start_date)} – {formatAppraisalDate(row.end_date)}</p><p className="mt-1 text-xs text-gray-400">Supervisor: {row.appraiser_name || '—'}</p></div><div className="flex items-center gap-3"><div className="text-right"><p className="text-lg font-bold text-primary-600">{row.score_percentage || 0}%</p><p className="text-xs text-gray-400">Overall score</p></div><ChevronDown className={`h-5 w-5 text-gray-400 transition-transform ${open ? 'rotate-180' : ''}`} /></div></button>{open && <div className="space-y-5 border-t border-gray-200 p-5 dark:border-slate-700">{detailLoading ? <p className="py-8 text-center text-gray-500">Loading assessment details…</p> : selected?.id === row.id && <MyAppraisalDetail row={row} detail={selected} comment={comment} satisfied={satisfied} saving={saving} onComment={setComment} onSatisfied={setSatisfied} onSubmit={() => submitFeedback(row.id)} onCancel={() => { setExpanded(null); setSelected(null); }} />}</div>}</Card>;
    })}</div>}
  </div>;
}

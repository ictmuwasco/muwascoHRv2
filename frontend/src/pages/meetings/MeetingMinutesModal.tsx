import { useState, useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import toast from 'react-hot-toast';
import {
  FileText,
  Users,
  ListChecks,
  Gavel,
  ClipboardList,
  CheckCircle2,
  Loader2,
  Save,
  Send,
  RotateCcw,
  Plus,
  ArrowUp,
  ArrowDown,
  Trash2,
  AlertTriangle,
} from 'lucide-react';
import Modal from '../../components/ui/Modal';
import MeetingMinutesPrint from './MeetingMinutesPrint';
import Tabs from '../../components/ui/Tabs';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import Input from '../../components/ui/Input';
import Combobox from '../../components/ui/Combobox';
import api from '../../utils/api';
import minutesService from '../../api/services/meetingMinutesService';
import type {
  MinutesStatus,
  MinutesOptions,
  MinutesPayload,
  MinutesDetail,
  AttendanceRow,
  AgendaItemInput,
  DecisionItemInput,
  ActionItemInput,
  AobItemInput,
} from '../../api/services/meetingMinutesService';

export interface MinutesMeetingInfo {
  id: number;
  title: string;
  meeting_date: string;
  start_time: string;
  end_time: string;
  location: string;
  status: string;
}

interface FormState {
  meeting_date: string;
  start_time: string;
  end_time: string;
  venue: string;
  chairperson_id: string;
  secretary_id: string;
  aob: string;
  next_meeting_date: string;
  next_meeting_time: string;
  next_meeting_venue: string;
  next_meeting_notes: string;
  amendment_reason: string;
  agenda_items: AgendaItemInput[];
  decisions: DecisionItemInput[];
  action_items: ActionItemInput[];
  aob_items: AobItemInput[];
}

type ItemList = 'agenda_items' | 'decisions' | 'action_items' | 'aob_items';

const emptyAgenda = (pos: number): AgendaItemInput => ({
  position: pos,
  agenda_number: `${pos}.0`,
  title: '',
  presenter_id: '',
  discussion: '',
  decision: '',
});
const emptyDecision = (n: number): DecisionItemInput => ({
  decision_number: String(n),
  resolution: '',
  responsible_id: '',
  department_id: '',
  due_date: '',
  status: 'pending',
});
const emptyAction = (): ActionItemInput => ({
  action: '',
  assigned_to: '',
  department_id: '',
  due_date: '',
  priority: 'medium',
  status: 'pending',
  remarks: '',
});
const emptyAob = (): AobItemInput => ({
  item: '',
  discussion: '',
  decision: '',
  action: '',
  responsible_id: '',
});

const blankForm = (m: MinutesMeetingInfo): FormState => ({
  meeting_date: m.meeting_date || '',
  start_time: m.start_time || '',
  end_time: m.end_time || '',
  venue: m.location || '',
  chairperson_id: '',
  secretary_id: '',
  aob: '',
  next_meeting_date: '',
  next_meeting_time: '',
  next_meeting_venue: '',
  next_meeting_notes: '',
  amendment_reason: '',
  agenda_items: [emptyAgenda(1)],
  decisions: [emptyDecision(1)],
  action_items: [emptyAction()],
  aob_items: [emptyAob()],
});

const TABS = [
  { id: 'overview', name: 'Meeting Info', icon: <FileText className="h-4 w-4" /> },
  { id: 'attendance', name: 'Attendance', icon: <Users className="h-4 w-4" /> },
  { id: 'agenda', name: 'Agenda & Discussion', icon: <ListChecks className="h-4 w-4" /> },
  { id: 'actions', name: 'Resolutions & Actions', icon: <ClipboardList className="h-4 w-4" /> },
  { id: 'review', name: 'Review & Publish', icon: <CheckCircle2 className="h-4 w-4" /> },
];

const catOf = (p: AttendanceRow): string => {
  if (p.category) return p.category;
  const att = (p.attendance_status ?? '').toLowerCase();
  const res = (p.response_status ?? '').toLowerCase();
  const type = (p.invitation_type ?? '').toLowerCase();
  if (att === 'present') return 'Present';
  if (att === 'excused' || res === 'declined') return 'Apologies';
  if (att === 'absent') return 'Absent';
  if (type === 'qr_checkin') return 'Guests';
  return 'Not Marked';
};

/** Human-readable response value ("accepted" -> "Accepted"). */
const humanize = (v?: string | null): string => {
  if (!v) return '—';
  const label = v.replace(/_/g, ' ');
  return label.charAt(0).toUpperCase() + label.slice(1);
};

const selectCls =
  'w-full px-3 py-2 border rounded-md bg-white dark:bg-slate-800 dark:border-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-primary-500';
const textareaCls =
  'w-full px-3 py-2 border rounded-md bg-white dark:bg-slate-900 dark:text-slate-100 dark:border-slate-600 focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y';

const Field = ({ label, children }: { label: string; children: ReactNode }) => (
  <label className="block">
    <span className="label text-gray-700 dark:text-gray-300">{label}</span>
    <div className="mt-1">{children}</div>
  </label>
);

const StatusBadge = ({ status, exists }: { status: MinutesStatus['status']; exists: boolean }) => {
  if (!exists) return <Badge variant="default">Not Started</Badge>;
  return status === 'published' ? (
    <Badge variant="success">Published</Badge>
  ) : (
    <Badge variant="warning">Draft</Badge>
  );
};

const MeetingMinutesModal = ({
  meeting,
  onClose,
  onSaved,
}: {
  meeting: MinutesMeetingInfo;
  onClose: () => void;
  onSaved?: () => void;
}) => {
  // ---- State ----
  const [minutesStatus, setMinutesStatus] = useState<MinutesStatus | null>(null);
  const [options, setOptions] = useState<MinutesOptions>({ employees: [], departments: [] });
  const [form, setForm] = useState<FormState>(blankForm(meeting));
  const [participants, setParticipants] = useState<AttendanceRow[]>([]);
  const [activeTab, setActiveTab] = useState('overview');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [participantsLoading, setParticipantsLoading] = useState(false);
  const [error, setError] = useState('');
  // Branded printable view (logo + formal layout + Print/PDF).
  const [showPrint, setShowPrint] = useState(false);

  // Chairperson/secretary pickers scan the full staff list, so they get
  // type-to-filter. emp_no and designation ride along in `description`, which
  // means the filter also matches on staff number and job title.
  const employeeOptions = useMemo(
    () =>
      options.employees.map((e) => ({
        value: e.id,
        label: `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() || 'Unnamed employee',
        description: [e.emp_no, e.designation].filter(Boolean).join(' · ') || undefined,
      })),
    [options.employees],
  );

  useEffect(() => {
    const load = async () => {
      setLoading(true);
      setError('');
      try {
        const [statusRes, optionsRes] = await Promise.allSettled([
          minutesService.status(meeting.id),
          minutesService.options(meeting.id),
        ]);
        if (statusRes.status === 'fulfilled') {
          const statusPayload = statusRes.value.data?.data as MinutesStatus | undefined;
          if (statusPayload) setMinutesStatus(statusPayload);
        }
        if (optionsRes.status === 'fulfilled') {
          const optionsPayload = optionsRes.value.data?.data as MinutesOptions | undefined;
          if (optionsPayload) setOptions(optionsPayload);
        }

        const statusPayload =
          statusRes.status === 'fulfilled'
            ? (statusRes.value.data?.data as MinutesStatus | undefined)
            : undefined;
        if (statusPayload?.exists) {
          try {
            const viewRes = await minutesService.view(meeting.id);
            const detail = viewRes.data?.data as MinutesDetail | null;
            if (detail) {
              const m = detail.minutes || {};
              setForm({
                meeting_date: (m as any).meeting_date || meeting.meeting_date,
                start_time: (m as any).start_time || meeting.start_time,
                end_time: (m as any).end_time || meeting.end_time,
                venue: (m as any).venue || meeting.location,
                chairperson_id: (m as any).chairperson_id ? String((m as any).chairperson_id) : '',
                secretary_id: (m as any).secretary_id ? String((m as any).secretary_id) : '',
                aob: (m as any).aob || '',
                next_meeting_date: (m as any).next_meeting_date || '',
                next_meeting_time: (m as any).next_meeting_time || '',
                next_meeting_venue: (m as any).next_meeting_venue || '',
                next_meeting_notes: (m as any).next_meeting_notes || '',
                amendment_reason: '',
                agenda_items: detail.agenda_items?.length
                  ? (detail.agenda_items as unknown as AgendaItemInput[])
                  : [emptyAgenda(1)],
                decisions: detail.decisions?.length
                  ? (detail.decisions as unknown as DecisionItemInput[])
                  : [emptyDecision(1)],
                action_items: detail.action_items?.length
                  ? (detail.action_items as unknown as ActionItemInput[])
                  : [emptyAction()],
                aob_items: detail.aob_items?.length
                  ? (detail.aob_items as unknown as AobItemInput[])
                  : [emptyAob()],
              });
            }
          } catch {
            /* view failed — treat as new */
          }
        }

        // Load participants for the Attendance tab
        setParticipantsLoading(true);
        try {
          const partRes = await api.get<{ data: AttendanceRow[] }>(
            `/meetings/${meeting.id}/participants`,
          );
          const partPayload = partRes.data?.data;
          setParticipants(Array.isArray(partPayload) ? partPayload : []);
        } catch {
          setParticipants([]);
        }
      } catch (err: any) {
        setError(
          err.response?.data?.message || err.response?.data?.error || 'Failed to load minutes data',
        );
      } finally {
        setLoading(false);
        setParticipantsLoading(false);
      }
    };
    load();
  }, [meeting.id]);

  const isPublished = minutesStatus?.exists && minutesStatus?.status === 'published';
  const isViewOnly = isPublished && minutesStatus?.can_view && !minutesStatus?.can_edit_draft;
  const canEdit = !!(minutesStatus?.can_edit_draft || minutesStatus?.can_create);
  const canPublish = !!minutesStatus?.can_publish;
  const canReopen = !!minutesStatus?.can_reopen;

  // Employee-id -> name map from the options endpoint — used as a fallback
  // when a participant row arrives without name fields.
  const nameById = useMemo(() => {
    const map = new Map<string, string>();
    options.employees.forEach((e) => {
      const full = `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim();
      if (full) map.set(String(e.id), full);
    });
    return map;
  }, [options.employees]);

  /** Resolve a participant's display name (never shows a bare id when a name exists). */
  const displayNameOf = (p: AttendanceRow): string => {
    const direct = [p.first_name, p.last_name].filter(Boolean).join(' ').trim();
    if (direct) return direct;
    if (p.name && p.name.trim()) return p.name.trim();
    const fromOptions = nameById.get(String(p.employee_id));
    if (fromOptions) return fromOptions;
    if (p.employee_number) return `#${p.employee_number}`;
    return `#${p.employee_id ?? '?'}`;
  };

  // ---- Form helpers ----
  const updateField = (
    key: keyof FormState,
    value: string | AgendaItemInput[] | DecisionItemInput[] | ActionItemInput[] | AobItemInput[],
  ) => {
    setForm((f) => ({ ...f, [key]: value }));
  };

  const updateItem = (list: ItemList, index: number, field: string, value: string) => {
    setForm((f) => ({
      ...f,
      [list]: f[list].map((item: any, i: number) =>
        i === index ? { ...item, [field]: value } : item,
      ),
    }));
  };

  const addItem = (list: ItemList, template: (pos: number) => any) => {
    setForm((f) => ({ ...f, [list]: [...f[list], template(f[list].length + 1)] }));
  };

  const removeItem = (list: ItemList, index: number) => {
    setForm((f) => ({
      ...f,
      [list]: f[list]
        .filter((_, i) => i !== index)
        .map((item: any, i: number) => ({ ...item, position: i + 1 })),
    }));
  };

  const moveItem = (list: ItemList, from: number, to: number) => {
    if (to < 0 || to >= form[list].length) return;
    const items = [...form[list]];
    const [moved] = items.splice(from, 1);
    items.splice(to, 0, { ...moved, position: to + 1 });
    items.forEach((item: any, i: number) => {
      item.position = i + 1;
    });
    setForm((f) => ({ ...f, [list]: items }));
  };

  const buildPayload = (opts: { publish?: boolean } = {}): MinutesPayload => {
    // The backend 422s on blank rows (agenda title required, action text
    // required, AOB item required…). Template placeholder rows the user
    // never filled are dropped here so saving "just works".
    const cleanAgenda = form.agenda_items.filter(
      (a) => String(a.title ?? '').trim() !== '' || String(a.discussion ?? '').trim() !== '' || String(a.decision ?? '').trim() !== '',
    );
    const cleanDecisions = form.decisions.filter(
      (d) => String(d.resolution ?? '').trim() !== '',
    );
    const cleanActions = form.action_items.filter(
      (a) => String(a.action ?? '').trim() !== '',
    );
    const cleanAob = form.aob_items.filter(
      (a) =>
        String(a.item ?? '').trim() !== '' ||
        String(a.discussion ?? '').trim() !== '' ||
        String(a.decision ?? '').trim() !== '' ||
        String(a.action ?? '').trim() !== '',
    );
    // The simplified editor has no Decisions tab: resolutions live on each
    // agenda item ("Resolution / Agreement") and tasks in the Actions list.
    // Strict publish validation still requires >= 1 decision, so derive the
    // list from whatever the user actually filled in (never invent content).
    let derivedDecisions = cleanDecisions;
    if (derivedDecisions.length === 0) {
      const fromAgenda = cleanAgenda
        .filter((a) => String(a.decision ?? '').trim() !== '')
        .map((a, i) => ({
          decision_number: String(i + 1),
          resolution: String(a.decision).trim(),
          responsible_id: '',
          department_id: '',
          due_date: '',
          status: 'pending',
        }));
      const fromActions = cleanActions.map((a, i) => ({
        decision_number: String(i + 1),
        resolution: String(a.action).trim(),
        responsible_id: String(a.assigned_to ?? ''),
        department_id: '',
        due_date: String(a.due_date ?? ''),
        status: 'pending',
      }));
      derivedDecisions = fromAgenda.length > 0 ? fromAgenda : fromActions;
    }
    return {
      publish: opts.publish ?? false,
      // Strict publish validation requires a non-empty reference number; the
      // status endpoint always reports one (generated on the fly if needed).
      reference_number: minutesStatus?.reference_number ?? '',
      meeting_date: form.meeting_date,
      start_time: form.start_time,
      end_time: form.end_time,
      venue: form.venue,
      chairperson_id: form.chairperson_id,
      secretary_id: form.secretary_id,
      aob: form.aob,
      next_meeting_date: form.next_meeting_date,
      next_meeting_time: form.next_meeting_time,
      next_meeting_venue: form.next_meeting_venue,
      next_meeting_notes: form.next_meeting_notes,
      amendment_reason: form.amendment_reason,
      agenda_items: cleanAgenda,
      decisions: derivedDecisions,
      action_items: cleanActions,
      aob_items: cleanAob,
    };
  };

  // ---- Save handlers ----
  const saveDraft = async () => {
    if (!canEdit) return;
    setSaving(true);
    try {
      const pl = buildPayload();
      if (minutesStatus?.exists) {
        await minutesService.update(meeting.id, pl);
      } else {
        await minutesService.create(meeting.id, pl);
      }
      toast.success('Draft saved successfully');
      const res = await minutesService.status(meeting.id);
      setMinutesStatus(res.data?.data as MinutesStatus);
      onSaved?.();
    } catch (err: any) {
      toast.error(err.response?.data?.message || 'Failed to save draft');
    } finally {
      setSaving(false);
    }
  };

  const publishMinutes = async () => {
    if (!canPublish) return;
    setSaving(true);
    try {
      const pl = buildPayload({ publish: true });
      if (minutesStatus?.exists) {
        // publish() saves the current form state AND flips status to
        // 'published'. update() alone would silently keep it a draft while
        // the UI reported success.
        await minutesService.publish(meeting.id, pl);
      } else {
        // Create a draft first, then publish it: the strict publish rules
        // (reference number + >= 1 decision) only run on the publish
        // endpoint, keeping both paths consistent.
        await minutesService.create(meeting.id, buildPayload());
        const st = await minutesService.status(meeting.id);
        const latest = st.data?.data as MinutesStatus | undefined;
        if (latest) setMinutesStatus(latest);
        await minutesService.publish(meeting.id, {
          ...pl,
          reference_number: latest?.reference_number ?? pl.reference_number,
        });
      }
      toast.success('Minutes published successfully');
      const res = await minutesService.status(meeting.id);
      setMinutesStatus(res.data?.data as MinutesStatus);
      onSaved?.();
    } catch (err: any) {
      toast.error(err.response?.data?.message || 'Failed to publish');
    } finally {
      setSaving(false);
    }
  };

  const reopenMinutes = async () => {
    if (!canReopen || !form.amendment_reason.trim()) {
      toast.error('Please provide a reason for reopening');
      return;
    }
    setSaving(true);
    try {
      await minutesService.reopen(meeting.id, form.amendment_reason);
      toast.success('Minutes reopened for amendment');
      const res = await minutesService.status(meeting.id);
      setMinutesStatus(res.data?.data as MinutesStatus);
      onSaved?.();
    } catch (err: any) {
      toast.error(err.response?.data?.message || 'Failed to reopen');
    } finally {
      setSaving(false);
    }
  };

  const ref = minutesStatus?.reference_number || null;

  // ---- Render helpers ----
  const renderOverview = () => (
    <div className="space-y-4">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Field label="Meeting Title">{meeting.title}</Field>
        <Field label="Reference Number">{ref || 'Generated on publish'}</Field>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Field label="Date">
          <Input
            type="date"
            value={form.meeting_date}
            onChange={(e) => updateField('meeting_date', e.target.value)}
            readOnly={isViewOnly}
          />
        </Field>
        <Field label="Start">
          <Input
            type="time"
            value={form.start_time}
            onChange={(e) => updateField('start_time', e.target.value)}
            readOnly={isViewOnly}
          />
        </Field>
        <Field label="End">
          <Input
            type="time"
            value={form.end_time}
            onChange={(e) => updateField('end_time', e.target.value)}
            readOnly={isViewOnly}
          />
        </Field>
        <Field label="Venue">
          <Input
            value={form.venue}
            onChange={(e) => updateField('venue', e.target.value)}
            readOnly={isViewOnly}
          />
        </Field>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <Field label="Chairperson">
          <Combobox
            value={form.chairperson_id}
            onChange={(v) => updateField('chairperson_id', v)}
            options={employeeOptions}
            placeholder="Search chairperson"
            noOptionsMessage="No employees found."
            disabled={isViewOnly}
          />
        </Field>
        <Field label="Secretary">
          <Combobox
            value={form.secretary_id}
            onChange={(v) => updateField('secretary_id', v)}
            options={employeeOptions}
            placeholder="Search secretary"
            noOptionsMessage="No employees found."
            disabled={isViewOnly}
          />
        </Field>
      </div>
    </div>
  );

  const renderAttendance = () => {
    const cats: Record<string, AttendanceRow[]> = {
      Present: [],
      Apologies: [],
      Absent: [],
      Guests: [],
      'Not Marked': [],
    };
    participants.forEach((p) => {
      const cat = catOf(p);
      (cats[cat] || (cats['Not Marked'] = [])).push(p);
    });
    const order = ['Present', 'Apologies', 'Absent', 'Guests', 'Not Marked'];
    return (
      <div className="space-y-4">
        {participantsLoading ? (
          <div className="text-sm text-gray-500">Loading participants…</div>
        ) : participants.length === 0 ? (
          <div className="text-sm text-gray-500">No participants found for this meeting.</div>
        ) : (
          order.map((cat) => {
            const rows = cats[cat];
            if (!rows.length) return null;
            return (
              <div key={cat}>
                <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                  {cat} ({rows.length})
                </h4>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-700">
                    <thead className="bg-gray-50 dark:bg-slate-700/50">
                      <tr>
                        <th className="px-3 py-2 text-left text-xs">Name</th>
                        <th className="px-3 py-2 text-left text-xs">Response</th>
                        <th className="px-3 py-2 text-left text-xs">Attendance</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white dark:bg-slate-800 divide-y divide-gray-200 dark:divide-slate-700">
                      {rows.map((p) => (
                        <tr key={p.invitation_id ?? p.employee_id ?? p.employee_number}>
                          <td className="px-3 py-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {displayNameOf(p)}
                          </td>
                          <td className="px-3 py-1 text-sm">{humanize(p.response_status)}</td>
                          <td className="px-3 py-1 text-sm">{humanize(p.attendance_status)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            );
          })
        )}
        {!participantsLoading && participants.length > 0 && (
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-2">
            Attendance is derived from existing meeting invitations.
          </p>
        )}
      </div>
    );
  };

  const renderItemForm = (
    list: ItemList,
    title: string,
    fields: Array<{
      key: string;
      label: string;
      type?: string;
      wide?: boolean;
      options?: { id: number | string; name: string }[];
    }>,
    template: (pos: number) => any,
  ) => {
    const items = form[list] as any[];
    return (
      <div className="space-y-3">
        <div className="flex items-center justify-between">
          <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
            {title} ({items.length})
          </h4>
          {!isViewOnly && (
            <Button variant="outline" size="sm" onClick={() => addItem(list, template)}>
              <Plus className="h-3 w-3 mr-1" /> Add
            </Button>
          )}
        </div>
        {items.map((item, i) => (
          <div
            key={item.id || i}
            className="border border-gray-200 dark:border-slate-700 rounded-lg p-3 space-y-3"
          >
            <div className="flex items-center justify-between">
              <span className="text-xs text-gray-500 dark:text-gray-400">Item {i + 1}</span>
              {!isViewOnly && items.length > 1 && (
                <div className="flex space-x-1">
                  <button
                    type="button"
                    onClick={() => moveItem(list, i, i - 1)}
                    className="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    title="Move up"
                  >
                    <ArrowUp className="h-3 w-3" />
                  </button>
                  <button
                    type="button"
                    onClick={() => moveItem(list, i, i + 1)}
                    className="p-0.5 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                    title="Move down"
                  >
                    <ArrowDown className="h-3 w-3" />
                  </button>
                  <button
                    type="button"
                    onClick={() => removeItem(list, i)}
                    className="p-0.5 text-red-500 hover:text-red-700"
                    title="Remove"
                  >
                    <Trash2 className="h-3 w-3" />
                  </button>
                </div>
              )}
            </div>
            {fields.map((f) => {
              const val = item[f.key] ?? '';
              const gridSpan = f.wide ? 'md:col-span-2' : '';
              if (f.type === 'textarea') {
                return (
                  <div key={f.key} className={gridSpan}>
                    <label className="label text-gray-700 dark:text-gray-300">{f.label}</label>
                    <textarea
                      className={textareaCls}
                      value={String(val)}
                      onChange={(e) => updateItem(list, i, f.key, e.target.value)}
                      disabled={isViewOnly}
                      rows={2}
                    />
                  </div>
                );
              }
              if (f.options) {
                return (
                  <div key={f.key} className={gridSpan}>
                    <label className="label text-gray-700 dark:text-gray-300">{f.label}</label>
                    <select
                      className={selectCls}
                      value={String(val)}
                      onChange={(e) => updateItem(list, i, f.key, e.target.value)}
                      disabled={isViewOnly}
                    >
                      {f.options.map((o) => (
                        <option key={o.id} value={o.id}>
                          {o.name}
                        </option>
                      ))}
                    </select>
                  </div>
                );
              }
              return (
                <div key={f.key} className={gridSpan}>
                  <label className="label text-gray-700 dark:text-gray-300">{f.label}</label>
                  <input
                    type={f.type || 'text'}
                    className={selectCls}
                    value={String(val)}
                    onChange={(e) => updateItem(list, i, f.key, e.target.value)}
                    disabled={isViewOnly}
                  />
                </div>
              );
            })}
          </div>
        ))}
      </div>
    );
  };

  const renderAgenda = () => (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
            Agenda Items & Discussion ({form.agenda_items.length})
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            What was discussed under each agenda item, and what was agreed.
          </p>
        </div>
        {!isViewOnly && (
          <Button variant="outline" size="sm" onClick={() => addItem('agenda_items', (pos) => emptyAgenda(pos))}>
            <Plus className="h-3 w-3 mr-1" /> Add Item
          </Button>
        )}
      </div>
      {form.agenda_items.map((item, i) => (
        <div key={(item as { id?: string | number }).id ?? i} className="border border-gray-200 dark:border-slate-700 rounded-lg p-3 space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-gray-500 dark:text-gray-400">
              Agenda {item.agenda_number || `${i + 1}.0`}
            </span>
            {!isViewOnly && form.agenda_items.length > 1 && (
              <button type="button" onClick={() => removeItem('agenda_items', i)} className="p-0.5 text-red-500 hover:text-red-700" title="Remove">
                <Trash2 className="h-3 w-3" />
              </button>
            )}
          </div>
          <div>
            <label className="label text-gray-700 dark:text-gray-300">Title *</label>
            <input className={selectCls} value={String(item.title ?? '')}
              onChange={(e) => updateItem('agenda_items', i, 'title', e.target.value)}
              disabled={isViewOnly} placeholder="e.g. Non-revenue water reduction — Zone 3" />
          </div>
          <div>
            <label className="label text-gray-700 dark:text-gray-300">Discussion / Deliberation</label>
            <textarea className={textareaCls} value={String(item.discussion ?? '')}
              onChange={(e) => updateItem('agenda_items', i, 'discussion', e.target.value)}
              disabled={isViewOnly} rows={3} placeholder="Key points raised, reports tabled…" />
          </div>
          <div>
            <label className="label text-gray-700 dark:text-gray-300">Resolution / Agreement</label>
            <textarea className={textareaCls} value={String(item.decision ?? '')}
              onChange={(e) => updateItem('agenda_items', i, 'decision', e.target.value)}
              disabled={isViewOnly} rows={2} placeholder="What was agreed…" />
          </div>
        </div>
      ))}
    </div>
  );

  // Resolutions + follow-ups in ONE simple table (water-company style):
  // what was agreed, who does it, by when. The old separate Decisions tab
  // (resolution + responsible + department + due + status) is folded in here,
  // and the old priority/remarks/department columns are dropped.
  const renderActions = () => (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <div>
          <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
            Resolutions & Action Points ({form.action_items.length})
          </h4>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            Each agreement becomes a trackable action: who, by when.
          </p>
        </div>
        {!isViewOnly && (
          <Button variant="outline" size="sm" onClick={() => addItem('action_items', () => emptyAction())}>
            <Plus className="h-3 w-3 mr-1" /> Add Action
          </Button>
        )}
      </div>
      {form.action_items.map((item, i) => (
        <div key={(item as { id?: string | number }).id ?? i} className="border border-gray-200 dark:border-slate-700 rounded-lg p-3 space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Action {i + 1}</span>
            {!isViewOnly && form.action_items.length > 1 && (
              <button type="button" onClick={() => removeItem('action_items', i)} className="p-0.5 text-red-500 hover:text-red-700" title="Remove">
                <Trash2 className="h-3 w-3" />
              </button>
            )}
          </div>
          <div>
            <label className="label text-gray-700 dark:text-gray-300">Resolution / Action *</label>
            <textarea className={textareaCls} value={String(item.action ?? '')}
              onChange={(e) => updateItem('action_items', i, 'action', e.target.value)}
              disabled={isViewOnly} rows={2} placeholder="e.g. Repair the burst main on Kenyatta Road" />
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label className="label text-gray-700 dark:text-gray-300">Responsible Person</label>
              <select className={selectCls} value={String(item.assigned_to ?? '')}
                onChange={(e) => updateItem('action_items', i, 'assigned_to', e.target.value)} disabled={isViewOnly}>
                <option value="">— Select —</option>
                {options.employees.map((e) => (
                  <option key={e.id} value={e.id}>{e.first_name} {e.last_name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="label text-gray-700 dark:text-gray-300">Due Date</label>
              <input type="date" className={selectCls} value={String(item.due_date ?? '')}
                onChange={(e) => updateItem('action_items', i, 'due_date', e.target.value)} disabled={isViewOnly} />
            </div>
            <div>
              <label className="label text-gray-700 dark:text-gray-300">Status</label>
              <select className={selectCls} value={String(item.status ?? 'pending')}
                onChange={(e) => updateItem('action_items', i, 'status', e.target.value)} disabled={isViewOnly}>
                {['pending', 'in_progress', 'completed', 'deferred'].map((s) => (
                  <option key={s} value={s}>{s.replace('_', ' ')}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
      ))}
      {!isViewOnly && (
        <p className="text-xs text-gray-500 dark:text-gray-400">Empty rows are ignored on save.</p>
      )}
    </div>
  );

  const renderReview = () => (
    <div className="space-y-6">
      <div className="border border-gray-200 dark:border-slate-700 rounded-lg p-4">
        <h4 className="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
          Next Meeting
        </h4>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Field label="Date">
            <Input
              type="date"
              value={form.next_meeting_date}
              onChange={(e) => updateField('next_meeting_date', e.target.value)}
              readOnly={isViewOnly}
            />
          </Field>
          <Field label="Time">
            <Input
              type="time"
              value={form.next_meeting_time}
              onChange={(e) => updateField('next_meeting_time', e.target.value)}
              readOnly={isViewOnly}
            />
          </Field>
        </div>
        <div className="mt-3">
          <Field label="Venue">
            <Input
              value={form.next_meeting_venue}
              onChange={(e) => updateField('next_meeting_venue', e.target.value)}
              readOnly={isViewOnly}
            />
          </Field>
        </div>
        <div className="mt-3">
          <Field label="Notes">
            <textarea
              className={textareaCls}
              value={form.next_meeting_notes}
              onChange={(e) => updateField('next_meeting_notes', e.target.value)}
              disabled={isViewOnly}
              rows={2}
            />
          </Field>
        </div>
      </div>
      <Field label="Any Other Business (AOB)">
        <textarea
          className={textareaCls}
          value={form.aob}
          onChange={(e) => updateField('aob', e.target.value)}
          disabled={isViewOnly}
          rows={3}
          placeholder="Any other matters raised, e.g. burst pipes, meter faults, customer complaints…"
        />
      </Field>
      {canReopen && minutesStatus?.exists && minutesStatus?.status === 'published' && (
        <div className="border border-amber-200 dark:border-amber-800 rounded-lg p-4 bg-amber-50 dark:bg-amber-900/20">
          <h4 className="text-sm font-semibold text-amber-800 dark:text-amber-300 mb-2">
            Reopen for Amendment
          </h4>
          <textarea
            className={textareaCls}
            value={form.amendment_reason}
            onChange={(e) => updateField('amendment_reason', e.target.value)}
            placeholder="Reason for amendment…"
            rows={2}
          />
          <Button
            variant="outline"
            size="sm"
            className="mt-2"
            loading={saving}
            onClick={reopenMinutes}
          >
            <RotateCcw className="h-4 w-4 mr-1" /> Reopen
          </Button>
        </div>
      )}
      <div className="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-slate-700">
        <StatusBadge status={minutesStatus?.status || null} exists={!!minutesStatus?.exists} />
        <div className="flex space-x-3">
          {minutesStatus?.exists && (
            <Button variant="outline" onClick={() => setShowPrint(true)}>
              <FileText className="h-4 w-4 mr-1" /> View Minutes
            </Button>
          )}
          {canEdit && (
            <Button variant="secondary" onClick={saveDraft} loading={saving}>
              <Save className="h-4 w-4 mr-1" /> Save Draft
            </Button>
          )}
          {canPublish && (
            <Button variant="primary" onClick={publishMinutes} loading={saving}>
              <Send className="h-4 w-4 mr-1" />{' '}
              {minutesStatus?.exists ? 'Publish' : 'Save & Publish'}
            </Button>
          )}
        </div>
      </div>
    </div>
  );

  const tabContent: Record<string, () => ReactNode> = {
    overview: renderOverview,
    attendance: renderAttendance,
    agenda: renderAgenda,
    actions: renderActions,
    review: renderReview,
  };

  return (
    <Modal
      isOpen={true}
      onClose={onClose}
      title={
        isViewOnly
          ? 'Meeting Minutes'
          : minutesStatus?.exists
            ? 'Edit Meeting Minutes'
            : 'Create Meeting Minutes'
      }
      size="2xl"
    >
      <div className="space-y-4">
        <div className="flex items-center justify-between pb-3 border-b border-gray-200 dark:border-slate-700">
          <div>
            {ref && <span className="text-xs text-gray-500 dark:text-gray-400">Ref: {ref}</span>}
          </div>
          {minutesStatus?.exists && (
            <Badge variant={minutesStatus.status === 'published' ? 'success' : 'warning'}>
              {minutesStatus.status === 'published' ? 'Published' : 'Draft'}
            </Badge>
          )}
        </div>
        {error && (
          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg text-sm">
            {error}
          </div>
        )}
        {loading && !minutesStatus ? (
          <div className="flex items-center justify-center h-48">
            <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
          </div>
        ) : (
          <>
            {!minutesStatus?.can_create && !minutesStatus?.exists && !minutesStatus?.can_view ? (
              <div className="p-6 text-center text-red-600 dark:text-red-400">
                <AlertTriangle className="h-12 w-12 mx-auto mb-2" />
                <p>You do not have permission to view or manage minutes for this meeting.</p>
              </div>
            ) : (
              <>
                <Tabs
                  tabs={TABS}
                  activeTab={activeTab}
                  onChange={setActiveTab}
                  variant="underline"
                />
                <div className="pt-4 min-h-[300px]">{tabContent[activeTab]?.() || null}</div>
              </>
            )}
          </>
        )}
      </div>
      {showPrint && (
        <MeetingMinutesPrint meeting={meeting} onClose={() => setShowPrint(false)} />
      )}
    </Modal>
  );
};

export default MeetingMinutesModal;

import { useState, useEffect } from 'react';
import { Printer, FileDown, X, Loader2 } from 'lucide-react';
import Button from '../../components/ui/Button';
import Badge from '../../components/ui/Badge';
import minutesService, { MinutesDetail } from '../../api/services/meetingMinutesService';
import type { MinutesMeetingInfo } from './MeetingMinutesModal';

/**
 * MeetingMinutesPrint — fully formatted, branded minutes document.
 *
 * Renders the SAVED minutes (GET /meetings/{id}/minutes) as a formal MUWASCO
 * document: logo + company header, meeting meta, attendance, agenda with
 * discussions/decisions, resolutions, action items, AOB and next meeting.
 * "Download PDF" uses the browser's print-to-PDF pipeline (print CSS hides
 * everything else), so no extra dependency is needed and the PDF always
 * matches the on-screen document exactly.
 */
const esc = (v: unknown): string => String(v ?? '—');

const fmtDate = (d?: string | null): string => {
  if (!d) return '—';
  try {
    return new Date(d).toLocaleDateString('en-GB', {
      weekday: 'long',
      day: '2-digit',
      month: 'long',
      year: 'numeric',
    });
  } catch {
    return String(d);
  }
};

const fmtTime = (t?: string | null): string => {
  if (!t) return '—';
  const [h, m] = String(t).split(':');
  const hh = parseInt(h, 10);
  if (Number.isNaN(hh)) return String(t);
  const ampm = hh >= 12 ? 'PM' : 'AM';
  return `${hh % 12 || 12}:${m ?? '00'} ${ampm}`;
};

const nameOf = (p: Record<string, unknown>): string => {
  const direct = [p.name, p.employee_name].find((v) => typeof v === 'string' && v.trim() !== '') as
    string | undefined;
  if (direct) return direct;
  const full = `${(p.first_name as string) ?? ''} ${(p.last_name as string) ?? ''}`.trim();
  return full || `Employee #${(p.employee_id as string) ?? '?'}`;
};

const MeetingMinutesPrint = ({
  meeting,
  onClose,
}: {
  meeting: MinutesMeetingInfo;
  onClose: () => void;
}) => {
  const [detail, setDetail] = useState<MinutesDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    minutesService
      .view(meeting.id)
      .then((res) => {
        if (!cancelled) {
          setDetail((res.data?.data as MinutesDetail) ?? null);
          setLoading(false);
        }
      })
      .catch((err: any) => {
        if (!cancelled) {
          setError(err.response?.data?.message || 'Failed to load minutes.');
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [meeting.id]);
  const minutes = (detail?.minutes ?? {}) as Record<string, unknown>;
  const agendaItems = (detail?.agenda_items ?? []) as Record<string, unknown>[];
  const decisions = (detail?.decisions ?? []) as Record<string, unknown>[];
  const actionItems = (detail?.action_items ?? []) as Record<string, unknown>[];
  const aobItems = (detail?.aob_items ?? []) as Record<string, unknown>[];
  const participants = (detail?.participants ?? []) as Record<string, unknown>[];
  const status = String(minutes.status ?? 'draft');
  const ref = String(minutes.reference_number ?? minutes.ref ?? '');

  const handlePrint = () => window.print();
  const sectionTitle =
    'text-sm font-bold uppercase tracking-wide text-slate-700 border-b-2 border-slate-800 pb-1 mb-3 mt-8';

  return (
    <>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          #minutes-print-doc, #minutes-print-doc * { visibility: visible; }
          #minutes-print-doc { position: absolute; left: 0; top: 0; width: 100%; }
          .no-print { display: none !important; }
        }
      `}</style>
      <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 overflow-y-auto">
        <div className="relative w-full max-w-4xl my-8 bg-white rounded-xl shadow-xl">
          <div className="no-print flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white rounded-t-xl z-10">
            <h3 className="text-lg font-semibold text-gray-900">Meeting Minutes</h3>
            <div className="flex items-center space-x-2">
              {!loading && !error && (
                <Button size="sm" variant="outline" onClick={handlePrint}>
                  <Printer className="h-4 w-4 mr-1" /> Print
                </Button>
              )}
              {!loading && !error && (
                <Button size="sm" onClick={handlePrint}>
                  <FileDown className="h-4 w-4 mr-1" /> Download PDF
                </Button>
              )}
              <button
                onClick={onClose}
                className="p-2 text-gray-500 hover:text-gray-700 rounded-md hover:bg-gray-100"
                aria-label="Close"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
          </div>
          <div className="p-8">
            {loading ? (
              <div className="flex items-center justify-center h-48">
                <Loader2 className="h-8 w-8 animate-spin text-primary-600" />
              </div>
            ) : error ? (
              <p className="text-center text-red-600 py-12">{error}</p>
            ) : (
              <div id="minutes-print-doc" className="text-gray-900">
                <div className="text-center border-b-4 border-blue-900 pb-4 mb-6">
                  <img
                    src="/assets/muwascologo.png"
                    alt="MUWASCO Logo"
                    className="h-16 mx-auto mb-2 object-contain"
                  />
                  <h1 className="text-xl font-bold text-blue-900">
                    MURANGA WATER AND SANITATION COMPANY LTD
                  </h1>
                  <p className="text-xs text-gray-600">
                    P.O. Box 90461 - 80100, Muranga, Kenya | Tel: +254 (0) 41 2314209 | Email:
                    info@muwasco.co.ke
                  </p>
                  <h2 className="text-lg font-bold mt-3 uppercase tracking-wide">
                    Minutes of Meeting
                  </h2>
                  <div className="flex items-center justify-center gap-3 mt-1">
                    {ref && <span className="text-xs text-gray-500">Ref: {ref}</span>}
                    <Badge variant={status === 'published' ? 'success' : 'warning'}>
                      {status === 'published' ? 'Published' : 'Draft'}
                    </Badge>
                  </div>
                </div>
                <table className="w-full text-sm border-collapse mb-2">
                  <tbody>
                    <tr className="border-b">
                      <td className="py-1.5 pr-4 font-semibold w-40">Meeting Title</td>
                      <td>{esc(meeting.title)}</td>
                    </tr>
                    <tr className="border-b">
                      <td className="py-1.5 pr-4 font-semibold">Date</td>
                      <td>{fmtDate(meeting.meeting_date)}</td>
                    </tr>
                    <tr className="border-b">
                      <td className="py-1.5 pr-4 font-semibold">Time</td>
                      <td>
                        {fmtTime(meeting.start_time)} – {fmtTime(meeting.end_time)}
                      </td>
                    </tr>
                    <tr className="border-b">
                      <td className="py-1.5 pr-4 font-semibold">Venue</td>
                      <td>{esc(meeting.location)}</td>
                    </tr>
                    {minutes.chairperson_name ? (
                      <tr className="border-b">
                        <td className="py-1.5 pr-4 font-semibold">Chairperson</td>
                        <td>{esc(minutes.chairperson_name)}</td>
                      </tr>
                    ) : null}
                    {minutes.secretary_name ? (
                      <tr className="border-b">
                        <td className="py-1.5 pr-4 font-semibold">Secretary</td>
                        <td>{esc(minutes.secretary_name)}</td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
                {participants.length > 0 && (
                  <>
                    <h3 className={sectionTitle}>1. Attendance</h3>
                    <table className="w-full text-sm border-collapse">
                      <thead>
                        <tr className="bg-slate-100">
                          <th className="text-left py-1.5 px-2 border">#</th>
                          <th className="text-left py-1.5 px-2 border">Name</th>
                          <th className="text-left py-1.5 px-2 border">Designation</th>
                          <th className="text-left py-1.5 px-2 border">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {participants.map((p, i) => (
                          <tr key={i} className="border-b">
                            <td className="py-1.5 px-2 border">{i + 1}</td>
                            <td className="py-1.5 px-2 border">{nameOf(p)}</td>
                            <td className="py-1.5 px-2 border">{esc(p.designation || p.role)}</td>
                            <td className="py-1.5 px-2 border">
                              {esc(p.attendance_status || p.response_status || p.category)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </>
                )}
                {agendaItems.length > 0 && (
                  <>
                    <h3 className={sectionTitle}>2. Agenda Deliberations</h3>
                    {agendaItems.map((a, i) => (
                      <div key={i} className="mb-4 text-sm">
                        <p className="font-semibold">
                          {esc(a.agenda_number ?? `${i + 1}.0`)} — {esc(a.title)}
                          {a.presenter_name ? ` (Presented by ${esc(a.presenter_name)})` : ''}
                        </p>
                        {a.discussion ? (
                          <p className="mt-1">
                            <span className="font-medium">Discussion: </span>
                            {esc(a.discussion)}
                          </p>
                        ) : null}
                        {a.decision ? (
                          <p className="mt-1">
                            <span className="font-medium">Decision: </span>
                            {esc(a.decision)}
                          </p>
                        ) : null}
                      </div>
                    ))}
                  </>
                )}
                {decisions.length > 0 && (
                  <>
                    <h3 className={sectionTitle}>3. Resolutions</h3>
                    <table className="w-full text-sm border-collapse">
                      <thead>
                        <tr className="bg-slate-100">
                          <th className="text-left py-1.5 px-2 border">No.</th>
                          <th className="text-left py-1.5 px-2 border">Resolution</th>
                          <th className="text-left py-1.5 px-2 border">Responsible</th>
                          <th className="text-left py-1.5 px-2 border">Due</th>
                          <th className="text-left py-1.5 px-2 border">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {decisions.map((d, i) => (
                          <tr key={i} className="border-b">
                            <td className="py-1.5 px-2 border">
                              {esc(d.decision_number ?? i + 1)}
                            </td>
                            <td className="py-1.5 px-2 border">{esc(d.resolution)}</td>
                            <td className="py-1.5 px-2 border">
                              {esc(d.responsible_name ?? d.responsible_id)}
                            </td>
                            <td className="py-1.5 px-2 border">{esc(d.due_date)}</td>
                            <td className="py-1.5 px-2 border">{esc(d.status)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </>
                )}
                {actionItems.length > 0 && (
                  <>
                    <h3 className={sectionTitle}>4. Action Items</h3>
                    <table className="w-full text-sm border-collapse">
                      <thead>
                        <tr className="bg-slate-100">
                          <th className="text-left py-1.5 px-2 border">#</th>
                          <th className="text-left py-1.5 px-2 border">Action</th>
                          <th className="text-left py-1.5 px-2 border">Assigned To</th>
                          <th className="text-left py-1.5 px-2 border">Due</th>
                          <th className="text-left py-1.5 px-2 border">Priority</th>
                          <th className="text-left py-1.5 px-2 border">Status</th>
                        </tr>
                      </thead>
                      <tbody>
                        {actionItems.map((a, i) => (
                          <tr key={i} className="border-b">
                            <td className="py-1.5 px-2 border">{i + 1}</td>
                            <td className="py-1.5 px-2 border">{esc(a.action)}</td>
                            <td className="py-1.5 px-2 border">
                              {esc(a.assigned_to_name ?? a.assigned_to)}
                            </td>
                            <td className="py-1.5 px-2 border">{esc(a.due_date)}</td>
                            <td className="py-1.5 px-2 border">{esc(a.priority)}</td>
                            <td className="py-1.5 px-2 border">{esc(a.status)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </>
                )}
                {aobItems.length > 0 && (
                  <>
                    <h3 className={sectionTitle}>5. Any Other Business</h3>
                    {aobItems.map((a, i) => (
                      <div key={i} className="mb-3 text-sm">
                        <p className="font-semibold">
                          {i + 1}. {esc(a.item)}
                        </p>
                        {a.discussion ? <p className="mt-1">{esc(a.discussion)}</p> : null}
                        {a.decision || a.action ? (
                          <p className="mt-1 text-gray-700">
                            {[
                              a.decision ? `Decision: ${a.decision}` : '',
                              a.action ? `Action: ${a.action}` : '',
                            ]
                              .filter(Boolean)
                              .join(' | ')}
                          </p>
                        ) : null}
                      </div>
                    ))}
                  </>
                )}
                {Boolean(minutes.next_meeting_date || minutes.next_meeting_venue) && (
                  <>
                    <h3 className={sectionTitle}>6. Next Meeting</h3>
                    <p className="text-sm">
                      {minutes.next_meeting_date
                        ? fmtDate(minutes.next_meeting_date as string)
                        : ''}
                      {minutes.next_meeting_time
                        ? ` at ${fmtTime(minutes.next_meeting_time as string)}`
                        : ''}
                      {minutes.next_meeting_venue ? ` — ${esc(minutes.next_meeting_venue)}` : ''}
                    </p>
                    {minutes.next_meeting_notes ? (
                      <p className="text-sm mt-1">{esc(minutes.next_meeting_notes)}</p>
                    ) : null}
                  </>
                )}
                <div className="mt-10 pt-4 border-t text-center text-xs text-gray-500">
                  <p className="font-semibold text-gray-700">
                    Confidential document — for official use only
                  </p>
                  <p>
                    Generated by the MURANGA WATER AND SANITATION COMPANY LTD HR Management System
                    on{' '}
                    {new Date().toLocaleDateString('en-GB', {
                      day: '2-digit',
                      month: 'short',
                      year: 'numeric',
                    })}
                  </p>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
};

export default MeetingMinutesPrint;

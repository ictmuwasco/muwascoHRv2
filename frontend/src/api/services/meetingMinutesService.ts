import api from '../../utils/api'

// ============================================================================
// Meeting Minutes — frontend API service (canonical wire contract).
//
// Backend endpoints (api.php):
//   GET  /meetings/{id}/minutes/status   -> MinutesStatus flags
//   GET  /meetings/{id}/minutes/options  -> { employees, departments }
//   GET  /meetings/{id}/minutes          -> MinutesDetail (view)
//   POST /meetings/{id}/minutes          -> create (payload = MinutesPayload)
//   PUT  /meetings/{id}/minutes          -> update (payload = MinutesPayload)
//   POST /meetings/{id}/minutes/publish  -> publish (payload = MinutesPayload)
//   POST /meetings/{id}/minutes/reopen   -> { reason }
//
// All responses arrive via the shared api wrapper as { data: { data: ... } },
// so callers read `res.data?.data`.
// ============================================================================

export type MinutesWorkflowStatus = 'draft' | 'published'

/** Flags returned by GET /meetings/{id}/minutes/status (server-computed authz). */
export interface MinutesStatus {
  exists: boolean
  status: MinutesWorkflowStatus | null
  version?: number
  reference_number?: string | null
  can_create: boolean
  can_edit_draft: boolean
  can_publish: boolean
  can_reopen: boolean
  can_view: boolean
}

/** Lookups for people/department pickers (from the options endpoint). */
export interface MinutesOptions {
  employees: { id: number; first_name: string; last_name: string; emp_no?: string; designation?: string }[]
  departments: { id: number; name: string }[]
}

export interface AgendaItemInput {
  position: number
  agenda_number: string
  title: string
  presenter_id: string   // '' allowed
  discussion: string
  decision: string
}

export interface DecisionItemInput {
  decision_number: string
  resolution: string
  responsible_id: string // '' allowed
  department_id: string  // '' allowed
  due_date: string       // '' allowed
  status: string
}

export interface ActionItemInput {
  action: string
  assigned_to: string    // '' allowed
  department_id: string  // '' allowed
  due_date: string       // '' allowed
  priority: string
  status: string
  remarks: string
}

export interface AobItemInput {
  item: string
  discussion: string
  decision: string
  action: string
  responsible_id: string // '' allowed
}

/** Create / update / publish payload — mirrors the service normalizer keys. */
export interface MinutesPayload {
  publish?: boolean
  reference_number?: string
  meeting_date: string
  start_time: string
  end_time: string
  venue: string
  chairperson_id: string
  secretary_id: string
  aob: string
  status?: MinutesWorkflowStatus
  next_meeting_date: string
  next_meeting_time: string
  next_meeting_venue: string
  next_meeting_notes: string
  amendment_reason?: string
  agenda_items: AgendaItemInput[]
  decisions: DecisionItemInput[]
  action_items: ActionItemInput[]
  aob_items: AobItemInput[]
}

/** GET /minutes response — header + child collections (+ participants when present). */
export interface MinutesDetail {
  minutes: Record<string, unknown> | null
  agenda_items?: Record<string, unknown>[]
  decisions?: Record<string, unknown>[]
  action_items?: Record<string, unknown>[]
  aob_items?: Record<string, unknown>[]
  participants?: Record<string, unknown>[]
  [key: string]: unknown
}

/** Attendance participant row (derived from meeting_invitations — never duplicated). */
export interface AttendanceRow {
  invitation_id?: number
  employee_id?: number
  name?: string
  first_name?: string
  last_name?: string
  employee_number?: string
  designation?: string
  response_status?: string
  attendance_status?: string
  invitation_type?: string
  category?: string
}

const minutesService = {
  status: (meetingId: number) =>
    api.get(`/meetings/${meetingId}/minutes/status`),

  options: (meetingId: number) =>
    api.get(`/meetings/${meetingId}/minutes/options`),

  view: (meetingId: number) =>
    api.get(`/meetings/${meetingId}/minutes`),

  create: (meetingId: number, payload: MinutesPayload) =>
    api.post(`/meetings/${meetingId}/minutes`, payload),

  update: (meetingId: number, payload: MinutesPayload) =>
    api.put(`/meetings/${meetingId}/minutes`, payload),

  publish: (meetingId: number, payload: MinutesPayload) =>
    api.post(`/meetings/${meetingId}/minutes/publish`, payload),

  reopen: (meetingId: number, reason: string) =>
    api.post(`/meetings/${meetingId}/minutes/reopen`, { reason }),
}

export default minutesService

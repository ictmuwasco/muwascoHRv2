# Meetings Module

> Status: **Implemented.** Audited against `api.php`, `backend/app/Controllers/Meeting/`, `backend/app/Services/`, `backend/app/Repositories/` and `frontend/src/pages/meetings/`.

## Overview

The Meetings module lets HR and authorised managers create meetings, invite employees, collect RSVPs, mark attendance, and hand off to the [Meeting Minutes](meeting-minutes.md) module for the official record.

| | |
|---|---|
| Controllers | `App\Controllers\Meeting\MeetingController`, `App\Controllers\Meeting\MeetingMinutesController` |
| Services | `MeetingService`, `MeetingMinutesService` |
| Repositories | `MeetingRepository`, `MeetingMinutesRepository` |
| Tables | `meetings`, `meeting_invitations` (migrations 016 / 017), `meeting_minutes` + 4 child tables (migration 034) |
| Frontend | `frontend/src/pages/meetings/` — `CreateMeeting.tsx`, `MyMeetings.tsx`, `MeetingsDashboard.tsx`, `MeetingMinutesModal.tsx` |

## How a meeting flows

1. **Create** — HR creates a meeting (title, agenda, date, time, venue) and picks participants.
2. **Invite** — one invitation row per participant; invitees get a notification and see the meeting under **My Meetings**.
3. **Respond** — the invitee confirms or declines. Response status is recorded per invitation; identity always comes from the authenticated session, never from the request body.
4. **Meet** — on the meeting day HR marks attendance per participant (present / absent / excused). Attendance is separate from the RSVP.
5. **Record** — after the meeting, minutes are drafted, published and (if needed) reopened for amendment — see [meeting-minutes.md](meeting-minutes.md).

## Roles & permissions (server-enforced)

| Action | Who |
|---|---|
| Create / edit / cancel meetings | HR Manager, Super Admin |
| Invite / remove participants | HR Manager, Super Admin |
| Mark attendance | HR Manager, Super Admin |
| Respond to own invitation | The invited employee only |
| View own meetings | Any authenticated user |
| Draft / publish / reopen minutes | HR Manager, Super Admin |
| View published minutes | Participants who **accepted** the invitation, plus HR |

All checks run server-side through the hybrid RBAC system (`role_permissions` + `user_page_permissions` overrides). The frontend only hides controls for UX — it never grants access.

## API endpoints

All routes below are registered in `api.php` and auth-protected (JWT bearer). Authoritative list: `API_REFERENCE.md`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/meetings` | Paginated, filterable meeting list (rows carry minutes flags for UI gating) |
| POST | `/meetings` | Create meeting (+ invitations) |
| GET | `/meetings/{id}` | Meeting detail incl. invitations |
| PUT | `/meetings/{id}` | Update meeting |
| DELETE | `/meetings/{id}` | Delete meeting |
| POST | `/meetings/{id}/cancel` | Cancel a scheduled meeting |
| GET | `/meetings/{id}/participants` | Participants with response + attendance status (names resolved server-side) |
| POST | `/meetings/{id}/participants` | Add participant |
| DELETE | `/meetings/{id}/participants/{employeeId}` | Remove participant |
| POST | `/meetings/{id}/confirm` | Invitee accepts (session identity) |
| POST | `/meetings/{id}/decline` | Invitee declines (session identity) |
| POST | `/meetings/{id}/attendance` | HR marks participant attendance |
| GET | `/meetings/{id}/minutes/status` | Minutes flags: exists / status / version / can_* |
| GET | `/meetings/{id}/minutes` | View minutes (permission- & invitation-gated) |
| POST | `/meetings/{id}/minutes` | Create draft minutes |
| PUT | `/meetings/{id}/minutes` | Update draft (or publish with `publish: true`) |
| POST | `/meetings/{id}/minutes/publish` | Publish minutes |
| POST | `/meetings/{id}/minutes/reopen` | Reopen published minutes (reason required) |
| GET | `/meetings/{id}/minutes/options` | Employees + departments for minutes pickers |
| GET | `/my-meetings` | Current user's invitations grouped by tab |
| GET | `/meetings/stats` | Dashboard counters |
| GET | `/meetings/eligible-employees` | Invitee picker source |

## Frontend behaviour

- **CreateMeeting.tsx** — meeting list + create/edit modal + participant picker; row action **Add Minutes** gated by server-provided `row.minutes.can_manage`.
- **MyMeetings.tsx** — employee view: tabs from `/my-meetings`, confirm/decline actions, detail modal with per-participant response + attendance badges, **View Minutes** for published minutes of accepted meetings.
- **MeetingsDashboard.tsx** — counters from `/meetings/stats`.

## Notifications

Invitation, response and cancellation events emit notifications via the Notifications module; channel delivery respects user notification preferences (see [notifications](NOTIFICATIONS.md)).

## Known limitations

- No recurring / series meetings.
- No external calendar (ICS / Outlook) integration.
- QR check-in exists as an invitation type without a standalone kiosk flow.
- Minutes permissions are module-level, not per-meeting delegates.


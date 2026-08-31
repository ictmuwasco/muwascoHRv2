# Meeting Minutes Module

> Status: **Implemented.** Built and verified in this codebase: migration `034_meeting_minutes.sql`, `MeetingMinutesController` / `MeetingMinutesService` / `MeetingMinutesRepository`, `frontend/src/pages/meetings/MeetingMinutesModal.tsx` and `frontend/src/api/services/meetingMinutesService.ts`.

## Purpose

Structured, **official** minutes for a meeting — decisions, action items, attendance and AOB — versioned from draft to published, replacing free-text minutes. Minutes are an organizational record: draft → publish → (reopen for amendment → version bump).

## What minutes capture

| Section | Content |
|---|---|
| Overview | Reference number (server-generated `MMS-{meeting_id}-{YYYY}`), meeting date, start/end time, venue, chairperson, secretary — auto-inherited from the meeting |
| Attendance | Derived from `meeting_invitations` (RSVP status + marked attendance + invitation type). Categories: Present, Apologies, Absent, Guests (QR check-in), Not marked. Names resolved server-side |
| Agenda | Ordered items (position): number, title, presenter, discussion, decision/resolution |
| Decisions | Numbered resolutions with responsible person, department, due date, status (`pending` → `in_progress` → `completed` / `deferred` / `cancelled`) |
| Action items | Action, assignee, department, due date, priority (`low|medium|high|critical`), status (incl. `overdue`), remarks |
| AOB | Free-text any-other-business plus structured AOB items (item / discussion / decision / action / responsible) |
| Next meeting | Date, time, venue, notes |
| Approval trail | `prepared_by/at`, `reviewed_by/at`, `approved_by/at`, `published_by/at` on the header row |

Attendance is deliberately **derived from `meeting_invitations`** — the minutes module creates no second attendance mechanism.

## Lifecycle & versioning (service-enforced)

| Transition | Rule |
|---|---|
| Create draft | Only if no minutes exist for the meeting; requires `meetings.minutes.create` |
| Edit draft | `meetings.minutes.update`; all child rows replaced inside **one DB transaction** (keeps ordering/numbering consistent) |
| Publish | `meetings.minutes.publish`; stamps `published_by/at`; becomes immutable for editors |
| Reopen | `meetings.minutes.amend`; **written reason required** (stored as `amendment_reason`), `version` +1, returns to `draft` |
| View | Drafts: minutes managers only. Published: minutes managers **or** invitees whose `response_status = accepted` |

The service throws typed errors which the controller maps verbatim — double publish → **409 Conflict**, no permission → **403**, unknown meeting/minutes → **404**, invalid payload → **422**. Never a blanket 400.

## Permissions (seeded idempotently by migration 034)

| Permission | Roles |
|---|---|
| `meetings.minutes.create` | super_admin, hr_manager |
| `meetings.minutes.view` | super_admin, hr_manager, dept_head, section_head |
| `meetings.minutes.update` | super_admin, hr_manager |
| `meetings.minutes.publish` | super_admin, hr_manager |
| `meetings.minutes.amend` | super_admin, hr_manager |

Checked server-side via the hybrid RBAC (role permissions + per-user overrides). The UI renders strictly from server-provided flags: `can_create`, `can_edit_draft`, `can_publish`, `can_reopen`, `can_view` from `GET /meetings/{id}/minutes/status`, and `minutes.can_manage` on list rows.

## Database schema (migration `034_meeting_minutes.sql`)

Column-level detail lives in the migration file; the shape is:

```text
meeting_minutes (1 row per meeting — UNIQUE meeting_id)
├── identity     : id, meeting_id → meetings ON DELETE CASCADE, reference_number UNIQUE
├── when/where   : meeting_date, start_time, end_time, venue
├── people       : chairperson_id → employees, secretary_id → employees
├── lifecycle    : status ENUM('draft','published') DEFAULT 'draft',
│                  version INT DEFAULT 1, amendment_reason NULL
├── content      : aob, next_meeting_date/time/venue/notes
├── audit trail  : prepared_by → users + prepared_at, reviewed_by/at,
│                  approved_by/at, published_by → users + published_at
└── timestamps   : created_at, updated_at

meeting_minutes_agenda_items  : minutes_id FK, position, agenda_number, title,
                                presenter_id → employees, discussion, decision
meeting_minutes_decisions     : minutes_id FK, decision_number, resolution,
                                responsible_id → employees, department_id FK,
                                due_date, status
meeting_minutes_action_items  : minutes_id FK, action, assigned_to → employees,
                                department_id FK, due_date, priority, status, remarks
meeting_minutes_aob_items     : minutes_id FK, item, discussion, decision,
                                action, responsible_id → employees
```

Child tables carry indexes on `minutes_id` (plus the filtered columns: `assigned_to`, `due_date`, `status`). Deleting a meeting cascades to its minutes and children. The migration is **idempotent** (re-runnable via `backend/database/run_migration_034.php`) and adds no columns to `meetings` / `meeting_invitations` — existing behaviour is untouched.

## Frontend (`MeetingMinutesModal.tsx`)

- Tabbed editor — **Overview / Attendance / Agenda / Decisions / Action Items / AOB / Publish** — inside the shared `Modal` (responsive, dark-mode aware); not a single textarea.
- Auto-loads existing minutes when `status.exists`; every tab hydrates from the server payload.
- Per-tab add / edit / remove for child items; draft state preserved while switching tabs.
- **Save Draft** vs **Publish** choose create-or-update based on `status.exists`; **Reopen** requires an amendment reason and is blocked client-side without one (server enforces it too).
- Published minutes render read-only for viewers without edit rights; a hard "no permission" state renders when server flags are absent.
- Participant names come from the server (employee names, not numbers — `MeetingRepository::findByIdWithInvitations` aliases `employee_id AS employee_number` and computes `name`); the modal renders them via its `displayNameOf()`/`humanize()` chain.

## API contract

Wire shapes are owned by `frontend/src/api/services/meetingMinutesService.ts`:

| Call | Shape |
|---|---|
| `GET status` | `{ exists, status, version, reference_number, can_create, can_edit_draft, can_publish, can_reopen, can_view }` |
| `GET minutes` | `{ minutes: {header fields}, agenda_items: [], decisions: [], action_items: [], aob_items: [], participants: [] }` |
| `POST / PUT minutes` | Header fields + `agenda_items[]`, `decisions[]`, `action_items[]`, `aob_items[]`; add `publish: true` to publish in one step |
| `POST reopen` | `{ reason }` — required |
| `GET options` | `{ employees: [...], departments: [...] }` for the pickers |

## Audit trail

Every transition writes to `AuditService` under the meetings module: minutes created / updated / published / reopened / viewed (official reads audited), with actor, meeting id and the amendment reason on reopen. See `SECURITY_AUDIT.md` for audit semantics.

## Testing status

Green at ship time with the existing suites (backend 27/27, frontend vitest 42/42). **Dedicated minutes-specific test files do not yet exist** — tracked as a gap in `testing.md`.

## Related documentation

- [meetings.md](meetings.md) — meetings, invitations, RSVP, attendance marking
- [NOTIFICATIONS.md](NOTIFICATIONS.md) — delivery channels and preferences
- [SECURITY_AUDIT.md](SECURITY_AUDIT.md) — hybrid RBAC and audit-log semantics
- [API_REFERENCE.md](API_REFERENCE.md) — authoritative route table
- [testing.md](testing.md) — current suites and coverage gaps



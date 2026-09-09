# PHASE 5 — Backend Domain Architecture, Business Logic & Workflow Engine Report

> Branch: `feature/attendance-push-sms-notifications`
> Scope: backend domain architecture, workflow state machines, authorization,
> transactions, idempotency, audit, error handling, testing.
> Companion docs: `PHASE3_REPORT.md` (controller consolidation), `PHASE4_REPORT.md`
> (database integrity), `BACKEND_CONVENTIONS.md` (canonical layering rules).

---

## 0. Executive summary

Phase 5 executed on the **custom flat PHP framework** (documented in
`ARCHITECTURE_AUDIT.md` §1 — no Laravel at runtime). Laravel-shaped concepts from
the phase mandate were mapped onto the codebase's own conventions:

| Mandate concept | Implemented as |
|---|---|
| Domain Actions | Service classes (`App\Services\…`), thin controllers delegate |
| Form Requests | `App\Validators\*` + enum whitelists matched to schema ENUMs (shape), business checks in services |
| Policies / resource-level authorization | Route permission metadata + `AuthorizationService` + per-record scope checks (`isAuthorisedApprover`, `canViewProfile`, minutes `canManageMinutes`) |
| Domain Exceptions | `App\Services\Attendance\*Exception`, `App\Services\Leave\*Exception` → mapped to 409/422/400 envelopes |
| Events / Listeners | Post-commit side effects + cron (`backend/cron/`) — unchanged pattern, documented |
| Queues | `NotificationRouter` channel pipeline + `notification_logs` idempotency — unchanged |
| Scheduler | Windows Task Scheduler → `backend/cron/*` (idempotent scripts) |

**Delivered:** attendance clock domain extracted to services (5 duplicated
auto-close implementations → 1); dashboard GET de-mutated; leave workflow state
machine enforced with duplicate-approval and balance-reversal fixes; leave
roster write domain extracted with July→June validation; financial-year
resolution centralized; meeting self-invite vulnerability closed; stale/dead
code retired; 36 new unit tests (121 → 158 total, 575 → 700+ assertions, 0
failures).

**Deliberately deferred** (documented debt, per §38 "do not rewrite working
modules unnecessarily"): read-SQL extraction in LeaveController/ReportsController/
DashboardController statistics, EmployeeController upload logic, MonitoringController
mysqli reads, strategic-performance cluster, and the `Helpers\AuthorizationService →
Services\Authorization` namespace move (cosmetic, tracked in `BACKEND_CONVENTIONS.md` §8).

---

## 1. Findings register (final state)

| ID | Severity | Finding | Resolution |
|---|---|---|---|
| P5-1 | CRITICAL | Clock-in/out business logic (geo-fence, office resolution, late calc, transaction, idempotent retry, audit) inline in 1,074-line `AttendanceController` | **FIXED** — `Services/Attendance/AttendanceClockService`; controller is now a thin HTTP mapper |
| P5-2 | CRITICAL | Missed clock-out logic duplicated in 5 places incl. a mutating GET (`DashboardController::autoClockOutPreviousDays`) | **FIXED** — single `Services/Attendance/AttendanceCloseService`; dashboard is read-only; cron + lazy reconcile + ops endpoint all delegate |
| P5-3 | CRITICAL | Stale `AttendanceService`/`AttendanceValidator`/`Models\Attendance` targeted a non-existent schema (`date`, `clock_in_time`) — booby traps | **RETIRED** — deleted after zero-caller verification; `byEmployeeAction` re-wired to `AttendanceRepository` |
| P5-4 | CRITICAL | HR/super-admin bypass could re-approve an already-approved leave → **double balance deduction** | **FIXED** — `LeaveWorkflowRules::assertCanDecide` guard; `code=INVALID_TRANSITION`, HTTP 409 |
| P5-5 | CRITICAL | `LeaveRosterController` write path: raw SQL, no month validation, no audit, private FY fallback | **FIXED** — `Services/Leave/LeaveRosterService` (upsert, July→June month validation → 422 `INVALID_ROSTER_MONTH`, year mapping, audit); FY via central resolver |
| P5-6 | CRITICAL | Exception messages leaked to API clients (`'Database error: ' . $e->getMessage()` — 5 sites) | **FIXED** — safe messages + `\logger()->error(...)` with full context server-side |
| P5-7 | HIGH | LeaveController holds ~400 ln inline SQL (index, delegates, balances) | **PARTIALLY FIXED** — decision endpoints hardened + 409 contract mapped; read-SQL extraction deferred (tracked debt) |
| P5-8 | HIGH | Financial-year resolution duplicated ≥4 places | **FIXED** — `FinancialYearService::resolveCurrentFinancialYearId()` + `yearIdForDate()`; `LeaveProfileService` and the roster now delegate |
| P5-9 | HIGH | Hardcoded leave allocation rules (`FinancialYearService::getLeaveRules()`) and `getAnnualLeaveTypeId()` fallback `: 1` | **OPEN** — documented; moving allocation rules to config is a business-data task (tracked debt) |
| P5-10 | HIGH | Meeting: self-invite vulnerability — `updateInvitationResponse` INSERTed an `hr_invited` row for any non-invited RSVP caller; unvalidated `attendance_status`/`status` inputs | **FIXED** — invitation-existence check in RSVP (self-invite blocked); `MeetingRules` whitelists lifecycle + attendance statuses (422) |
| P5-11 | HIGH | Leave email templates exist with no send-site; approvals complete silently | **VERIFIED/OPEN** — see §5.2; needs a business decision on recipients before wiring |
| P5-12 | MED | Mixed response emission (`$this->json` legacy shapes vs `ApiResponse` envelope) | **CONTAINED** — preserved verbatim where the SPA consumes non-envelope keys (clock 403 `OUTSIDE_RADIUS` payload); documented |
| P5-13 | MED | No audit on leave decisions | **VERIFIED** — controller-level audits already exist (approve/reject/invalidate/cancel); roster writes now audited in the service |
| P5-14 | MED | Fat controllers from Phase-3 Wave 4b | **PARTIALLY FIXED** — Attendance + Dashboard mutation removed; rest deferred (tracked) |
| P5-15 | MED | Authorization engine in `Helpers` | **DEFERRED** — namespace move is cosmetic; tracked in conventions §8 |
| P5-16 | MED | Test gaps for critical workflows | **FIXED (unit layer)** — 36 new tests across attendance rules, close-service idempotency, leave state machine, roster calendar, meeting rules; feature/HTTP layer documented in §7 |
| P5-17 | LOW | `Auth::getInstance()` inside services; envelope-shaped service returns | **OPEN (accepted)** — consistent with the framework's established style |
| P5-18 | LOW | Rate limits: login brute-force protected; clock endpoints unthrottled | **OPEN (accepted)** — auth + geo-fence + idempotency make abuse low-value |
| P5-19 | LOW | Latent dead routes (`store/show/update/destroy` → non-existent controller actions = 500s) | **FIXED** — removed from `api.php` with explanatory comment |

---

## 2. Architecture map (after Phase 5)

```
React SPA
   ↓  JSON / httpOnly access_token cookie / X-Request-ID
api.php  (front controller: CORS, SecurityMiddleware, AuthenticationMiddleware,
          route registrations with module:action ACL metadata)
   ↓
Controller      — session identity, request shaping, outcome/exception → HTTP
   ↓
Validator/Whitelist — shape/format; enums matched to schema ENUMs (§19)
   ↓
Domain Services — THE authoritative business engine:
   Services/Attendance/{AttendanceClockService, AttendanceCloseService}
   Services/Leave/{LeaveWorkflowRules, LeaveRosterService, Leave*Service}
   Services/MeetingRules · MeetingService · MeetingMinutesService
   Services/FinancialYearService (single July→June resolver)
   ↓
Transactions (mysqli begin/commit/rollback inside services only)
   ↓
Repositories / Models / db() helper → MySQL
   ↓
AuditService (WHO/WHAT/WHEN/target/metadata) · NotificationRouter (push/SMS)
   ↓
ApiResponse envelope  { success, message, data | error{code, request_id} }
```

Domain boundaries enforced (mandate §3): attendance never re-implements
leave-overlap rules (uses `CalendarContextService`); the roster never touches
balances or applications; minutes access is enforced in `MeetingMinutesService`;
financial-year answers come from one service.

## 3. Leave workflow state machine (§5/§6)

Documented and enforced in `App\Services\Leave\LeaveWorkflowRules` (unit-tested):

```
applied → pending_subsection_head → pending_section_head → pending_dept_head
        → pending_managing_director → pending_bod_chair
        (variants: pending_hr / pending_hr_manager / pending_manager by role)

pending_*  → approved    (final stage; balances deducted in the same transaction)
pending_*  → rejected    (balances untouched)
pending_*  → cancelled   (applicant only; balances untouched)
pending_*  → invalidated
approved   → invalidated (formal reversal; balances RESTORED in the same transaction)
```

Forbidden forever: `rejected → approved`, `approved → pending`,
`cancelled → approved`, `approved → rejected`, any decision on a terminal
state, re-approval of `approved` (the HR/super-admin bypass path that caused
P5-4). Violations return **409 `INVALID_TRANSITION`** (previously a misleading
400 or, for the HR bypass, a silent double deduction).

## 4. Leave roster (§7) — planning only

`Services/Leave/LeaveRosterService` owns roster writes. Guarantees:
- scheduling NEVER deducts balances, NEVER creates applications, NEVER marks absence;
- planned months must be the July→June calendar months (422 `INVALID_ROSTER_MONTH`);
- `scheduled_year` is derived from the FY start + July/January split;
- every write is audited (`LeaveRoster` target, metadata with employee/FY/month).

---

## 5. Attendance (§9–§13) and meetings (§14–§16)

### 5.1 Attendance
- `AttendanceClockService`: server-authoritative clock in/out — session
  identity only, GPS accuracy cap, Haversine geofence (403 `OUTSIDE_RADIUS`
  with distance/allowed_radius), duplicate clock-in answered idempotently
  (pre-check + `uk_attendance_employee_date` 1062 backstop), clock-out only
  against today's session (`NOT_CLOCKED_IN` otherwise), late status by
  `ATTENDANCE_LATE_CUTOFF` (default 08:30, boundary preserved: 08:30 is NOT late).
- **New business rule (§10, env-gated):** clock-in during APPROVED leave is
  blocked with **409 `ON_APPROVED_LEAVE`**. Default enabled;
  `ATTENDANCE_BLOCK_CLOCKIN_ON_LEAVE=false` restores pre-Phase-5 behaviour.
- `AttendanceCloseService`: the single missed-clock-out implementation
  (batch sweep + per-employee lazy reconcile). Idempotent by construction;
  batch sweeps audited. Consumers: `cron/auto_clockout.php`, ops endpoint,
  dashboard-card lazy reconcile. `DashboardController` GET no longer mutates.

### 5.2 Notifications (§25) — verified
The push/SMS reminder pipeline (`NotificationRouter` +
`AttendanceReminderEligibilityService` + `notification_logs` UNIQUE idempotency)
already satisfied the mandate. **Open item:** the leave email templates under
`Templates/Emails/Leave/` have no production send-site (approvals are silent).
Wiring them requires a recipient decision (applicant only? next approver?
delegate?) — deliberately not guessed.

### 5.3 Meetings
- `MeetingRules` (unit-tested): lifecycle `scheduled → ongoing → completed /
  cancelled`; RSVP open only for `scheduled|ongoing`; `attendance_status`
  whitelist `present|absent|excused|not_marked` (matches migration 017 ENUM).
- **Security fix (§15):** RSVP no longer self-invites — `confirmAttendance` /
  `declineAttendance` require an existing invitation row (previously the
  repository silently INSERTed an `hr_invited` row for any caller).
- `PUT /meetings/{id}` validates `status` against the lifecycle (422 otherwise).
- Minutes access rule unchanged and verified (mandate §16 satisfied):
  published minutes visible only to `meetings:manage` holders or invitees with
  `response_status='accepted'`; enforced in `MeetingMinutesService`.

## 6. Transactions, idempotency, audit, errors

- Transactions remain inside services only (leave approval/deduction,
  minutes persistence, clock-in insert). No new transaction wraps simple reads.
- Idempotency: clock-in (pre-check + unique key), auto-close (`WHERE clock_out
  IS NULL`), reminders (`notification_logs` UNIQUE), roster upsert, duplicate
  leave decisions (now blocked, not double-applied).
- Audit: leave decisions + roster writes + attendance clock events + batch
  closes are recorded with actor/target/metadata. Audit failures never break
  the main flow (AuditService contract).
- Error handling: no service leaks `$e->getMessage()` to clients anymore
  (verified by grep); log-to-file keeps full context. New client-facing codes:
  `INVALID_TRANSITION` (409), `ON_APPROVED_LEAVE` (409), `INVALID_ROSTER_MONTH`
  (422), `NOT_CLOCKED_IN` (400, pre-existing contract preserved).

## 7. Testing strategy and results

- Suite: **158 tests / 700+ assertions / 0 failures / 1 skipped** (baseline was
  121/575 from Phase 3 — all pre-existing tests still pass untouched).
- New unit tests (no DB required, following the repo's established pattern):
  - `Unit/Services/Attendance/AttendanceClockRulesTest` — request validation,
    accuracy cap, geofence inside/outside/fallback, late-boundary, leave switch.
  - `Unit/Services/Attendance/AttendanceCloseServiceTest` — no-op when clean,
    single batch update, **idempotent repeated runs**, per-employee reconcile.
  - `Unit/Services/Leave/LeaveWorkflowRulesTest` — pending/terminal decidability,
    invalid-transition map, invalidate-from-approved-only, message specificity.
  - `Unit/Services/Leave/LeaveRosterRulesTest` — July→June month validation
    (case-sensitive), FY-start→calendar-year mapping.
  - `Unit/Services/Meetings/MeetingRulesTest` — lifecycle, RSVP window,
    ENUM whitelists.
- Known gap (documented, tracked): HTTP/feature-layer tests require a live
  schema (the CI suite intentionally excludes `requires-db`); the decision
  guards are covered at the rules layer they delegate to.

## 8. Definition of done — status

| Criterion | Status |
|---|---|
| Major domains have clear boundaries | ✅ attendance / leave / roster / meetings / FY |
| Fat controllers addressed where necessary | ✅ Attendance, Dashboard mutation, roster writes (rest tracked) |
| Business rules enforced by the domain layer | ✅ services + rules classes |
| Leave workflow centralized + roster separate | ✅ |
| Financial-year logic centralized | ✅ |
| Attendance authoritative; duplication prevented | ✅ (5 copies → 1) |
| Missed clock-out safe + idempotent | ✅ |
| Meeting workflows consistent; access authorized; minutes visibility per rule | ✅ (self-invite fixed) |
| Write endpoints validated | ✅ (new whitelists + guards; pre-existing validators untouched) |
| Critical workflows transactional | ✅ (verified/kept) |
| Idempotency risks addressed | ✅ (incl. the double-deduction bug) |
| Notifications centralized; audit consistent; errors standardized; no internal leakage | ✅ (leave-email send site open, documented) |
| IDOR reviewed | ✅ (meeting self-invite fixed; RSVP derives identity from session; minutes rule enforced) |
| Critical workflows tested; existing functionality working; CI passing | ✅ 158 green |
| Backend documentation updated | ✅ this report + conventions/module docs |

## 9. Dead code removed (zero references verified first)

`Models/Attendance.php` · `Services/AttendanceService.php` ·
`Services/Contracts/AttendanceServiceInterface.php` ·
`Validators/AttendanceValidator.php` · 4 latent attendance routes in `api.php`.
Original `AttendanceController` preserved at
`backend/_legacy/AttendanceController.pre-phase5.php.txt`.

## 10. Follow-up backlog (tracked, do not lose)

1. Extract LeaveController read SQL → `LeaveProfileService`/`DelegateService` (P5-7).
2. Delegate `ReportsController` to the existing `LeaveReport*`/`AttendanceReport*` services.
3. Extract `DashboardController` statistics into a query service; strategic-performance
   cluster + `MonitoringController` (recipe: Workplan extraction, Phase 3).
4. Move leave allocation rules from `FinancialYearService::getLeaveRules()` to config.
5. Decide recipients and wire leave email notifications post-commit.
6. Move `Helpers\AuthorizationService` → `Services\Authorization` (compat alias).
7. `leave_applications.id` uses `MAX(id)+1` allocation inside `insertApplication`
   — concurrent submissions may collide (unique-PK failure, user retries).
   Consider auto-increment in a Phase 4-style migration.

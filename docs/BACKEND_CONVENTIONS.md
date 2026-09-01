# Backend Conventions & Architecture Standards (Phase 3)

This document is the authoritative guide for backend code organization in this
project. It reflects the architecture **as it is** after the Phase 3
consolidation (see `ARCHITECTURE_AUDIT.md` for the audit that produced it).

The stack is a custom flat PHP 8 framework (no Laravel at runtime): a single
front controller (`api.php`), a small core (`backend/app/Core`),
`backend/bootstrap.php` for bootstrapping, and mysqli-backed data access.

---

## 1. Request lifecycle (canonical)

```
HTTP Request
    ↓
api.php  (front controller: bootstrap, CORS from backend/config/cors.php,
          security headers, router + 270 route registrations)
    ↓
SecurityMiddleware            (headers, session timeout, body limits, CSRF)
AuthenticationMiddleware      (session/JWT auth gate + public-route allowlist)
AuthorizationMiddleware       (per-route permission metadata, e.g. 'leave:view')
    ↓
Controller   (thin: parse input → validateRequest() → delegate → respond)
    ↓
Service      (business rules, transactions, audit logging, orchestration)
    ↓
Repository / Model  (data access; repositories for complex query reuse)
    ↓
MySQL
    ↓
ApiResponse envelope  ({ success, message, data } / { success, message, error })
```

Rules of thumb:

- **Routes** never contain business logic; they define method, path, controller
  action and the server-side permission metadata.
- **Middleware** never contains module-specific workflows.
- **Controllers** never query the database directly, never open transactions,
  never emit raw `echo json_encode`. They validate shape, delegate, and respond.
- **Services** own business rules, multi-table transactions, audit events and
  cross-module orchestration.
- **Repositories** exist where they earn their keep (complex query reuse,
  query isolation, testability). Simple static model CRUD does not need one.

## 2. Where should new code go?

| Need | Location |
|---|---|
| New endpoint | Route in `api.php` (+ permission metadata), action in the module's controller |
| New business rule | Service method (never controller, never validator) |
| Input shape/format validation | `App\Validators\*Validator` (shape only) via `BaseController::validateRequest()` |
| Business validation (balance, conflict, uniqueness, scope) | Service layer (throws or returns structured result) |
| Authorization decision | Route metadata + `BaseController::requirePermission()`; engine: `App\Helpers\AuthorizationService` |
| Complex/reused query | Repository method |
| Simple CRUD | Model (`App\Models\*`) |
| Audit event | `\App\Services\AuditService::getInstance()->log(...)` — called from services, not controllers |
| Notification | `App\Services\Notification\*` channel services |
| Response | `$this->success(...)` / `$this->error(...)` (BaseController) — never raw `echo json_encode` |
| Env-dependent config | `backend/config/*.php` + `config('dot.key')` — never hardcoded values |
| One-off data fixes | `scripts/` (temporary, deleted after use) |

## 3. Response envelope (single source of truth)

`App\Helpers\ApiResponse` is the only place that prints a response body.

- Success: `{ "success": true, "message": "...", "data": ... }` (HTTP 2xx)
- Failure: `{ "success": false, "message": "...", "error": { "code": "...",
  "request_id": "...", "reference": "...", "details": {...} } }` (HTTP 4xx/5xx)
- `X-Request-ID` is always attached for log correlation.
- Validation failures: HTTP 422, code `VALIDATION_ERROR`, `details` maps
  field => message; `message` is the first field error.
- Exception messages must never reach the client; log them with `\logger()`.

## 4. Validation split

- **Request validation** (`App\Validators\*`): shape/format only — required,
  email, date, length, enum membership. No DB lookups, no business rules.
- **Business validation** (services): uniqueness, existence, balances,
  conflicts, scope/permission-dependent rules. May hit the database.
- Never duplicate a business rule in both layers.

## 5. Transactions

Multi-write workflows (e.g. leave approval → balance update → audit →
notification) belong in **services**, wrapped in a transaction
(`Helpers\Database` connection: `begin_transaction/commit/rollback`).
Controllers never open transactions. Cross-module notifications that can be
eventual are sent after the transaction commits (or via the cron scripts in
`backend/cron`).

## 6. Naming & style

- Controllers: `*Controller`, actions `*Action` (route-compatible verbs like
  `catalog`/`stats` are also supported by the router but prefer `*Action`).
- Services: `*Service`, one domain per service; no God services.
- Validators: `*Validator extends BaseValidator`, implement
  `performValidation()` using the `validate*()` helpers only.
- Repositories: `*Repository` (+ `Contracts\*RepositoryInterface` when a
  service depends on the abstraction).
- Resources: `App\Http\Resources\*Resource::toArray/collection` for entity
  shaping used by more than one endpoint.
- All files: `declare(strict_types=1);`, namespaced, PSR-4 via Composer.
- Type-hint parameters/returns; add PHPDoc only where it explains **why**.

## 7. Testing

- PHPUnit config: `phpunit.xml.dist` (bootstrap: `backend/bootstrap.php`).
  Do not reintroduce a bare `phpunit.xml`.
- Unit tests: `backend/tests/Unit/**` — fast, no live DB required.
- Feature/script tests: `backend/tests/*.php`.
- Services must be testable without HTTP: constructor-inject repositories;
  avoid static singletons inside new code; never read `$_GET/$_POST` in
  services (controllers pass data in).
- Tests that simulate infrastructure failure must force the failure path
  (e.g. `setFailedMysqli($fake)`) rather than depending on live-service state.

## 8. Known architectural debts (tracked, do not extend)

- God controllers remaining: Dashboard, LeaveRoster, StrategicPlan, Reports,
  Attendance (partial), Employee (partial), Monitoring, and the strategic
  performance cluster (AppraisalCycle, PerformanceContract, KPI,
  SectionalObjective). Extraction recipe: mirror `WorkplanController` →
  `WorkplanService` + `WorkplanQueryService`.
- `App\Helpers\AuthorizationService` (the authorization engine) lives in
  `Helpers`; moving it to `Services\Authorization\` is planned and must keep a
  compatibility alias.
- `AuthService` uses a setter dependency bag; constructor injection preferred.
- Laravel-style vendor skeletons in `vendor/laravel` and
  `backend/app/Console` remain unused (documented in
  `backend/_legacy/README.md`); do not build on them.
### 8.1 Phase 5 update (authoritative — supersedes the list above where they overlap)

Resolved in Phase 5 (see `PHASE5_REPORT.md`):

- Attendance clock-in/out logic extracted to `Services/Attendance/AttendanceClockService`;
  missed-clock-out closing consolidated into `Services/Attendance/AttendanceCloseService`
  (cron, ops endpoint and the per-employee lazy reconcile all delegate — do not re-implement).
- `DashboardController` is read-only (the mutating GET auto-clock-out was removed).
- Leave workflow transitions enforced by `Services/Leave/LeaveWorkflowRules`:
  duplicate approvals blocked (409 `INVALID_TRANSITION`); invalidation of an
  approved application restores balances.
- Leave roster writes moved to `Services/Leave/LeaveRosterService` with
  July→June month validation (422 `INVALID_ROSTER_MONTH`) and audit.
- Financial-year resolution centralized: `FinancialYearService::
  resolveCurrentFinancialYearId()` / `yearIdForDate()` — never write a new
  "current FY" query.
- Stale-schema artifacts removed: `Models/Attendance.php`,
  `Services/AttendanceService.php` (+ interface), `Validators/AttendanceValidator.php`.
- Meeting RSVP security: self-invite path closed (invitation must exist);
  lifecycle/attendance statuses validated via `Services/MeetingRules`.

New conventions introduced in Phase 5:

- Pure business-rule classes (`*Rules`, static, no DB, unit-testable):
  `Services/Leave/LeaveWorkflowRules`, `Services/MeetingRules`. Services
  delegate state-transition decisions to them; controllers map results to
  409/422 envelopes.
- Domain exceptions live next to their domain
  (`Services/Attendance/*Exception`, `Services/Leave/*Exception`) and carry
  client-safe context; never leak `$e->getMessage()` into responses.

Still open (deferred deliberately — tracked in `PHASE5_REPORT.md` §10):

- Read-side SQL extraction: LeaveController (~400 ln), DashboardController
  stats, LeaveRoster reads, ReportsController, MonitoringController,
  EmployeeController uploads, strategic-performance cluster.
- Leave allocation rules hardcoded in `FinancialYearService::getLeaveRules()`;
  `getAnnualLeaveTypeId()` fallback `: 1`.
- `App\Helpers\AuthorizationService` namespace move to `Services\Authorization`
  (cosmetic; keep a compatibility alias).
- `leave_applications.id` allocated via `MAX(id)+1` (concurrency collision risk).
- Leave email templates have no send-site yet (recipient decision pending).


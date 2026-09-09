# Backend Architecture Audit (Phase 3)

> Date: 2026-08-31 · Branch: `feature/attendance-push-sms-notifications`
> Authoritative inventory of the backend before Phase 3 consolidation.
> Supplementary documentation for the PHASE3 report — see `docs/PHASE3_REPORT.md`.

## 1. What this backend actually is

The application does **not** use the Laravel framework at runtime. It runs a
**custom, flat PHP 8 framework**:

```
React SPA (axios + fetch clients)
   │  JSON, X-Request-ID, httpOnly `access_token` cookie
   ▼
api.php  (root front controller, 582 lines)
   ├─ SecurityMiddleware::run()            → security headers, session timeout, trusted proxy
   ├─ AuthenticationMiddleware::process()  → single auth gate (public-route allowlist)
   ├─ ApiRouter (inline class)             → 270 route registrations + reflection dispatch
   │     └─ AuthorizationMiddleware::enforce("module:action")  → server-defined route ACL
   ▼
Controllers (38)  →  Services (60+)  →  Repositories (13) / Models (15)  →  MySQLi
   ▼
MySQL  — 38 sequential SQL migrations (backend/database/migrations/)
```

Bootstrapping (`backend/bootstrap.php`) wires: Composer autoload, `.env` loading,
global `env()/config()/db()/logger()` helpers, session management with an API
lock-release optimisation, one central exception handler (§12 envelope), and the
observability layer (RequestIdService, ErrorTrackerService, fatal/perf shutdown
hooks). Runtime config lives in `backend/config/{database,observability,
permissions,authz_allowlist}.php`.

## 2. Component inventory and classification

Legend: ✅ ACTIVE · 🗑 DEAD (no references) · 📦 LEGACY (inert / not executed) ·
⚠ INCONSISTENT · ♻ REQUIRES REFACTORING

### 2.1 Controllers (backend/app/Controllers)

| File | Lines | Class | Notes |
|---|---|---|---|
| HR/WorkplanController | 2 282 | ⚠ CRITICAL candidate | Raw SQL, inline transactions, audit + cascade validation in controller; no service layer |
| Leave/LeaveController | 1 376 | ♻ HEAVY | Good service delegation but private helpers duplicate service queries; raw `json_encode` in 2 spots |
| AttendanceController | 1 074 | ♻ HEAVY | Service+repos used; geo-fence, audit + a transaction block still inline |
| Employee/EmployeeController | 943 | ♻ HEAVY | ~10 inline AuditService calls; file-upload logic inline |
| System/MonitoringController | 701 | ♻ HEAVY | Raw `mysqli` stats queries; capture delegated to ErrorTracking service |
| Leave/LeaveRosterController | 663 | ♻ HEAVY | Raw SQL only, no service |
| DashboardController | 578 | ♻ HEAVY | 12 inline count queries + auto-clockout logic |
| HR/StrategicPlanController | 535 | ♻ HEAVY | Raw SQL + OrgScope inline |
| Reports/ReportsController | 515 | ♻ HEAVY | Raw SQL overlapping dedicated LeaveReport/AttendanceReport services |
| HR/AppraisalCycleController | 423 | ⚠ MODERATE | Raw SQL + direct `RBAC::getInstance()` (legacy authz bypass) |
| HR/PerformanceContractController | 406 | ⚠ MODERATE | Raw SQL + OrgScope inline |
| HR/FinancialYearController | 380 | ⚠ MODERATE | FinancialYearService used but still calls RBAC directly |
| HR/ConsentController | 352 | ⚠ MODERATE | Model carries heavy business logic |
| HR/KPIController | 254 | ⚠ MODERATE | Raw SQL + OrgScope inline |
| Reports/AttendanceReportController | 268 | ✅ | Delegates to AttendanceReport services |
| Employee/UserController | 251 | ✅ | Uses UserService + UserResource |
| Auth/AuthController | 249 | ⚠ MODERATE | AuthService used, 4 inline AuditService calls |
| HR/SectionalObjectiveController | 219 | ⚠ MODERATE | Raw SQL + OrgScope inline |
| Settings/PermissionController | 202 | ✅ | PermissionService + AuditService |
| HR/PayrollController | 172 | ⚠ MODERATE | Raw SQL |
| Settings/AuditLogController | 166 | ✅ | Delegates to AuditService |
| HR/DepartmentController | 161 | ✅ | DepartmentService |
| HR/SectionController | 161 | ✅ | DepartmentService |
| HR/SubsectionController | 155 | ✅ | DepartmentService |
| HR/ComplaintController | 154 | ✅ | — |
| Notifications/AdminNotificationController | 151 | ✅ | AppTime helper |
| Meeting/MeetingMinutesController | 145 | ✅ | MeetingMinutesService |
| Notifications/PushSubscriptionController | 139 | ✅ | — |
| Notifications/NotificationTestController | 133 | ✅ | — |
| HR/HolidayController | 126 | ✅ | — |
| HR/AppraisalController | 118 | ✅ | — |
| Notifications/NotificationPreferencesController | 70 | ✅ | — |
### 2.3 Repositories (13) and Models (15)

✅ **Repositories are genuinely useful** for complex queries (EmployeeRepository
709, MeetingRepository 613, MeetingMinutesRepository 494, LeaveRepository 437,
DepartmentRepository 250). They reconcile toward the model's public contract and
stay. Simple CRUD is done through `BaseModel` static helpers.
⚠ **Models contain business logic**: `Consent` (758) and `UserPagePermission`
(405) exceed thin data access.

### 2.4 Validators (backend/app/Validators)

🗑 **Entirely unused in production.** No controller references any validator.
All validation is inline. `BaseValidator` + `ValidatorInterface` +
{Attendance,Auth,Department,Employee,Leave,User}Validator are dormant.

### 2.5 Responses / Resources

- ✅ `UserResource` (+ `ApiResourceInterface`) — used by UserController.
- ✅ `App\Http\Resources\FinancialYearResource` — used by FinancialYearController.
- 🗑 `JsonResponse`, `EmployeeResource`, `LeaveResource`, `AttendanceResource`,
  `DepartmentResource` — zero references (removed in Phase 3).

### 2.6 Middleware

- ✅ Active: `SecurityMiddleware::run()`, `AuthenticationMiddleware::process()`,
  `AuthorizationMiddleware::enforce()/check()`, `BaseMiddleware`,
  `MiddlewareInterface`.
- 🗑 `AuthMiddleware` — zero references (removed in Phase 3).

### 2.7 Policies / Gates

- 🗑 `App\Policies\EmployeePolicy`, `App\Gates\Gate` — zero references (removed
  in Phase 3; working-tree copies preserved under `backend/_legacy/` for the
  uncommitted AuthorizationService fixes they contained).

### 2.8 Routes

- ✅ Live router: the inline `ApiRouter` inside `api.php`, **270 routes**, each
  with an optional `"module:action"` route-ACL permission.
- 🗑 `backend/routes/routes/{api,web,channels,console}.php` — Laravel-style
  route files never executed by any bootstrap (removed in Phase 3). Only the
  legacy `LeaveRoleBasedAccessTest` read `routes/routes/api.php` as text.

### 2.9 Configuration

- ✅ Runtime-loaded by `config()`/`env()`: `backend/config/{database,
  observability,permissions,authz_allowlist}.php` + `.env`.
- 🗑 `backend/config/config/*` (16 Laravel-style files) — never loaded
  (removed in Phase 3; `jwt.php` working copy preserved in `backend/_legacy/`).

### 2.10 Database tooling

- ✅ SQL migrations (38) in `backend/database/migrations/` + `Migration.php` runner.
- 🗑 Laravel-style `backend/database/database/{migrations,seeders,factories}`
  (removed in Phase 3).
- ⚠ One-off runners (`run_migration.php`, `run_migration_031..034.php`,
  `apply_notification_migrations.php`) — historical; kept as dead-code report
  "requires verification".
- ⚠ `composer.json` `migrate`/`seed` scripts point at non-existent
  `backend/database/migrate.php` / `seed.php`.

### 2.11 Cron / CLI

✅ `cron/attendance_reminders.php`, `cron/auto_clockout.php`,
`cron/error_retention.php` — schedule-driven, idempotent, documented.

### 2.12 Tests

- ✅ PHPUnit Unit + Feature suites (phpunit.xml.dist canonical; phpunit.xml
  local-dev copy gitignored).
- ✅ Error-tracking unit tests, authz unit tests, Employee controller/repo/service
  tests, legacy standalone script tests in `backend/tests/*.php`.
- ⚠ `backend/tests/bootstrap.php` defines a `TestDatabase` mock but the phpunit
  configs bootstrap `backend/bootstrap.php`, so the mock is never active
  (kept for the standalone script tests that require it).

### 2.13 Logging / Error tracking

✅ Central exception handler (§12 envelope, request-id correlation, fail-safe
ErrorTrackerService, performance/fatal hooks); `Helpers\Logger` for structured
app logs; `AuditService` for the audit trail.
| Settings/NotificationController | 66 | ✅ | — |
| Settings/SettingController | 49 | ✅ | — |
| BaseController | 252 | ✅ | Envelope helpers, auth/permission helpers, request parsers |

### 2.2 Services (backend/app/Services)

**Healthy / cohesive:** AuthService, EmployeeService, UserService,
DepartmentService, AuditService, PermissionService, LeaveCalculationService,
LeaveWorkflowService, LeaveDocumentService, LeaveAttachmentService,
FinancialYearService, HolidayService, MeetingService, MeetingMinutesService,
AttendanceService, NotificationService, the `Services/Notification/*` channel
suite, the `AttendanceReport/*` and `LeaveReport*` reporting families, and the
`ErrorTracking/*` suite (fail-safe, unit-tested).

**Oversized but cohesive:** LeaveProfileService (1 128), LeaveApprovalService
(900), AttendanceDashboardService (750), EmployeeService (592).

**Placement issue:** the single authorization engine `App\Helpers\
AuthorizationService` (706) plus `RBAC`, `Auth`, `JWT`, `Session`,
`OrgScope` are domain services living under `Helpers`.

**Contract inconsistency:** only 7/60+ services implement a `Services/Contracts`
interface. No DI container — controllers `new` services and use setter injection.
## 3. Architecture findings summary

| ID | Severity | Finding | Root cause |
|---|---|---|---|
| C1 | CRITICAL | Routing embedded in the web-accessible `api.php` | 270 `$router->add()` calls + router engine + bootstrap in one file; not testable in isolation |
| C2 | CRITICAL | WorkplanController = 2 282-line God controller | No service layer; raw SQL + transactions + validation + audit in one class |
| C3 | CRITICAL | Validator layer exists but is unused | Validators never wired; validation inline with inconsistent shapes |
| C4 | CRITICAL | Response envelopes inconsistent (raw `json_encode(['error'=>…])` in controllers) | BaseController envelope coexists with ad-hoc shapes |
| H1 | HIGH | Raw SQL in ~12 controllers vs Repository/Service pattern elsewhere | Incremental growth without a shared query layer |
| H2 | HIGH | Transaction boundaries inconsistent (controllers vs services) | No convention for where transactions belong |
| H3 | HIGH | Authorization domain code in `Helpers`; direct `RBAC` calls still exist | Historical growth; `AuthorizationService` is the de-facto engine |
| H4 | HIGH | Laravel scaffolding dead-weight in routes/config/bootstrap/database | Abandoned framework migration |
| H5 | HIGH | Auth duplication: `Auth` helper vs `AuthService` vs middleware | Multiple generations of auth code |
| M1..M10 | MEDIUM | CORS origins ×4, hardcoded DB `+03:00`, God services, service-locator bag, dual naming, stale docs, untracked .dist | See PHASE3_REPORT.md |

## 4. Request lifecycle (target, Phase 3)

```
HTTP Request -> Routes (api.php) -> Middleware (Security -> Authn -> Authz)
-> Controller (validate -> delegate) -> Service (business rules + transactions)
-> Repository/Model (data access) -> MySQL -> Standard JSON envelope -> Response
```

Frontend compatibility contract: responses are `{ "success": bool,
"message": string, "data": mixed }` (success) or `{ "success": false,
"message": string, "error": { "code", "request_id", "reference", "details" } }`
plus the `X-Request-ID` header. The SPA reads `data.error || data.message` for
failures and adopts `x-request-id`.
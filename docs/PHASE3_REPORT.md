# PHASE 3 — Backend Architecture, Code Organization & Technical Debt Consolidation Report

Status: **COMPLETE** (Waves 0–3 executed; Wave 4 partially — see Controller Audit).
Regression state: `phpunit --no-coverage` → **121 tests, 575 assertions, 0 failures, 1 skipped**; full `php -l` sweep of `backend/app` clean.

---

## 1. Architecture Findings

| ID | Severity | Affected files | Description / Root cause | Impact | Fix implemented |
|---|---|---|---|---|---|
| C1 | CRITICAL | `api.php` | 270 routes + router engine + bootstrap in the web-accessible entrypoint | Every route change touches the live entrypoint | Accepted structural risk, documented; extraction deferred to avoid late-phase regressions |
| C2 | CRITICAL | `Controllers/HR/WorkplanController.php` (2 282 ln, 37 SQL, 2 inline tx) | God controller: state machine, scope filtering, validation, audit, transactions inline | Untestable; high regression risk | Extracted `Services/Workplan/WorkplanService` (state machine, WITHIN-cascade assignment, transactions) + `WorkplanQueryService` (scope reads); controller delegates |
| C3 | CRITICAL | `Validators/*`, controllers | Validator layer had **zero** production callers; DB-uniqueness ran on every create/update; `LeaveValidator` validated a non-existent column (`employment_date`) | Double queries; drift from schema | Rewrote 4 shape-only validators (Department, User, Employee, Leave) with correct fields; wired via new `BaseController::validateRequest()` (standard 422) into Leave, Department, Employee, User |
| C4 | CRITICAL | `LeaveController` (31), `EmployeeController` (2), `SecurityMiddleware` (4), `BaseMiddleware`, `AuthorizationService` | Raw `echo json_encode` ad-hoc responses; exceptions leaked (`$e->getMessage()`, file/line) | Unstable client contract; information disclosure | All converted to `Helpers\ApiResponse` (single envelope source of truth, now with `X-Request-ID` + output-buffer hygiene); leaks replaced with safe messages + server logs |
| H1 | HIGH | ~12 controllers raw SQL | No shared query layer for complex reads | Duplication, untestable | Workplan reads consolidated into `WorkplanQueryService` (proven pattern); rest queued (Wave 4b) |
| H2 | HIGH | Workplan (2), Attendance, Consent model | Transactions in controllers/models vs services elsewhere | Inconsistent rollback semantics | Workplan transactions moved into `WorkplanService` (correct layer) |
| H3 | HIGH | `FinancialYearController`, `Helpers/RBAC.php` | Legacy `RBAC::` bypass of the central authorization engine — active when APP_DEBUG=1 | Privilege-escalation risk in dev/staging | RBAC path removed; controller uses `AuthorizationService::canAccess/requirePermission` |
| H4 | HIGH | `FinancialYearController` | Dev-mode auth hole + leaked exception details + raw `php://input` | Debug-mode compromise; error disclosure | Auth hole closed; envelope errors; `$this->getJsonBody()` |
| H5 | HIGH | Auth helper / AuthService / middleware | Three generations of authn code | Drift, confusion | Boundaries documented (Auth = session identity facade; AuthService = credential workflows; middleware = request gate) |
| M1 | MEDIUM | 4 files | CORS allowlist duplicated | Divergent origin lists | Centralized in `backend/config/cors.php` (`config('cors.allowed_origins')`) |
| M2 | MEDIUM | `Helpers/Database.php` | Hardcoded `+03:00` DB timezone | Silent wrong-time behavior | Env-driven `DB_TIMEZONE` (default preserved) |
| M3 | MEDIUM | Workplan/Employee controllers | Class-wide `$this->db` coupling | Hidden dependencies | Removed from Workplan (service owns DB); Employee queued |
| M4 | MEDIUM | `LeaveController` helpers | Duplicated leave-type / financial-year lookups vs services | Divergent logic risk | Documented for Wave 5 consolidation |
| M5 | MEDIUM | docs, cron docblock | Stale docs ("no composer", deleted config dirs) | Misleads developers | ARCHITECTURE_AUDIT.md, PHASE3_REPORT.md, BACKEND_CONVENTIONS.md written; stale claims corrected |
| M6 | MEDIUM | `phpunit.xml` vs `.dist` | Legacy config silently skipped newer tests | False green confidence | `.dist` canonical (tests 100 → 121) |
| M7 | MEDIUM | `ErrorTrackerFailSafeTest` | Tests depended on live MySQL reachability | Flaky CI | Deterministic via PSR-3 stub capture + fail-safe verification |

---

## 2. Backend Architecture Map

```
HTTP Request
  ↓ api.php (SecurityMiddleware → AuthenticationMiddleware → AuthorizationMiddleware)
  ↓ Routes (inline ApiRouter, per-route permission metadata)
  ↓ Controller (BaseController: request parsing, validateRequest() [shape/format only],
  │             permission gate, response delegation)
  ↓ Service (business rules, state machines, transactions, audit)
  ↓ Repository / Model (query isolation)
  ↓ Helpers\Database (mysqli, prepared statements)
  ↓ MySQL
  ↓ Response (Helpers\ApiResponse envelope + X-Request-ID)
  ↓ Failure path: bootstrap exception handler → structured log + ErrorTracker → same envelope
```

Standards codified in `docs/BACKEND_CONVENTIONS.md`.
﻿# PHASE 3 - Backend Architecture Consolidation Report

> Date: 2026-08-31 | Branch: feature/attendance-push-sms-notifications
> Baseline: 100 tests / 526 assertions / 2 env-dependent failures -> Final: 113 tests / 564 assertions / 0 failures.

## 1. Architecture Findings

| ID | Severity | Affected files | Description / root cause | Impact | Fix implemented |
|---|---|---|---|---|---|
| C1 | CRITICAL | api.php | 270 routes + router engine + bootstrap embedded in the web entrypoint | Untestable routing, god file | Documented; router extraction queued as isolated follow-up |
| C2 | CRITICAL | Controllers/HR/WorkplanController.php | 2282-line God controller: 37 raw-SQL sites, 2 inline transactions, 21 audit refs, state machine + cascade logic in HTTP layer | Untestable, unreviewable, duplicated scope logic | Split into Services/Workplan/WorkplanService (writes, transactions, state machine, audit) + WorkplanQueryService (scope-filtered reads); controller is now a thin delegator (0 SQL) |
| C3 | CRITICAL | Validators/*, all controllers | Validator layer existed but was never wired; validation inline with inconsistent shapes; stale validators enforced a wrong field (employment_date) and repo-coupled business rules | Silent drift between HTTP validation and business rules | BaseController::validateRequest() wired with standard 422 VALIDATION_ERROR envelope; 4 validators rewritten to shape/format only; 6 endpoints migrated |
| C4 | CRITICAL | Leave/LeaveController (31 sites), Employee/EmployeeController (2), SecurityMiddleware (5), AuthorizationService, BaseMiddleware | Raw echo json_encode with {error:...} shapes bypassed the envelope, request-id header and buffer cleanup | Inconsistent frontend error contract; internals leaked in one handler | Single source of truth Helpers/ApiResponse (buffer cleanup, X-Request-ID, nosniff); all sites converted; leaking handlers sanitized |
| H1 | HIGH | 12 controllers | Raw SQL in controllers vs Repository/Service elsewhere | Inconsistent data access | Workplan + FinancialYear migrated; remainder enumerated for Wave 4b |
| H2 | HIGH | Workplan, Attendance, Consent | Transactions in controllers/models vs services | Partial-write risk without clear ownership | Workplan transactions moved into transactional services; convention documented |
| H3 | HIGH | Helpers/AuthorizationService, HR/FinancialYearController | Authorization engine mislocated in Helpers; FinancialYearController bypassed the engine via direct RBAC::getInstance() with a dev-mode auth bypass ($_ENV[PHPUNIT] acting as is_development()) | Inconsistent authorization; auth bypass outside tests | FinancialYearController delegates to the engine (canAccessFinancialYear); RBAC bypass and dev-mode hole removed |
| H4 | HIGH | backend/routes/routes/*, backend/bootstrap/app.php, backend/config/config/*, backend/database/database/* | Abandoned Laravel migration scaffolding | Misleading docs, dead weight | Removed from app tree; preserved under backend/_legacy/ per deployment-safety review |
| H5 | HIGH | Helpers/Auth.php, Services/AuthService.php | Overlapping identity/authn entry points | Confusion over authoritative path | Documented split: Auth = session identity facade, AuthService = credential workflows; login audit consolidated |
| M1 | MEDIUM | api.php, bootstrap.php, BaseController, SecurityMiddleware | CORS origin list duplicated x4 (two disagreed) | Drift risk | Single backend/config/cors.php via config(cors.allowed_origins) |
| M2 | MEDIUM | Helpers/Database.php, backend/config/database.php | Timezone +03:00 hardcoded in connection init | Silent drift risk | Config-driven with documented default |
| M3 | MEDIUM | tests/Unit/Services/ErrorTracking/ErrorTrackerFailSafeTest.php | Test asserted DB absence - failed when MySQL reachable (order-dependent) | Red suite on live environments | Test now asserts the real fail-safe contract |
| M4 | MEDIUM | phpunit.xml vs phpunit.xml.dist | Two configs; tracked one booted a never-used bootstrap | Inconsistent CI/local runs | phpunit.xml untracked; .dist canonical (surfaced 13 hidden tests) |
| M5 | MEDIUM | .gitignore | .dist untracked; phpunit.xml not ignored | Config loss risk | Corrected |

## 2. Backend Architecture Map (as implemented)

```
HTTP Request
  -> api.php (front controller: env/session/error-handling bootstrap,
             SecurityMiddleware -> AuthenticationMiddleware -> ApiRouter)
  -> AuthorizationMiddleware::enforce (per-route permission metadata, engine-backed)
  -> Controller (thin: permission guard -> validateRequest -> service call -> response)
  -> Service (business rules, transactions, audit, notifications)
  -> Repository / Model (data access; mysqli via Helpers/Database)
  -> MySQL
  -> Helpers/ApiResponse envelope {success, message, data | error{code, request_id, reference, details}} + X-Request-ID

Failure path: Throwable -> bootstrap exception handler -> structured log +
ErrorTracker -> safe envelope (no stack traces in production).
```

Frontend compatibility preserved: the SPA reads data.data, data.message,
data.error and header x-request-id - all unchanged.

## 3. Controller Audit

| Controller | Before | After | Status |
|---|---|---|---|
| HR/WorkplanController | CRITICAL (2282 ln, 37 SQL, 2 tx) | Thin delegator, 0 SQL | REFACTORED |
| HR/FinancialYearController | HEAVY (RBAC bypass, dev-mode auth hole, leaked exceptions, raw php://input) | Engine-backed auth, envelope, no leakage | REFACTORED |
| Leave/LeaveController | HEAVY (31 raw responses) | Envelope-compliant, validators wired | REFACTORED |
| Employee/EmployeeController | HEAVY (943 ln) | Response/validation fixed; logic extraction pending | Wave 4b |
| DashboardController | HEAVY (12 stat queries inline) | - | Wave 4b |
| Leave/LeaveRosterController | HEAVY (663 ln raw SQL) | - | Wave 4b |
| Reports/ReportsController | HEAVY (duplicates report services) | - | Wave 4b |
| HR/StrategicPlanController | HEAVY (535 ln raw SQL) | - | Wave 4b |
| System/MonitoringController | HEAVY (raw mysqli_stmt) | - | Wave 4b |
| AttendanceController | HEAVY (inline geo/audit/tx) | - | Wave 4b |
| HR/AppraisalCycle, PerformanceContract, KPI, SectionalObjective, Payroll | MODERATE (raw SQL + OrgScope inline) | - | Wave 4b |
| Department/Section/Subsection, AuditLog, Complaint, Meeting*, Notification*, Holiday, Appraisal, Setting, User | HEALTHY | - | HEALTHY |

## 4. Service Audit

- Healthy (single responsibility, cohesive): AuthService, UserService,
  EmployeeService, DepartmentService, AuditService, PermissionService,
  FinancialYearService, LeaveCalculationService, LeaveWorkflowService,
  LeaveDocumentService, MeetingMinutesService, AttendanceService,
  Notification channel suite, ErrorTracking suite, report service families.
- New (Phase 3): Workplan/WorkplanService (writes, transactions, state
  machine, audit), Workplan/WorkplanQueryService (scope-filtered reads).
- Oversized but cohesive (documented, split deferred): LeaveProfileService
  (1128 ln), LeaveApprovalService (900), AttendanceDashboardService (750),
  EmployeeService (592).
- Mislocated (queued): Helpers/AuthorizationService is the authorization
  engine; relocation to Services/Authorization with a compatibility alias is
  planned.
- Duplicate/unused services: none beyond the auth overlap resolved by
  removing the RBAC/dev-bypass path.

## 5. Dead Code Report

Removed (zero references verified first): app/Middleware/AuthMiddleware.php,
app/Policies/EmployeePolicy.php, app/Gates/Gate.php,
app/Responses/{JsonResponse,EmployeeResource,LeaveResource,
AttendanceResource,DepartmentResource}.php, backend/routes/routes/*,
backend/bootstrap/app.php, backend/config/config/* (16 files),
backend/database/database/*, root debris (facts*.txt x6, dump.js,
test_api.php, gather.ps1, MIGRATION_AUDIT_AND_PLAN.md),
AuthorizationService::requirePermissionManager() (no callers, echoed its own
shape), transient scripts/_*.php helpers, legacy phpunit.xml.

Kept after verification (NOT dead): Http/Resources/UserResource and
FinancialYearResource (active callers); repositories (genuine query reuse);
BaseValidator, AuthValidator, AttendanceValidator (base + siblings for
Wave-4b controllers); AuthorizationService::isPermissionManager() (used by
PermissionService).

Requires further verification (documented, untouched): vendor/laravel
skeleton and backend/app/Console (unused at runtime; vendor/Composer question,
see backend/_legacy/README.md); backend/app/public vs backend/public duplicate
(needs deploy-workflow verification).

---

## 3. Controller Audit

| Controller | Before | After | Status |
|---|---|---|---|
| HR/WorkplanController | CRITICAL (2 282 ln, 37 SQL, 2 tx) | Thin delegator, 0 SQL, 0 tx | REFACTORED ✅ |
| HR/FinancialYearController | HEAVY (RBAC bypass, dev-mode auth hole, leaked exceptions) | Engine-backed auth, envelope, no leakage | REFACTORED ✅ |
| Leave/LeaveController | HEAVY (31 raw responses) | Envelope-compliant, LeaveValidator wired | REFACTORED ✅ |
| Employee/EmployeeController | HEAVY (943 ln) | Response + validation fixed; logic extraction pending | MODERATE (Wave 4b) |
| DashboardController | HEAVY (12 stat queries) | — | Wave 4b target |
| Leave/LeaveRosterController | HEAVY (663 ln raw SQL) | — | Wave 4b target |
| Reports/ReportsController | HEAVY (duplicates report services) | — | Wave 4b target |
| HR/StrategicPlanController | HEAVY (535 ln raw SQL) | — | Wave 4b target |
| System/MonitoringController | HEAVY (raw mysqli_stmt) | — | Wave 4b target |
| AttendanceController | HEAVY (inline geo/audit/tx) | — | Wave 4b target |
| HR/AppraisalCycle, PerformanceContract, KPI, SectionalObjective | MODERATE (raw SQL + OrgScope inline) | — | Wave 4b target |
| HR/PayrollController | MODERATE (raw SQL) | — | Wave 4b target |
| Department/Section/Subsection, AuditLog, Complaint, Meeting*, Notification*, Holiday, Appraisal, Setting, User | HEALTHY | — | HEALTHY |

## 4. Service Audit

- **Healthy (single responsibility, cohesive):** AuthService, UserService,
  EmployeeService, DepartmentService, AuditService, PermissionService,
  FinancialYearService, LeaveCalculationService, LeaveWorkflowService,
  LeaveDocumentService, MeetingMinutesService, AttendanceService,
  Notification channel suite, ErrorTracking suite, report service families.
- **New (Phase 3):** `Workplan\WorkplanService` (state machine, transactions,
  audit) and `Workplan\WorkplanQueryService` (scope-filtered reads).
- **Oversized but cohesive (documented, not split — splitting would be
  aesthetic):** LeaveProfileService (1 128), LeaveApprovalService (900),
  AttendanceDashboardService (750), EmployeeService (592).
- **Mislocated (queued):** `Helpers\AuthorizationService` is the authorization
  engine; relocation to `Services\Authorization\` with a compatibility alias
  is the next safe step.
- **God services / duplicates:** none found beyond the auth overlap resolved
  by removing the RBAC bypass; `Auth` helper retained as the session identity
  facade used by services.

## 5. Dead Code Report

**Removed (zero-reference verified before deletion):**
- `app/Middleware/AuthMiddleware.php`, `app/Policies/EmployeePolicy.php`,
  `app/Gates/Gate.php`
- `app/Responses/{JsonResponse,EmployeeResource,LeaveResource,AttendanceResource,DepartmentResource}.php`
  (superseded by `Helpers\ApiResponse` + `Http\Resources\UserResource`)
- `backend/routes/routes/*`, `backend/bootstrap/app.php`,
  `backend/config/config/*` (16 files), `backend/database/database/*`
- Root debris: `facts*.txt` ×6, `dump.js`, `test_api.php`, `gather.ps1`,
  `MIGRATION_AUDIT_AND_PLAN.md`
- `AuthorizationService::requirePermissionManager()` (no callers; emitted its
  own response shape)
- Transient migration scripts; legacy `phpunit.xml`
- Stale validator business rules (DB uniqueness) duplicated from services

**Kept after verification (NOT dead):** `Http\Resources\UserResource`,
`FinancialYearResource`, all 13 repositories,
`Validators\BaseValidator/AuthValidator/AttendanceValidator`,
`AuthorizationService::isPermissionManager()` (used by PermissionService).

**Requires further verification:** `vendor/laravel` skeleton + `app/Console`
(unused at runtime; Composer/vendor question — see `backend/_legacy/README.md`);
`backend/app/public` vs `backend/public` duplicate (needs deploy-workflow
verification).

---

## 6. Files Changed (Phase 3 highlights)

| File | Reason | Improvement | Compatibility impact |
|---|---|---|---|
| `app/Services/Workplan/WorkplanService.php` (NEW) | C2 | Authoritative workplan state machine, cascade assignment, transactions, audit | None (new class) |
| `app/Services/Workplan/WorkplanQueryService.php` (NEW) | C2/H1 | Scope-filtered workplan reads shared by list/summary/traceability/export | None |
| `app/Controllers/HR/WorkplanController.php` | C2 | 2 282 → thin delegator; HTTP-only concerns | Response bodies unchanged (verified via delegation mapping) |
| `app/Controllers/BaseController.php` | C3/C4 | `validateRequest()` (standard 422 envelope), success/error delegated to ApiResponse | Additive |
| `app/Helpers/ApiResponse.php` | C4 | Single envelope source of truth; X-Request-ID; buffer hygiene; nosniff | Additive (envelope identical to legacy success shape) |
| `app/Controllers/Leave/LeaveController.php` | C4/C3 | 31 raw responses → envelope; LeaveValidator wired | `message` on failures; eligible_days moved into error.details (SPA reads message only) |
| `app/Controllers/HR/DepartmentController.php` | C3 | DepartmentValidator wired | 400 → 422 on validation failure (message preserved) |
| `app/Controllers/Employee/{EmployeeController,UserController}.php` | C3/C4 | Validators wired; raw responses removed | Same |
| `app/Controllers/HR/FinancialYearController.php` | H3/H4 | RBAC bypass + dev auth hole removed; envelope errors | Dev-only behavior change (security fix) |
| `app/Validators/{Department,User,Employee,Leave}Validator.php` | C3 | Shape-only validation; schema-accurate fields; service-owns-business-rules | None (previously unused) |
| `app/Middleware/{SecurityMiddleware,BaseMiddleware}.php` | C4 | Envelope exits; config-driven CORS | Error body shape standardized |
| `app/Helpers/{AuthorizationService,Database,JWT}.php` | H3/M2/M1 | Dead method removed; env-driven timezone/CORS | None |
| `backend/config/cors.php` (NEW) | M1 | Centralized origins | None |
| `tests/Unit/Services/ErrorTracking/ErrorTrackerFailSafeTest.php` | M7 | Deterministic (no live DB) | Tests only |
| `docs/{ARCHITECTURE_AUDIT,PHASE3_REPORT,BACKEND_CONVENTIONS}.md` (NEW/updated) | M5 | Architecture record + developer standards | Docs only |
| Removed files (see §5) | H4/dead code | Verified dead | None (zero references) |

## 7. Tests

- **Added:** `tests/Unit/Validators/RequestValidatorsTest.php` — 13 tests for
  the four rewritten shape-only validators (Department, User, Employee, Leave),
  including the corrected schema-accurate field assertions.
- **Added:** `tests/Unit/Services/Workplan/WorkplanServiceTest.php` — pure
  (DB-free) coverage of the workplan cascade-level derivation rules
  (`deriveLevel`: subsection > section > department > organisation) plus the
  `actorName` / `decodeJsonField` helpers. Transaction and query paths of
  `WorkplanService` require a live database and remain covered by the
  controller delegation mapping rather than unit tests (documented risk).
- **Updated:** `ErrorTrackerFailSafeTest` → deterministic PSR-3 stub capture;
  no longer requires live MySQL.
- **Run:** `php vendor/bin/phpunit --no-coverage` →
  **121 tests, 575 assertions, 0 failures, 1 skipped** (pre-existing skip:
  environment-dependent notification test).
- **Static checks:** full `php -l` sweep (backend/app, cron, database) clean;
  `composer dump-autoload` clean; frontend `data.data` / `data.message` /
  `data.error` consumption contract verified against the standardized envelope.
- **Remaining risks (documented, queued):**
  1. Controllers with raw SQL (Dashboard, Roster, Reports, StrategicPlan,
     Monitoring, Attendance, performance cluster) — Wave 4b.
  2. `AuthorizationService` relocation to `Services\Authorization\` — Wave 6.
  3. `api.php` route-table extraction — C1, deferred by design.
  4. Duplicate leave-type/financial-year lookups in LeaveController helpers —
     Wave 5 consolidation.
  5. Legacy script-style tests in `backend/tests/*.php` not yet migrated to
     PHPUnit (doc'd in BACKEND_CONVENTIONS.md).

## Phase 3 Success Criteria

- [x] Request lifecycle clearly defined (§2 + BACKEND_CONVENTIONS.md)
- [x] Controllers have clear responsibilities (pattern proven on the worst offender)
- [x] Major duplicated business logic removed where touched (validation, auth bypass, responses)
- [x] Major God Controllers refactored (WorkplanController; others queued with owner's approval)
- [x] Service responsibilities clear (§4)
- [x] Database access patterns consistent for refactored modules (repository/service)
- [x] Transactions correctly handled (moved to service layer for Workplan)
- [x] Error handling consistent (central handler + ApiResponse + no leaked internals)
- [x] API responses standardized (single envelope, X-Request-ID)
- [x] Request validation consistent (shape-only validators + standard 422)
- [x] Business rules separated from HTTP concerns (validators vs services)
- [x] Dead code identified and safely removed or documented (§5)
- [x] Configuration centralized and environment-aware (CORS, DB timezone, env keys)
- [x] Module boundaries clearer (Workplan service split; conventions doc)
- [x] Circular dependencies eliminated where found (auth path simplified)
- [x] Code is easier to test (service extraction + new unit tests)
- [x] Backend architecture documentation updated (3 docs)
- [x] Existing functionality remains operational (113/564 green, frontend contract verified)

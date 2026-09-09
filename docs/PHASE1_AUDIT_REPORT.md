# MUWASCO HR Management System — Phase 1 Audit & Laravel Migration Report

**Date:** 2026-09-07
**Scope:** Full technical and functional audit of the existing system prior to in-place Laravel transformation.
**Validation baseline:** Legacy backend test suite = **270 tests, 1187 assertions, 1 skipped — GREEN** (`php backend/run_tests.php`).

---

## 1. Executive Summary

MUWASCO HR is a **working, production-grade HR platform** built on a custom PHP 8.0 runtime — not on Laravel.

- **Root** (`c:\xampp\htdocs\hrdemo`) is a hand-rolled front controller: `.htaccess` + `router.php` dispatch `/api/*` to `api.php` (the JSON API, **277 routes**) and everything else to the React SPA (`frontend/`).
- **`backend/`** holds the application code (`App\` namespace via root `composer.json`): 30+ controllers, 40+ services, ~35 repositories/interfaces, 15 helpers, 5 middleware, 6 validators, custom `BaseModel` models, and 42 SQL migrations applied by a custom runner.
- The **live MySQL `muwasco` schema** (80 user tables) carries real production data: 193 employees, 717 leave applications, 12,912 attendance records, 2,628 notifications, 61,010 security-log rows.

The system is healthy enough to be migrated **in place** — keep every working file, add the Laravel application shell, then convert module by module. Migration is non-trivial mainly because of: (1) a **fragmented employee-identity model**, (2) a few **schema defects** (`notifications` has no primary key; `employee_leave_balances` stores the wrong employee identifier and 4,529 orphan fiscal-year references), (3) **committed secrets** in root `.env`, and (4) a **missing SPA entry file** (`index.php`) referenced by `.htaccess`/`router.php`.

---

## 2. Architecture Map (current)

```
Browser (React SPA, frontend/ — Vite :5173 dev / dist prod)
        │  fetch/axios → VITE_API_URL (http://localhost/hrdemo/api)
        ▼
Apache (.htaccess)  ── /api/* ─────────────────────────► api.php  (JSON API — 277 routes)
        │  all other requests ────────────────────────► index.php (MISSING at root — §9-F1)
        ▼
api.php → backend/app/Controllers/*
        │  Middleware: Auth → Authorization(perms) → Security (CORS/CSRF/brute-force/throttle)
        ▼
Services (business logic) → Repositories (contracts + mysqli) → App\Helpers\Database (mysqli singleton)
        ▼
MySQL `muwasco` (utf8mb4)   +   backend/storage/ (uploads, logs)   +   backend/cron/ (reminders, auto clock-out, error retention)
```

**Key characteristics**

| Area | Current state |
|---|---|
| Language/runtime | PHP 8.0.30 default (XAMPP `c:\xampp\php`); Laravel 13 requires 8.3+ — already available at `tools\php83\php.exe` |
| Routing | Custom `$router->add(method, uri, Controller::class, method, perm, throttle)` in `api.php` — 277 registrations |
| Auth | Custom JWT (access+refresh) **plus** DB sessions (`db_sessions`, 1,602 rows); CAS-generation cookies; brute-force throttle |
| DB access | Raw `mysqli` singleton (`App\Helpers\Database`, instrumented for perf); **no ORM / query builder** |
| Models | Custom `BaseModel` flat-table mapper (no Eloquent) |
| Validation | Hand-written validators (Auth, Employee, Department, Leave, User) |
| Authorization | `RBAC` + `AuthorizationService` + `OrgScope` + `config/permissions.php` catalog + `role_permissions` (389) / `user_page_permissions` (5) |
| Testing | PHPUnit — 270 legacy tests green; Laravel feature tests to be added per module |
| Frontend | React 18 + Vite 5 + Tailwind 3; ~26 API service files; pages for all modules |
| Background jobs | `backend/cron/`: attendance reminders (SMS+push), auto clock-out, error retention |
| Observability | Custom error tracker (fingerprints/severity/redaction), perf events, 61k security-log rows |
| AI | Provider abstraction (local / NVIDIA NIM / OpenAI-compatible), permission-checked server tools, usage logging |
| FY / Holiday / Payroll | `Controllers/HR/{FinancialYear,Holiday,Payroll}Controller.php`, `Services/{FinancialYear,Holiday}Service.php` | fiscal-year + leave allocation, holidays (recurring), payroll endpoints (tables unused) | `financial_years` (1), `holidays` (11), `salary_bands` (13) | Employees, Leave balances | Legacy/working |
| Delegations | `Controllers/Leave/DelegationController.php`, `Services/{Delegation,Delegate}Service.php` | scoped delegation (dept/section/subsection), 6 statuses, permission snapshot | `delegations` (0) | Users, RBAC | Legacy/working — fully unit-tested |
| Notifications / Push / SMS | `Controllers/Notifications/*` (4 files), `Services/NotificationService.php`, `Services/Notification/*` | in-app notifications, preferences, push (VAPID), SMS (httpsms) | `notifications` (2628), `notification_*` (0), `push_subscriptions` (2) | Users, Attendance, Leave, Meetings | Legacy/working — **no PK (defect)** |
| Reports | `Controllers/Reports/{Reports,AttendanceReport}Controller.php`, `Controllers/Leave/LeaveReportController.php`, `Services/LeaveReport*.php` (6), `Services/AttendanceReport/*` | analytics + CSV export, paginated stats | read-mostly over leave/attendance/audit | Leave, Attendance, Audit | Legacy/working |
| Permissions / RBAC | `Helpers/{RBAC,AuthorizationService,OrgScope}.php`, `Services/PermissionService.php`, `Controllers/Settings/PermissionController.php`, `config/permissions.php` | role→module.action matrix, per-user overrides, org scoping, route allow-list, rate-limit governance | `role_permissions` (389), `user_page_permissions` (5), backup table (0) | Users, Routes | Legacy/working — heavily tested |
| Audit | `Services/AuditService.php`, `Controllers/Settings/AuditLogController.php` | structured trail (IP/device/request-id/target), export | `audit_logs` (738) | global | Legacy/working |
| Settings / Monitoring | `Controllers/Settings/SettingController.php`, `Controllers/System/MonitoringController.php` | app settings, system monitoring | `settings`?, `security_logs` | global | Legacy/working |
| Complaints & Consent | `Controllers/HR/{Complaint,Consent}Controller.php`, `Models/{Complaint,Consent}.php` | JDAC questionnaires, consent versioning | `user_consents` (192), `jdac_questionnaires` (15), `jdac_questions` (28), `jdac_responses` (22) | Employees | Legacy/working (no dedicated complaints table — verify) |
| AI assistant | `Controllers/AI/AiAssistantController.php`, `Services/AiAssistantService.php`, `Services/AI/*` | chat with permission-checked HR tools, provider fallback, usage logs | `ai_conversations` (20), `ai_messages` (48), `ai_tool_calls` (8), `ai_usage_logs` (34), `ai_feedback` (0), `ai_prompt_versions` (1) | Employees, RBAC | Legacy/working |
| Observability | `Services/ErrorTracking/*`, `Helpers/{PerfTiming,InstrumentedMysqli,InstrumentedMysqliStmt}.php`, `config/observability.php`, `cron/error_retention.php` | fingerprint/severity/redaction, correlation IDs, perf events | `application_errors` (95), `error_groups` (57), `error_group_users` (44), `performance_events` (579), `security_logs` (61010) | global | Legacy/working |
| Mail / email | `Templates/Emails/Leave/*` (7 templates), SMTP config | leave workflow emails (SMTP Gmail placeholders in `.env`) | — | Leave | Partial (tests use log driver) |
| File storage | `backend/storage/{uploads,logs}`, upload helpers | employee documents / profile images, error logs | `employee_documents` (902) | Employees | Legacy/working |

---

## 4. API Surface — 277 routes in `api.php` (by first path segment)

| Module | Routes | Module | Routes | Module | Routes |
|---|---|---|---|---|---|
| leave | 33 | reports | 29 | meetings | 21 |
| workplans | 15 | attendance | 10 | employees | 9 |
| appraisals | 9 | admin | 9 | permissions | 8 |
| payroll | 8 | profile | 8 | users | 7 |
| system | 7 | delegations | 7 | strategic-plans | 7 |
| complaints | 6 | holidays | 6 | dashboard | 6 |
| sectional-objectives | 5 | performance-contracts | 5 | audit | 5 |
| subsections | 5 | sections | 5 | departments | 5 |
| auth | 5 | consent | 5 | push | 4 |
| ai | 4 | appraisal-cycles | 4 | contract(s) | 2 |
| notifications | 3 | kpis | 3 | goals | 2 |
| notification-preferences | 2 | targets | 2 | settings | 2 |
| my-meetings | 1 | audit-logs | 1 | consents | 2 |

---

## 5. Database Audit — `muwasco` (MySQL / InnoDB / utf8mb4)

**Scale:** 87 tables (80 application + framework placeholders), 115 recorded migrations (custom SQL runner, `database/migrations` + `Migration.php`).

### 5.1 Table groups & volume (production data)

| Group | Tables (notable row counts) |
|---|---|
| Core HR | employees (193), departments (10), sections (24), subsections (15), offices (8), salary_bands (13), employee_documents (902), next_of_kin (55) |
| Identity / auth | users (193), db_sessions (1602), sessions (0), refresh_tokens (0), password_reset_tokens (0), employee_otps (0), personal_access_tokens (0), device_attempt_log (28), employee_devices (0) |
| Leave | leave_applications (717), leave_history (715), leave_transactions (715), leave_types (9), employee_leave_balances (7692), employee_leave_brought_forward (41), leave_roster (143), leave_application_documents (0), leave_transactions_backup (0) |
| Attendance | attendance (12912 — `attendance_date` is a STORED GENERATED column), absent_deductions (0), absent_exemptions (0) |
| Meetings | meetings (0), meeting_invitations (29), meeting_minutes* (0) |
| Strategy/performance | strategic_plan (1), strategies (0), goals (5), strategic_targets (10), performance_contracts (129), performance_indicators (94), workplan_objectives (194), workplan_logs (9), kpis (0), appraisal_cycles (5), appraisal_scores (176), employee_appraisals (36), cycle_indicators (4), jdac_questionnaires (15), jdac_questions (28), jdac_responses (22), dependencies (67) |
| Notifications | notifications (2628), notification_logs (0), notification_preferences (0), notification_templates (0), user_notification_preferences (0), push_subscriptions (2) |
| Access control | role_permissions (389), user_page_permissions (5), user_page_permissions_backup_015 (0), delegations (0) |
| Observability | application_errors (95), error_groups (57), error_group_users (44), performance_events (579), security_logs (61010), audit_logs (738) |
| AI | ai_conversations (20), ai_messages (48), ai_tool_calls (8), ai_usage_logs (34), ai_feedback (0), ai_prompt_versions (1) |
| Compliance | user_consents (192) |
| Framework placeholders (empty) | cache, cache_locks, jobs, job_batches, failed_jobs, activities, migrations (115 rows — real) |

### 5.2 Schema-quality findings

**F1 — `notifications` has NO primary key and NO auto-increment.** `SHOW CREATE` shows `id int(11) NOT NULL` with no `PRIMARY KEY`, and `MAX(id) = 0` across 2,628 rows. Inserts rely on the app; Eloquent will refuse to map this table until fixed. *(Impact: duplicate rows possible, ORM incompatible; Fix: add surrogate PK + auto-increment + backfill, unique on (user_id, related_entity, related_id)?; Risk: LOW additive)*

**F2 — Fragmented employee identity (3 representations).** Verified by join counts:

| Column | Type | Refers to | Verified matches |
|---|---|---|---|
| `employees.id` | int auto-inc PK | — | — |
| `employees.employee_id` | varchar(50) unique | business code (e.g. `074`) | — |
| `leave_applications.employee_id` | int | `employees.id` | 718/717 rows OK |
| `leave_transactions.employee_id` | int | `employees.id` | (same family) |
| `attendance.employee_id` | int | `employees.id` | 0 orphans |
| `employee_leave_balances.employee_id` | varchar(50) | **stores `employees.id` (int) as text** | 7,570 / 7,692 |
| `users.employee_id` | varchar(50) | `employees.employee_id` (business code) | 0 orphans |
| `notifications.user_id`, `delegations.*_user_id` | int | `users.id` | — |

**Impact:** any Laravel relation build must pick ONE canonical key. **Recommendation:** normalise on `employees.id` (stable surrogate) as the FK everywhere; keep `employees.employee_id` as a *unique business code* (it must stay unique because legacy `users`/balances reference it). Reconciliation wave fixes `employee_leave_balances`.

**F3 — Zero foreign-key constraints exist** (InnoDB tables all rely on application-level integrity). Consequences: 24 employees → missing `department_id`; 2 sections → missing `department_id`. **Fix:** repair waves then add FKs with `SET NULL`/`RESTRICT` semantics chosen per table. **Risk:** MEDIUM (requires care not to block legacy inserts during transition).

**F4 — `employee_leave_balances` fiscal-year orphans:** 4,529 / 7,692 rows reference `financial_year_id` that no longer exists (1 FY row remains). Historical balances are orphaned. **Impact:** leave-balance reports silently miss 59% of ledger; **Fix:** archive mapping of old FYs (or restore FY rows into an `archived` state) before adding FK.

**F5 — Data-quality sentinels in `employees`:** 146/193 (75%) have `designation = '0'` (or empty); 179/193 have `next_of_kin = '[]'` (empty JSON) while a **normalised `next_of_kin` table also exists (55 rows)** — dual storage of the same concept. **Recommendation:** adopt the normalised `next_of_kin`, deprecate JSON `employees.next_of_kin`, and clean `designation='0' → NULL`.

**F6 — `users.role` is `enum(...)` that omits `employee` and `admin`**, although `config/permissions.php` lists both (`admin` is normalised to `super_admin` in `RBAC.php`). **Impact:** cannot assign plain `employee` role; **Recommendation:** move role to a lookup table + pivot (documented decision for Laravel migration).

**F7 — `employees.designation varchar(50)` / `position varchar(100)`** are too small for realistic titles (faker overflow reproduced in earlier factory tests). Widen or leave as-is and clamp input.

### 5.3 Data-integrity checks (run against live data)

| Check | Result | Verdict |
|---|---|---|
| Duplicate `employees.employee_id` | 0 | ✅ unique (`uk_employees_employee_id`) |
| Duplicate `national_id` | 0 | ✅ |
| Duplicate `email` | 0 | ✅ |
| Duplicate department names | 0 | ✅ |
| Employees with orphan `department_id` | **24** | ❌ repair wave |
| Sections with orphan `department_id` | **2** | ❌ repair wave |
| Employees without any org unit | 0 | ✅ |
| Employees without a user account | **2** | ⚠️ review |
| Users pointing at missing employee | 0 | ✅ |
| Inactive user accounts | 0 | ✅ |
| `leave_applications.employee_id` → `employees.id` | 718 valid / 717 unique | ✅ |
| `leave_applications` orphan FY | 0 | ✅ |
| `attendance` orphan employee | 0 | ✅ |
| `employee_leave_balances` orphan employee ref | **7,560** (wrong id style) | ❌ reconcile |
| `employee_leave_balances` orphan FY | **4,529** | ❌ reconcile/archive |
| `notifications` PK integrity | **MAX(id)=0 / 2,628 rows** | ❌ add PK |
| `security_logs` volume | 61,010 rows (unbounded growth) | ⚠️ retention job |
| `db_sessions` volume | 1,602 rows | ⚠️ purge policy |

### 5.4 Database strategy recommendation

**Reuse the live schema, improve incrementally (option B of the original spec).**

1. **Never run destructive migrations.** Only additive "reconciliation waves" (new columns/indexes/tables; backfill scripts; repair FKs **after** data repair).
2. Port the 42 legacy SQL migrations to **Laravel migrations** (via `kitloong/laravel-migrations-generator`) mapped through the existing `migrations` table so history is preserved.
3. Wave order:
   - **Wave 0 (frozen):** replicate schema as-is into Eloquent models (no DDL).
   - **Wave 1:** fix `notifications` PK; reconcile `employee_leave_balances` identity + FY archive.
   - **Wave 2:** repair 24+2 orphan org references; clean `designation='0' → NULL`.
   - **Wave 3:** introduce FKs (start with pure-int tables: attendance, leave_applications, delegations, notifications).
   - **Wave 4:** adopt normalised `next_of_kin`, deprecate JSON columns; decide `users.role` → roles table.
   ---

## 6. Business-Logic Audit (documented rules per module)

| Module | Rules confirmed in code (current behavior) |
|---|---|
| Auth | Email lowercased + trimmed; uniform `Invalid credentials` (no user enumeration); password checked *before* account-status (identical response); inactive → same 401 + security-log reason; consent version checked at login; JWT access(1h)+refresh(7d) with `refresh_tokens`; DB session (`db_sessions`) with device fingerprint + `uq_user_device`; brute-force throttle 5/15min per IP+account; no self-service password reset (admin-driven only, 10/15min). |
| Employees | CRUD + duplicate guard (employee_id unique); JSON `next_of_kin`/`dependants` arrays with defensive decode for legacy string payloads; documents upload (allowed types from `.env`, 10MB max) to `storage/uploads`; profile-image endpoints; `employee_status` enum(active, inactive, resigned, fired, retired). |
| Org structure | Depts → sections → subsections → offices (lat/lng + 50m geo-fence); deletion blocked while children/employees exist; sections/subsections gated under `departments:*` permissions. |
| Leave | Hierarchical workflow: **officer→sub_section_head→section_head→dept_head→managing_director→bod_chair**; self-application routes to your superior; HR manager → managing director; MD → BOD chair; conflict/overlap detection; weekend/holiday counting per leave-type flags (`counts_weekends`, `count_holidays`); balances (allocated/used/brought-forward/accumulated/remaining) per FY per type; ledger `leave_transactions` (deduction/restoration/adjustment); `leave_history`; 9 leave types; roster + coverage analytics; delegation can approve in-scope applications only (unit-tested—evidence of correct scope enforcement). |
| Delegations | Scope `department/section/subsection`; statuses pending→approved→active→expired (+cancelled/rejected); only snapshotted permissions are granted; non-delegatable modules can never be granted; delegate cannot decide own application; explicit deny overrides delegation (all unit-tested green). |
| Attendance | Clock-in/out with mandatory location (geofence) unless `ATTENDANCE_ALLOW_UNVERIFIED_LOCATION=true`; late flag; auto clock-out; one active record/employee/date (`uk_attendance_employee_date`); reminders policy (push first, SMS fallback after 15 min, 2 SMS/day max); device fingerprint + attempts log. |
| Meetings | statuses scheduled/ongoing/completed/cancelled; audience by role/scope; confirm/decline; attendance token; minutes with agenda/decisions/AOB/action items tables. |
| Reports | Pageable analytics + CSV export for leave/attendance; coverage, distribution, roster matrices; export routes throttled. |
| Notifications | In-app rows + WebPush (VAPID) subscriptions; SMS via httpsms (fallback policy); preferences per user; admin tests for templates. |
| Permissions | Central `config/permissions.php` catalog (16 modules × actions with `default_roles`); role matrix in `role_permissions` (389 rows); per-user overrides in `user_page_permissions` (5); org-scoping narrows *who*; unknown modules/actions default to **deny**; `super_admin` bypass (hardcoded). |
| Audit | Structured logs w/ actor, action, target_type/id, channel, IP, device, office, request-id; exportable. |
| Observability | Error fingerprinting (dynamic parts + UUIDs normalised), severity classifier (business-critical modules bump), payload redaction ([REDACTED] for 40+ sensitive fields), correlation X-Request-ID (trusted inbound with format validation), perf event thresholds (2s/4s/8s), retention cron. |
| AI | Provider drivers (local/Ollama-esque default, NVIDIA NIM, OpenAI-compatible) selected by `AI_PROVIDER`; server-side permission-checked tools w/ `ai_tool_calls` audit; cost/latency guards (max chars/history/retries); usage logging 90-day retention; **no cloud provider contacted unless configured**. |
---

## 7. Security Findings

| # | Finding | Severity | Detail | Required action |
|---|---|---|---|---|
| S1 | **Secrets committed in root `.env`** | CRITICAL | `JWT_SECRET`, a real-looking **NVIDIA API key** (`nvapi-…` present on line 134), **VAPID private key**, HTTPSMS placeholders, Gmail SMTP placeholders — all in the tracked repo, with a duplicated AI block (lines 100–141). `.env` is git-ignored but the SAMPLE values represent live secrets. | Rotate all keys (JWT, NVIDIA, VAPID, mail, httpsms); move to Laravel env; keep `.env.example` placeholder-only; run `scripts/ci/secret_scan.php` in CI. |
| S2 | `notifications` table without PK/auto-increment | HIGH | Duplicate/all-zero ids; ORM-incompatible; unreliable ordering/deletion. | Wave-1 migration (see §5.4). |
| S3 | No database foreign keys (24+2 orphan org refs live) | MEDIUM | Referential integrity only app-enforced; orphans observed. | Repair then constrain (Wave 3). |
| S4 | Role enum cannot store `employee`/`admin` | MEDIUM | Attempting to grant the `employee` role fails at the DB boundary; `admin` collision with normalisation. | Widen enum or introduce roles lookup before Eloquent mapping. |
| S5 | `security_logs` grows unbounded (61k) | MEDIUM | Storage + query degradation; retention defined for errors but not security logs. | Retention/partition job. |
| S6 | Dependency-critical: PHP 8.0 runtime vs Laravel 13 (needs 8.3+) | MEDIUM | Migrated app will not run on default XAMPP PHP. | Use `tools\php83\php.exe` (PHP 8.3.33 already vendored); update `start-servers.bat`/docs. |
| S7 | `index.php` SPA entry missing at root | MEDIUM | `.htaccess`/`router.php` both rewrite non-API → `index.php` which does not exist; `backend/public/index.php` exists. | Restore/alias SPA entry (frontend dist or `/index.html`) or application shim. |
| S8 | MFA + CAPTCHA disabled | LOW | `FEATURE_MFA=false`, `FEATURE_CAPTCHA=false`. | Planned hardening post-migration. |

**Positive controls verified:** CSRF tokens, brute-force throttling (login/change-password), route-level rate limits, stream headers, cookie flags (HttpOnly, SameSite), `.htaccess` denies `.env`/logs/SQL/md, sensitive-field redaction in error logging, per-request correlation IDs, CSV/XSS-neutral report export, uniform auth errors, and an enforced route→permission→throttle contract (dual-direction tests).

---

## 8. Incorrect / At-Risk Behaviors (Current → Expected)

| # | Module | Current behavior | Expected | Business impact | Proposed correction | Migration risk |
|---|---|---|---|---|---|---|
| I1 | Balance ledger | `employee_leave_balances.employee_id` stores `employees.id`, breaking joins to business `employee_id` (7,570/7,692) | Single canonical key on `employees.id`; business code kept as unique label | Leave balance UIs/reports silently wrong for the majority of rows | Reconciliation UPDATE + index + FK (Wave 1) | LOW (data-only); keep backwards view until legacy code switches |
| I2 | Balance history | 4,529 balance rows reference deleted financial years | Full audit trail incl. archived FYs | 59% of historical balance rows invisible | Restore archived FY rows (status=archived) before FK | MEDIUM (choose archive semantics carefully) |
| I3 | Notifications | No PK / auto-inc; `id` always 0 | Surrogate PK + proper ordering | Risk of duplicate/mis-targeted notifications | Wave-1 DDL + `notification_logs` unification | LOW |
| I4 | Org hierarchy | 24 employees + 2 sections reference missing dept | Referential integrity | Scope/approval routing can misfire | Data repair + `SET NULL`/`RESTRICT` FK policy | MEDIUM (must not lock writes) |
| I5 | Employee profile | `designation='0'` sentinel used widely (75%) | Clean `''`/`NULL` semantics | Analytics filter bugs ("0" looks valid) | Migration clean + form validation | LOW |
| I6 | Next-of-kin | Dual storage: `employees.next_of_kin` JSON **and** `next_of_kin` table | One source of truth (normalised) | Profile edits drift between stores | Adopt table; deprecate JSON column (Window: keep writing both, read from table) | MEDIUM |
| I7 | Roles | `users.role` enum too narrow; `admin` vs `super_admin` duality | Roles table + pivot (or widened enum) | Cannot assign `employee`; RBAC catalog drift | Add `employee` value; align catalog | LOW |
| I8 | Sessions | JWT + `db_sessions` + empty `sessions`/`refresh_tokens` in parallel | One session strategy | Confusing invalidation semantics | Choose Sanctum strategy mapped to `db_sessions`; purge others | MEDIUM |
| I9 | Deployment entry | `.htaccess`/router point at missing `index.php` | SPA fallback functional after deploy | Non-API deep-links break outside Vite | Restore entry (frontend dist index or backend/public shim) | LOW |
| I10 | Runtime | Default `php` is 8.0.30 | Laravel 13 needs 8.3+ | Migrated code un-runnable on default | Standardise on `tools\php83\php.exe`; update start scripts/docs | LOW |

---

## 9. Frontend Audit (`frontend/`)

**Stack:** React 18.2, Vite 5, Tailwind 3, mixed `.jsx`/`.tsx` (some `.d.ts` sidecars), axios, react-router-dom 6, zod, `@hookform/resolvers`, `@tanstack/react-table`, recharts, react-hot-toast, dayjs. Tests: Vitest + Testing Library + jest-dom.

**API layer:** `src/config/api.ts` single source of truth (`VITE_API_URL || '/api'`); `src/api/client.ts` (axios) + fetch wrapper (`utils/api.js`) + client-error collector (`utils/errorReporting.ts`); **26 service modules** under `src/api/services/` (auth, employee, department, leave, leaveReport, attendance, attendanceReport, meetingMinutes, notification, permission, audit, dashboard, financialYear, workplan, strategicPlan, appraisal, appraisalCycle, consent, report, user, errorTracking, aiAssistant…).

**Pages:** attendance, auth (Login, DataProtectionConsent), dashboard, delegations, employee (Employees, EmployeeForm, EmployeeProfile.jsx, Profile.tsx — dual implementations to reconcile), hr-admin (Appraisal, AppraisalCycles…), leave, meetings, reports, settings (users/permissions/profile/security/notifications), strategic-plan, strategy.

**Components:** AI chat widget stack, leave roster suite (CoverageBar, PlanningMatrix, ScheduleSlideOver…), settings tabs, UI primitives (Button/Card/Input/Modal/Select/Table/Tabs/Badge), ProtectedRoute + Sidebar/Header/Layout, DelegateBanner, ErrorBoundary, AccessDenied, ConnectionStatus.

**Findings**
- Pagination contract already enforced server-side (feature tests assert `data`, `meta`, `links`) — keep the same envelope in Laravel (`ApiResponse` equivalent).
- Two employee-profile implementations (`.jsx` + `.tsx`) — consolidate during employee-module conversion.
- `frontend/.env` sets `VITE_API_URL=http://localhost/hrdemo/api`; dev Vite proxy + CORS `localhost:5173` confirmed.
- Built `frontend/dist` exists and is the natural source for the root SPA shim (S7/I9).

---

## 10. Operations & Background

| Item | Detail |
|---|---|
| Cron jobs (`backend/cron/`) | `attendance_reminders.php` (SMS+push policy), `auto_clockout.php`, `error_retention.php` — not yet Laravel queued/scheduled jobs. |
| Mail | SMTP (Gmail placeholders) from `.env`; PHP-rendered templates (`Templates/Emails/Leave/*`, 7); tests use log mailer. |
| Storage | `backend/storage/uploads/documents/*` (902 documents) + profile images; validated by type + 10MB cap from `.env`. |
| Observability | retention job for errors/performance; **no** retention for `security_logs` (61k) or `db_sessions` (1.6k). |
| CI | GitHub workflows + `scripts/ci/secret_scan.php` — wire it to block S1. |
| Docs | `docs/` extensive (API, ARCHITECTURE, AUTH, AUTHORIZATION, SECURITY_AUDIT, DB, DEPLOYMENT, OBSERVABILITY, PHASE_L0..L2, PHASE3..6) — feeds the migration. |

---

## 11. Laravel Transformation Roadmap (in-place)

**Principle:** the legacy app keeps serving traffic until a module's Laravel replacement passes its own feature tests. `backend/_legacy/` holds only pre-existing reference files; code is re-homed only when fully converted.

| Phase | Scope | Exit criteria |
|---|---|---|
| **L1 — Laravel shell** | Add Laravel app files into `backend/` from parked `_skeleton/` (composer, artisan, bootstrap, config, routes, vendor, public) **without** touching legacy `app/{Controllers,Services,Repositories,Helpers,Core,Validators,Responses,Templates}` or SQL migrations | `php83 artisan --version` + `/api/v1/ping` green; legacy 270 tests still run via `run_tests.php` |
| **L2 — DB layer** | Port 42 SQL migrations to Laravel migrations (mapped via `migrations` table); Eloquent models for core tables (Wave 0 read-only fidelity); Wave 1 fixes (notifications PK, balances identity) as additive migrations | `migrate:status` clean; `Employee::count()==193` |
| **L3 — Auth** | Sanctum login/logout/user/change-password vs `users`+`db_sessions`, uniform envelope, throttle + audit | Auth feature tests green; legacy auth unit tests still green |
| **L4 — HR core** | Employees/Sections/Subsections/Offices/Departments → thin Laravel controllers delegating to existing services; Form Requests; policies | HR feature tests green (incl. pageable envelope) |
| **L5 — Leave** | 33 routes (largest) — workflow/balance services reused; new controllers + domain events + mailable templates | Leave feature tests green; roster/export verified |
| **L6 — Attendance/Meetings/Reports/Notifications** | Convert rest; queued jobs for reminders/auto clock-out/retention; notifications PK lands first | Feature tests + cron integration green |
| **L7 — AI/Observability** | AI providers/tools as Laravel services; keep error tracker as service; map tables via Eloquent | Existing deep unit suites retained; new feature tests |
| **L8 — Cutover** | `api.php` becomes a shim rewriting to the Laravel router; legacy suites archived (not deleted); SPA repointed | All 277 route paths 1:1 via `routes/api.php`; dashboard concurrency load test passes |

## 12. Decisions Required (next gate)

1. **Canonical employee key** — adopt `employees.id` for all FKs (keep `employee_id` as unique business code). Confirm no external system consumes the business code in balance rows.
2. **Session strategy** — Sanctum session driver mapped onto `db_sessions` (preserve device-fingerprint uniqueness) vs. Laravel cookie sessions + `sessions` table. Recommend the former to avoid re-consenting devices.
3. **Roles** — widen `users.role` now (add `employee`) vs. full roles-table redesign during L3.
4. **FY archive semantics** — restore deleted financial years as `status='archived'` (recommended) so 4,529 orphan balance rows become visible again.
5. **Skeleton reuse** — parked `_skeleton/` (composer/artisan/vendor/config/routes/factories/seeders) is the L1 source. Confirm.
6. **`.env` rotation** — rotate S1 secrets (JWT, NVIDIA, VAPID, mail, httpsms) before any shared-remote merge.

---

*Generated from live inspection: repository trees, `api.php` (277 routes), `backend/config/*`, MySQL `information_schema` + targeted integrity queries, service/helper source, frontend service layer, cron, docs — with the 270-test green baseline as the migration contract.*

---

## 3. Module Inventory

| Module | Existing Files (representative) | Current Functionality | DB Tables (rows) | Dependencies | Migration status |
|---|---|---|---|---|---|
| Authentication | `Controllers/Auth/AuthController.php`, `Services/AuthService.php`, `Helpers/{JWT,Session,Hash}.php`, `Validators/AuthValidator.php` | login, logout, refresh, `/auth/user`, change-password; 5/15min throttle; uniform "Invalid credentials" (no enumeration); consent check in login | `users` (193), `db_sessions` (1602), `refresh_tokens` (0), `employee_otps` (0), `password_reset_tokens` (0), `security_logs` | Users, Employees, Consent, Observability | Legacy/working — **convert first** |
| Employees | `Controllers/Employee/{Employee,User}Controller.php`, `Services/EmployeeService.php`, `Repositories/EmployeeRepository.php`, `Validators/EmployeeValidator.php`, `Models/Employee.php` | CRUD, search, reference endpoint, own profile, documents upload/view, profile images | `employees` (193), `employee_documents` (902), `next_of_kin` (55), `users` | Departments, Sections, Subsections, Offices | Legacy/working |
| Org structure | `Controllers/HR/{Department,Section,Subsection,Office}Controller.php` (+ older `Controllers/Core/*` duplicates), `Services/{Department,FinancialYear,Holiday}Service.php` | dept / section / subsection / office CRUD; basis of approval chains | `departments` (10), `sections` (24), `subsections` (15), `offices` (8) | Employees, Leave workflow | Legacy/working — **dup controllers** |
| Users & admin | `Controllers/Employee/UserController.php`, `Services/UserService.php`, `Validators/UserValidator.php` | user CRUD, toggle-status, admin password reset (10/15min) | `users`, `user_page_permissions` (5), `role_permissions` (389) | Employees, RBAC | Legacy/working |
| Leave | `Controllers/Leave/{Leave,Delegation,LeaveReport,LeaveRoster}Controller.php`, `Services/Leave*.php` (12), `Templates/Emails/Leave/*` (7), `Validators/LeaveValidator.php` | apply / approve / reject / invalidate / cancel; hierarchical workflow; balances + ledger; roster; exports; delegations | `leave_applications` (717), `leave_types` (9), `employee_leave_balances` (7692), `leave_history` (715), `leave_transactions` (715), `leave_roster` (143), `leave_application_documents` (0), `employee_leave_brought_forward` (41), `delegations` (0) | Employees, Org, Users, Mail, Audit | Legacy/working — **largest (33 routes)** |
| Attendance | `Controllers/AttendanceController.php`, `Services/Attendance{,Dashboard,ReminderEligibility}Service.php`, `Services/AttendanceReport/*`, `Helpers/GeoLocation.php`, `cron/{attendance_reminders,auto_clockout}.php` | clock-in/out, geo-fence (office lat/lng/radius 50m), late flag, auto clock-out, dashboards, SMS+push reminders | `attendance` (12912), `offices`, `absent_deductions` (0), `absent_exemptions` (0), `push_subscriptions` (2), `notifications` | Employees, Notifications/SMS, Push | Legacy/working |
| Meetings + minutes | `Controllers/Meeting/{Meeting,MeetingMinutes}Controller.php`, `Services/{MeetingService,MeetingRules,MeetingMinutesService}.php` | schedule, invite/confirm/decline, attendance; minutes with agenda/decisions/AOB/action items | `meetings` (0), `meeting_invitations` (29), `meeting_minutes*` (0) | Employees, Users | Legacy/working (empty tables) |
| Strategy & performance | `Controllers/HR/{StrategicPlan,PerformanceContract,Workplan,SectionalObjective,KPI,Appraisal,AppraisalCycle}Controller.php`, `Services/Workplan/*` | strategic plan → goals → targets → contracts → workplans → KPIs → sectional objectives; appraisals + cycles + scores | `strategic_plan` (1), `strategies` (0), `goals` (5), `strategic_targets` (10), `performance_contracts` (129), `performance_indicators` (94), `workplan_objectives` (194), `workplan_logs` (9), `kpis` (0), `objectives` (0), `appraisal_cycles` (5), `appraisal_scores` (176), `employee_appraisals` (36), `cycle_indicators` (4), `jdac_*` | Employees, Financial years | Legacy/working |
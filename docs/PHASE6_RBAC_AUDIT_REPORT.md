# ROLE & PERMISSION SYSTEM AUDIT REPORT

**Phase:** Role, Page & Permission Restriction System Enhancement
**Repository:** MUWASCO HR Management System (`muwascoHRv2`)
**Branch:** `feature/phase5-backend-domain-architecture`
**Status:** Implemented, tested, matrix-locked

---

## Existing Authorization Architecture (before this phase)

The system already had a Phase 2 authorization engine built around:

- **`backend/config/permissions.php`** - the permission catalog (modules ->
  actions, `page`/`action` types, official role list).
- **`role_permissions`** table - role -> module/action grants (`is_granted`).
- **`user_page_permissions`** table - per-user `allow`/`deny` overrides.
- **`AuthorizationService`** (singleton) - the single resolution engine with
  documented precedence: unauthenticated -> DENY; super_admin -> ALLOW (policy);
  explicit user override -> ALLOW/DENY; own-profile self-service -> ALLOW; role
  permission -> ALLOW/DENY; no rule -> DEFAULT DENY.
- **`AuthorizationMiddleware::enforce('module:action')`** - server-defined gate
  on every `api.php` route. Routes without a permission must be allowlisted.
- **`OrgScope`** - organizational scope resolution (department/section/
  subsection) + `scopeWhere()` SQL narrowing.
- **Frontend** - `AuthContext.can()`/`canAny()` over the effective permission
  strings returned by `/auth/user`; a Sidebar already gated by `can()`; a
  `ProtectedRoute` guard with a permission prop; a Page Permission Registry
  (`frontend/src/config/pagePermissions.jsx`).

## Problems Found

1. **HR Manager / Managing Director Settings-API bypass.** The Settings page
   tabs were `super_admin`-only, but the **system settings API** (`GET|PUT
   /settings`) was gated `admin:view`/`admin:manage`, which were still seeded to
   `hr_manager`. Hiding the sidebar/tab did not protect the API (Section 8).
2. **Permission administration still open to HR.** `permission_overrides:view/
   manage` and `users:*` remained granted to `hr_manager`, contradicting
   Section 8 ("HR Manager must also be restricted from permission management").
3. **Officer/Employee over-broad default grants.** Non-manager roles still held
   `employees:view`, `reports:view`, `financial_year:view`, `performance:view`
   etc. - pages/actions Section 4 says officers must NOT have by default.
4. **Leave Roster / Oversight readable by officers.** `/leave/roster/**` reads
   were gated `leave:view`; officers hold `leave:view`, so an officer could
   fetch other employees' planned leave directly - a Section 33/4 violation.
5. **Hardcoded role checks scattered in the frontend.** `MANAGER_ROLES` /
   `isManager()` in `ManageLeaveLayout.jsx`, `MANAGER_ROLES` /
   `FULL_FILTER_ROLES` in `LeaveProfile.jsx`, `role === 'hr_manager'` in
   `AppraisalCycles.tsx`, and a `visible: () => true` Settings item in the
   Sidebar (Section 26).
6. **Attendance Dashboard visible in sidebar to `attendance:view` holders.** The
   dashboard route requires `attendance:manage`, but the sidebar child shown to
   anyone with `attendance:view` - a Section 18 parent-menu != child-access
   mismatch.
7. **Stale permission sets.** No proactive refresh: permission changes required
   a manual reload / storage clearing (Section 31).
8. **No access-denial auditing** for Settings / permission-override modules
   (Section 32 who/what/when).
9. **No deterministic re-run of the role matrix** - migrations that edited
   `role_permissions` ran only once (a marker-row early exit); editing the
   matrix and re-running did nothing.

## Permission Resolution Model

The existing Phase 2 precedence was correct and is kept - improved, not replaced:

```text
Authenticated user id (never a client-supplied role)
   |
1. Super Admin policy            -> ALLOW (always; cannot be overridden)
2. Explicit user DENY override   -> DENY
3. Explicit user ALLOW override  -> ALLOW
4. Own-profile self-service      -> ALLOW (profile module)
5. Role permission               -> ALLOW / DENY (role_permissions)
6. No rule                       -> DEFAULT DENY (unknown role / permission = DENY)
```

**Data scope is resolved separately** after permission: `OrgScope` derives
department/section/subsection from the authenticated session + employee record
- never from request parameters, query strings, hidden fields or localStorage.
Page access (`attendance:view`) is deliberately distinct from data scope
(`attendanceWhere()` -> own / unit / all).

This precedence is identical across backend (`AuthorizationService`), route
gates (`AuthorizationMiddleware`), the `/auth/user` effective-set the frontend
consumes, the sidebar, the route guards and the permission-management UI
(provenance badges: Default / Granted / Denied / Effective, with source labels).

## Default Role Matrix

Applied by migration `038_role_permission_matrix.sql` (idempotent, always
re-runnable) and locked by `RolePermissionMatrixTest`:

| Role | Default matrix |
|---|---|
| Officer | Dashboard, Profile (own), Attendance (own), My Meetings (authorized), Leave Application (own), Leave Profile (own), `settings:notifications` (self) - **nothing else** |
| Employee | Same minimal self-service set as Officer |
| Sub-section Head | Officer set + leave:manage/approve/reject (own subsection), workplans (own subsection), notifications |
| Section Head | Hierarchical set + section leave management + section workplans |
| Department Head | Hierarchical set + department leave management + department workplans |
| Manager | Existing configured set + **Leave Profile (own)** + `leave:view/apply` seeded (Phase 6 follow-up) + `settings:notifications` |
| HR Manager | **All HR modules EXCEPT Settings + permission admin + user admin + Settings API**; `meetings:dashboard` (org-wide) + `settings:notifications` |
| Managing Director | **All operational modules EXCEPT Settings + permission admin + user admin + Settings API**; `meetings:dashboard` + `settings:notifications` |
| BOD Chairman | Existing configured set + **Leave Profile (own)** + `leave:view/apply` seeded + `settings:notifications` |
| Super Admin | Everything (engine policy + catalog rows for UI completeness) |
| Unknown role | Everything denied (explicitly tested) |

`settings:notifications` is granted to **all 10 roles** - it is the self-service
own-preferences tab (backend preference APIs are authenticated-only self-service,
allowlisted in `config/authz_allowlist.php`). It is NOT a Settings-administration
grant.

**Leave Profile page (`/leave/profile`) is open to EVERY role** (`leave:view`,
seeded for manager + bod_chairman in the Phase 6 follow-up). Data scope for the
profile is always the caller's OWN record for non-manager roles
(`LeaveProfileService::canViewProfile`) - heads see their unit, HR/MD/super
admin see anyone.

**Meetings Dashboard (`/meetings`) is restricted to `meetings:dashboard`**
seeded to `hr_manager`, `managing_director`, `super_admin` only. Personal
invitations remain at `/my-meetings` (`meetings:view`), open to every invited
role.

## Page Permission Registry

`frontend/src/config/pagePermissions.jsx` is the single registry: each protected
route maps to its required permission, consumed by `App.jsx` (route guards), the
`Sidebar` (visibility) and `SettingsLayout` (tabs + index redirect). Key entries:

| Page / route | Permission |
|---|---|
| `/dashboard` | `dashboard:view` |
| `/profile` | `profile:view` |
| `/attendance` | `attendance:view` |
| `/attendance/dashboard` | `attendance:manage` |
| `/leave`, `/leave/apply`, `/leave/profile` | `leave:view` / `leave:apply` / `leave:view` (Leave Profile open to ALL roles) |
| `/leave/manage` | `leave:manage` |
| `/leave/roster`, `/leave/oversight` | `leave:manage` (Phase 6 change) |
| `/my-meetings` | `meetings:view` |
| `/meetings`, `/meetings/:id/details`, `/meetings/:id/confirm` | `meetings:dashboard` (Phase 6 follow-up: org-wide dashboard - HR/MD/super admin only) |
| `/meetings/create`, `/meetings/:id/edit` | `meetings:create` / `meetings:edit` |
| `/strategy/workplans*` | `workplan:view` (tier scope server-side) |
| `/settings` + every tab | `settings:view` / `settings:<tab>` |
| `/employees/**`, `/departments`, HR admin, reports | their catalog permissions |

## Data Scope Model

- **Attendance:** `OrgScope::attendanceReadMode()` -> `all` (HR, super admin, MD,
  PME/Audit leadership), `unit` (dept/section/subsection heads - narrowed to own
  unit), `own` (everyone else, pinned to `a.employee_id = <own db id>`). SQL is
  injected through `attendanceWhere()`; single-record endpoints go through
  `attendanceEmployeeAllowed()` (IDOR guard). Unresolvable identity/unit -> `1=0`
  (deny), never expose data.
- **Leave profiles:** `LeaveProfileService::canViewProfile($target)` - officer
  -> only own `employees.id`; heads -> same subsection/section/department;
  HR/MD/super admin -> anyone. The employee *list* endpoint (`profileEmployees`)
  returns only the caller's reachable set.
- **Approvals/workplans/strategy:** OrgScope narrowing per module (existing
  `scopeWhere`, `canManagePerformance` chain).
- **Meetings:** service-level invitee/organizer authorization (existing).

## Sidebar Changes

- Settings item now visible only when the user holds any settings registration
  permission (`SETTINGS_VISIBILITY_PERMISSIONS` = `settings:view` or
  `settings:notifications`) - replaced `visible: () => true`.
- Attendance Dashboard child requires `attendance:manage` (not `attendance:view`).
- Roster / Oversight children require `leave:manage` (independent per-child
  checks, Section 18) - replaced `canViewLeave`.
- **Meetings Dashboard** child requires `meetings:dashboard` (Phase 6
  follow-up) so only HR/MD/super admin see the org-wide dashboard; the
  Meetings group itself stays visible to every `meetings:view` role (they see
  My Meetings / Create Meeting per their own permissions).
- **Leave Profile** is now reachable for ALL roles: the non-manager Leave
  branch renders a submenu with Applications + Leave Profile (was a single
  link with no profile access).
- All other items remain centralized-`can()`-driven; group headers render only
  when at least one permitted child exists.

Sidebar = UX only. Routes and backend still enforce everything independently.

## Route Protection Changes

- `ProtectedRoute` renders an **Access Denied** screen for a missing
  permission (no silent redirects), and `SafeFallback` redirects unknown URLs to
  the first permitted route (loop-safe).
- Every route in `App.jsx` is wrapped with the registry permission, including
  **each Settings tab** and the workplan tier routes.
- `SettingsLayout` redirects `/settings` to the first permitted tab and shows
  Access Denied when the user has no settings permission at all.

## Backend Authorization Changes

- **Settings API closure:** `role_permissions` now revokes `hr_manager`
  `admin:view`/`admin:manage` (the `/settings` GET/PUT gates) - HR can no longer
  read or mutate system settings through direct API calls.
- **Permission admin closure:** `hr_manager` `permission_overrides:*` and
  `users:*` are explicit `0` rows; `permission_overrides:view/manage` gates all
  `/permissions/*` routes.
- **Roster gate:** `/leave/roster/**` reads raised from `leave:view` to
  `leave:manage`.
- **Attendance enforcement:** `AttendanceController` `index`/`today`/
  `byEmployee`/`hrEmployeeHistory` and `AttendanceDashboardService` pin data via
  `OrgScope::attendanceWhere()`/`attendanceEmployeeAllowed()`; ownership is
  always derived from the session.
- **Denial auditing:** `AuthorizationMiddleware` writes an audit entry (who /
  what / when, path only) when a `settings` or `permission_overrides` route is
  denied (403).
- **Effective-set refresh:** `AuthorizationService::getEffectivePermissionStrings`
  (already the frontend source) reflects the deterministic matrix - the matrix
  tests assert against the real engine + seeded tables.

## Permission Service Changes

- `PermissionController` re-validates actor authorization on every endpoint
  (route-gated by `permission_overrides:*`), refuses self-overrides and
  super-admin targets, validates module/action against the catalog, and audits
  every override change. Its `userPermissions` payload includes the target
  user's resolved organizational scope (`OrgScope::forUser`) for the user
  management UI.
- The frontend `permissionService.ts` structure is kept; the Permission
  Management tab now displays **Default (inherited) / Granted / Denied /
  Effective** provenance from `role_permissions` + overrides + `effective[]`
  source labels, plus the target user's org scope.

## Settings Permission Changes

| Setting surface | Default |
|---|---|
| `/settings` page shell (`settings:view`) | super_admin |
| Profile / Security / Audit / Users / Permissions / Monitoring tabs | super_admin (each independently seeded) |
| Notifications tab (`settings:notifications`) | **All 10 roles** (self-service) |
| `GET|PUT /settings` system-settings API (`admin:*`) | super_admin (hr_manager revoked) |
| `/permissions/*` API (`permission_overrides:*`) | super_admin |
| `/users` admin API (`users:*`) | super_admin |

All defaults overridable per user via the Override System.

## HR Manager Restrictions

- Full HR operational access (employees, departments, attendance, leave,
  payroll, meetings, reports, consent, holidays, financial years, strategy).
- **Denied by default:** Settings page + every administrative tab, the
  Settings API (`admin:*`), permission management (`permission_overrides:*`) and
  user administration (`users:*`). All previously-seeded grants are now explicit
  `0` rows -> override UI shows "role default: denied".
- `settings:notifications` self-service tab remains available.
- Restorable per-user only through an explicit allow override made by someone
  with `permission_overrides:manage`.

## Managing Director Restrictions

- Full operational catalog (dashboard, employees, departments, attendance,
  leave incl. manage/approve/reject/invalidate, reports, profile, performance,
  consent, financial years, holidays, meetings, complaints, payroll,
  notifications).
- **Denied by default:** Settings module (all tabs + shell), Settings API,
  permission management, user administration.
- `settings:notifications` self-service available.

## Super Admin Access

- Everything: all pages, modules, settings tabs, Settings API, permission
  management, all scopes, all records (engine policy + catalog rows for UI).
- Remains authenticated, audited (override changes, role changes), and can
  never be de-privileged by an override.

## API Bypass Test Results

Checked against the final migration state on both dev and test databases:

| Bypass attempt | Expected | Result |
|---|---|---|
| Officer navigates to `/settings`, `/settings/permissions`, `/settings/users` | 403 / Access Denied | PASS - `settings:*` super_admin; officer engine-deny |
| Officer changes employee id on `/attendance/employee/{id}` / `hr-employee-history` | 403 | PASS - `attendanceEmployeeAllowed` own-row pin |
| Officer requests another employee's leave profile (`/leave/profile/{id}`) | 403 / own data | PASS - `canViewProfile` ownership |
| Officer fetches department-level attendance | own records only | PASS - `attendanceWhere` = `a.employee_id = ?` |
| Officer calls `/leave/roster/upcoming` etc. | 403 | PASS - gate now `leave:manage` |
| Sub-section head requests another section's attendance / leave profile | 403 | PASS - unit narrowing + canViewProfile |
| Department head requests another department's data | 403 | PASS - unit narrowing |
| Section head requests `/leave/manage?section_id=99` (foreign section) | workflow-scope deny | PASS - service scope; gate `leave:manage` |
| HR Manager calls `GET /settings`, `PUT /settings` | 403 | PASS - `admin:view/manage` revoked |
| HR Manager calls `/permissions/*`, `/users*` APIs | 403 | PASS - `permission_overrides`/`users` revoked |
| Managing Director calls Settings APIs | absent-from-role -> deny | PASS - no `settings`/`admin`/`permission_overrides` grants to MD |
| Unauthorized user calls permission API | 403 | PASS - route-gated `permission_overrides:view` |
| Unknown role attempts any module | 403 | PASS - explicit test `testUnknownRoleAndUnknownPermissionDefaultToDeny` |
| Officer calls `GET /meetings` / `/meetings/stats` | 403 | PASS - gates `meetings:create` / `meetings:dashboard`; officer holds neither |
| Dept Head calls `GET /meetings` | own organized meetings only | PASS - `indexAction` scopes to `m.created_by = caller` unless `meetings:dashboard` |
| Officer navigates to `/meetings` / `/meetings/:id/details` | Access Denied | PASS - registry requires `meetings:dashboard` |

## Override System

`user_page_permissions` semantics unchanged and still honored:

```text
ROLE DEFAULTS  +  EXPLICIT GRANTS  -  EXPLICIT RESTRICTIONS  =  EFFECTIVE
```

- `allow` granted, `deny` revoked, no row = inherit role default (assignment of a
  custom permission never destroys all role defaults).
- Restore-default flow = remove the override row.
- Super admin cannot be overridden; nobody may override themselves.
- Audit logged for every grant/revoke (actor, target, module:action, old/new
  value, timestamp).

## Officer Restrictions

- Dashboard / Profile / Attendance (own) / My Meetings / Leave Application /
  Leave Profile (own) only.
- Cannot open Manage Leave, Roster, Oversight, Appraisal, HR Admin, Reports,
  Strategy, Settings or any other-employee record.
- Attendance and leave-profile reads are pinned to their own record server-side
  (IDOR-guarded); modifying employee IDs, query params or direct API calls is
  rejected (403 / own-data only).
- `employees:view`, `reports:view`, `financial_year:*`, `performance:view`,
  `strategic_plan:*`, `performance_contract:*`, `workplan:*` etc. are revoked by
## Security Issues Fixed

1. HR Manager / Managing Director Settings-API bypass (system settings readable/
   writable by HR).
2. HR Manager permission-management + user-admin capability retained from
   Section 8 lockdown.
3. Officer fetch of org-wide leave roster/oversight.
4. Officer access to Employees list, Reports, Financial Year, Performance,
   Strategy pages by default.
5. Scattered frontend role arrays (`MANAGER_ROLES`, `FULL_FILTER_ROLES`,
   `isManager`, `role === 'hr_manager'`) replaced with centralized checks.
6. Attendance Dashboard child link shown without `attendance:manage`.
7. Settings sidebar item shown to everyone (`visible: () => true`).
8. Stale permission sets after admin changes (Section 31 refresh).
9. Missing audit entries for settings/permission-override denials (Section 32).
10. Non-re-runnable role matrix migration (marker early-exit) making matrix
    edits silently ineffective on already-migrated environments.

## Files Modified

Backend:
- `backend/config/permissions.php` - `settings` module catalog (8 actions).
- `backend/database/migrations/038_role_permission_matrix.sql` - default matrix
  incl. `admin:*` revocation.
- `backend/database/run_migration_038.php` - always re-runs the reconciliation.
- `backend/app/Helpers/OrgScope.php` - attendance scope helpers
  (`attendanceReadMode`, `attendanceWhere`, `attendanceEmployeeAllowed`,
  `attendanceScope`).
- `backend/app/Controllers/AttendanceController.php` - scope pinning on all read
  endpoints + IDOR guards.
- `backend/app/Services/AttendanceDashboardService.php` - scope-aware queries.
- `backend/app/Middleware/AuthorizationMiddleware.php` - denial audit for
  settings/permission modules; enforce() emits 403.
- `backend/app/Controllers/Settings/PermissionController.php` - target-org-scope
  in `userPermissions`; consistent actor re-checks.
- `api.php` - `/leave/roster/**` read gates `leave:view` -> `leave:manage`
  (financial-years kept at `leave:view` for the Leave Profile FY selector);
  `/meetings` + `/meetings/stats` -> `meetings:dashboard`;
  `/meetings/eligible-employees` -> `meetings:create`.

**Phase 6 follow-up (Leave Profile for all roles / Meetings Dashboard senior-only):**
- `backend/database/migrations/038_role_permission_matrix.sql` - added
  `meetings:dashboard` seeds (hr_manager/managing_director/super_admin) and
  `leave:view/apply` seeds for manager + bod_chairman.
- `backend/app/Services/LeaveProfileService.php` - `canViewProfile` now treats
  employee/manager/bod_chairman as self-only (like officer).
- `backend/config/permissions.php` - `meetings:dashboard` catalog action.
- `frontend/src/config/pagePermissions.jsx` - `/meetings*` -> `meetings:dashboard`.
- `frontend/src/components/Sidebar.jsx` - meetings dashboard child +
  all-role Leave Profile submenu.
- `backend/tests/Unit/Authorization/RolePermissionMatrixTest.php` - new
  `testEveryRoleCanOpenTheirOwnLeaveProfile` + dashboard assertions.
Frontend:
- `frontend/src/config/pagePermissions.jsx` - the Page Permission Registry
  (settings tabs, roster/oversight `leave:manage`, attendance dashboard).
- `frontend/src/App.jsx` - every route registry-guarded; SafeFallback.
- `frontend/src/components/ProtectedRoute.jsx` - AccessDenied, loop-safe fallback.
- `frontend/src/components/AccessDenied.jsx` - new Access Denied screen.
- `frontend/src/components/Sidebar.jsx` - settings visibility (registry-driven),
  per-child gates, attendance + roster fixes, registry imports.
- `frontend/src/context/AuthContext.jsx` - `refreshPermissions()` + focus /
  visibility / 5-min interval refresh (Section 31).
- `frontend/src/components/settings/SettingsLayout.jsx` - permission-driven
  tabs, index redirect, module-level Access Denied.
- `frontend/src/components/settings/SettingsPermissionsTab.jsx` - provenance
  badges (Default/Granted/Denied/Effective), org-scope display.
- `frontend/src/pages/leave/ManageLeaveLayout.jsx` - removed MANAGER_ROLES /
  isManager; permission-driven guard.
- `frontend/src/pages/leave/LeaveProfile.jsx` - removed MANAGER_ROLES /
  FULL_FILTER_ROLES; `can('leave','manage')` gating.
- `frontend/src/pages/hr-admin/AppraisalCycles.tsx` - removed `role==='hr_manager'`;
  `can('performance','manage')`.
- `frontend/src/widgets` / attendance pages - centralized `can()` where relevant.

Docs:
- `docs/AUTHORIZATION.md` - Phase 6 addendum; corrected Section 7.2 and 10.1.
- `docs/PHASE6_RBAC_AUDIT_REPORT.md` - this report.

## Database Changes

- `role_permissions`:
  - `settings` module: 8 actions for super_admin; `settings:notifications` for
    all 10 roles; all administrative settings actions super_admin-only.
  - `hr_manager` explicit revocations (`permission_overrides:*`, `users:*`,
    `admin:*`) as `is_granted = 0` rows.
  - Officer/Employee pruning: `employees:view`, `reports:*`, `financial_year:*`,
    `performance:*`, `strategic_plan:*`, `performance_contract:*`,
    `workplan:*`, `kpi:*`, `sectional_objective:*` -> explicit `0` rows
    (visible as "role default: denied" in the UI).
  - `managing_director` full-operational grants.
- No schema changes. All upserts use `uk_role_module_action` and are idempotent.

## Migration Requirements

A single, additive, idempotent SQL migration:
`backend/database/migrations/038_role_permission_matrix.sql`, run with
`php backend/database/run_migration_038.php`. Safe to run repeatedly - it now
**always** reconciles `role_permissions` to the current file (the old marker-row
early exit was removed). Apply to every environment: dev / test / staging / prod.

## Test Results

- Backend Unit suite: **222 tests, 994 assertions, OK** (1 pre-existing skip).
- Authorization suite: **67 tests, 582 assertions - all pass**, including:
  - `RolePermissionMatrixTest` (Officer, Sub-section/Section/Department Head,
    HR Manager, Managing Director, Super Admin, unknown role, roster/oversight,
    settings notifications).
  - `OrgScopeAttendanceScopeTest` (read modes, `attendanceWhere`, IDOR guards).
  - `AuthorizationServiceTest`, `PrivilegeEscalationTest`,
    `PermissionCatalogTest`, `RoutePermissionMapTest`.
- Frontend: `npm test` -> **9 files, 52 tests pass**; `npm run build` succeeds.

## Remaining Issues

- **Pre-existing:** `ErrorTrackerFailSafeTest` singleton state issue (unrelated
  to authorization, tracked separately).
- **Role changes mid-session:** the permission set force-refreshes from
  `/auth/user` (which re-derives role and permissions), but a user whose *role*
  is changed keeps the session `user_role` until next login. Safe-by-default:
  the engine re-derives roles from trusted context on every authorization check.
- **Frontend is UX:** hiding sidebar/route/button must never be treated as a
  security boundary; all enforcement is server-side (unchanged principle).

## Subsection Head Restrictions

- Officer access inherited, plus subsection leave management
  (`leave:manage/approve/reject`) and own-subsection workplans.
- Data scope pinned to own subsection (`scopeWhere` and `attendanceWhere` unit
  narrowing); other subsections/sections/departments -> deny.

## Section Head Restrictions

- Hierarchical lower access + section leave management; data scope = own section.
- Other sections / other departments -> deny (tested through `1=0`/`1=1` and
  `attendanceEmployeeAllowed` unit logic + matrix tests).

## Department Head Restrictions

- Hierarchical lower access + department leave management; data scope = own
  department. Other departments -> deny.

## Phase 10 Correction - Employees / Roster / Reports are HR-Only Modules

### Root Cause Identified

1. **Employees:** sub_section_head / section_head / dept_head held
   `employees:view(/edit)` from legacy migration-004 seeds; managing_director
   held employees:view/create/edit/delete granted by this migration's section 5
   reconciliation. Sidebar, route registry and API gates all key off
   `employees:view`, so the seed was the single point of failure.
2. **Reports:** heads + MD held `reports:view(/export)` from the same origins.
   All `/reports/*` API gates were already correct.
3. **Roster (structural):** roster APIs, the sidebar Roster group and the page
   registry were all gated `leave:manage` - a permission heads/MD must keep for
   scoped Leave Management. One permission covered two business functions.
4. Attendance Dashboard/Records and Strategy pages were already correctly
   scoped server-side (OrgScope + unit tests) and were left unchanged.

### HR Admin vs HR Manager Analysis

There is no distinct `hr_admin` role in `users` (roles present:
super_admin, hr_manager, dept_head, section_head, sub_section_head, manager,
officer, managing_director + 25 legacy users with an empty role, who are
protected by deny-by-default). "HR Admin" in the sidebar is the HR
Administration nav GROUP (Financial Year, Appraisal Cycles, Consent, Holidays),
not a persona. The corrected matrix's "HR Admin" persona maps 1:1 onto
`hr_manager` (legacy `admin` normalizes to `super_admin`). One shared
operational baseline, differentiated only through the permission override
system - no duplicate architecture.

### Changes Made

- `backend/config/permissions.php`: new `leave:roster` catalog action
  ("Leave Roster / Oversight", page type).
- `backend/database/migrations/038_role_permission_matrix.sql` section 8:
  - `leave:roster` granted to `hr_manager` + `super_admin` ONLY; explicit
    `is_granted = 0` rows for the three heads + MD (UI shows
    "role default: denied", overrides can still re-grant per user).
  - `employees:view(/edit/create/delete)` and `reports:view(/export)` flipped
    to explicit 0-rows for sub_section_head, section_head, dept_head and
    managing_director.
  - Section 5 no longer grants MD employees/reports.
- `api.php`: all 11 roster routes repointed `leave:manage` -> `leave:roster`
  (`/leave/roster/financial-years` stays `leave:view` - self-service pages
  depend on it).
- `frontend/src/config/pagePermissions.jsx`: `/leave/roster` and
  `/leave/oversight` -> `leave:roster`.
- `frontend/src/components/Sidebar.jsx`: Roster group + Leave Roster +
  Leave Oversight visibility -> `can('leave', 'roster')`. Employees/Reports
  items needed NO code change (already permission-driven - the seed fix
  propagated through the whole chain).
- `backend/tests/Unit/Authorization/RolePermissionMatrixTest.php`: 18 new
  assertions locking the corrected matrix (officer + heads + MD denials for
  employees/reports/roster; HR + super admin allows).
- `backend/database/run_migration_038.php`: verification checks updated
  (MD employees expect 0; heads employees/reports expect 0; leave:roster
  grant count expect 2; heads/MD roster expect 0).

### Corrected Default Matrix (delta)

| Module    | Officer | Sub-section Head | Section Head | Dept Head | HR Manager | Managing Director | Super Admin |
|-----------|---------|------------------|--------------|-----------|------------|-------------------|-------------|
| Employees | DENY    | DENY             | DENY         | DENY      | ALLOW      | DENY              | ALLOW       |
| Roster    | DENY    | DENY             | DENY         | DENY      | ALLOW      | DENY              | ALLOW       |
| Reports   | DENY    | DENY             | DENY         | DENY      | ALLOW      | DENY              | ALLOW       |

Heads keep their management modules (scoped Attendance oversight, Leave
Management, Workplans, Strategy & Performance); page access remains separate
from data scope (OrgScope-enforced server-side).

### Phase 10 Test Results

- Migration re-run on dev + test DBs: all 9 runner checks green.
- Authorization suite: 67 tests, 600 assertions - OK.
- Full backend Unit suite: 222 tests, 1012 assertions - OK (1 pre-existing skip).
- Frontend: npm test 9 files / 52 tests pass; npm run build succeeds.

### Remaining Issues (Phase 10)

- Heads still hold legacy `financial_year:view/create(/edit)` seeds (they see
  the "Financial Year" item of the HR Admin group). Intentionally left
  role-configured - revocable via Settings -> Permissions without a code
  change - because the correction scope was Employees/Roster/Reports.
- 25 users with an empty `users.role` are fully deny-by-default (safe), but
  should be assigned real roles by HR.

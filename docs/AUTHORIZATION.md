# 🔐 AUTHORIZATION MODEL — Phase 2 Consolidation

> **Status:** Implemented (Phase 2)
> **Scope:** Roles, Permissions & Authorization Consolidation

This document is the authoritative description of the HR system's
authorization architecture after Phase 2.

```text
AUTHENTICATION          →  Who is this user?
AUTHORIZATION           →  What actions can this user perform?
DATA SCOPE              →  Which records can this user access?
```

---

## 1. Architectural Overview

Every authenticated API request flows through a single, deterministic
authorization pipeline:

```text
Authenticated Request
        ↓
AuthenticationMiddleware    (global gate — session/token, is_active)
        ↓
Route dispatch (api.php)    (server-defined permission on EVERY route)
        ↓
AuthorizationMiddleware::enforce($route['permission'])
        ↓
AuthorizationService::hasPermission(userId, module, action)
        ↓
| 1. userId invalid/unauthenticated        → DENY
| 2. SUPER ADMIN (trusted context)         → ALLOW (policy, see §5)
| 3. Explicit user override (allow/deny)   → ALLOW / DENY  (§7)
| 4. Own 'profile' module                  → ALLOW          (§8)
| 5. Role permission (role_permissions)    → ALLOW / DENY   (§6)
| 6. No rule                               → DEFAULT DENY   (§9)
```

Notable properties:

- The **permission requirement of every endpoint is defined by the server**
  (in the `api.php` route table), never by request parameters. See §10.
- A route with `null` permission is *authenticated-only*; it must appear in
  `backend/config/authz_allowlist.php` (self-service / public list).
- Controllers still call `requirePermission()` as **defense in depth**, but all
  of them funnel through the same underlying engine (§4.1).
- **Data scope is a separate question** from permission (§11).

---## 2. Components

| Component | Role |
|---|---|
| `AuthenticationMiddleware` | Single authentication gate. Defines `PUBLIC_ROUTES` (the only pre-auth endpoints). |
| `AuthorizationMiddleware` | Enforces a server-defined `module:action` on a route; **never reads request parameters**. |
| `AuthorizationService` | The single authorization engine (singleton). See §3. |
| `RBAC` helper | Role↔permission lookup against `role_permissions`, plus super-admin policy. |
| `Auth` helper | Resolves the authenticated user id and session role. |
| `BaseController` | `requirePermission(module, action)` convenience → `Auth::hasPermission()`. |
| `Gate` / `EmployeePolicy` | Thin conveniences that **delegate** to `AuthorizationService` (§4.2) — never parallel rules. |
| `PermissionService` | Manages user-specific overrides (`user_page_permissions`), validates against catalog, audits. |
| `UserPagePermission` | Model over `user_page_permissions`; module lists are **derived from the catalog** (§6). |
| `config/permissions.php` | **The permission catalog — single source of truth** (§6). |
| `config/authz_allowlist.php` | Reviewed allowlist of permission-less (authenticated-only/public) routes (§10). |
| Frontend `useAuth().can()` | UX visibility only — never a security boundary (§12). |

---

## 3. AuthorizationService — the Single Engine

`AuthorizationService` is a **singleton** (`getInstance()`; private constructor). Never `new AuthorizationService()`.

All authorization questions go through `hasPermission(int $userId, string $module, string $action): bool`.

### 3.1 User identity vs role — never mixed

The engine **always** receives an authenticated **user id** (or `null` = "session user"). The role is **derived internally** from trusted context:

1. If `$userId` is null → use the session user id (`Auth::id()`).
2. If `$userId` is the **acting session user** → session role may be used.
3. If `$userId` refers to a **different user** → that user's **target role is loaded from the database**. The acting user's role must never authorize a different user id (regression-locked by tests).

`getAllEffectivePermissions($targetUserId)` resolves the *target* role from the DB — not the acting admin's session role (F-04 fixed).

### 3.2 Service lifecycle

- Created via `AuthorizationService::getInstance()` only.
- `Gate`, `EmployeePolicy`, `AuthorizationMiddleware` all use the singleton.
- No `new AuthorizationService()` remains.

---

## 4. Entry Points & Consolidation

### 4.1 One primary entry point

The **only** authorization engine is `AuthorizationService::hasPermission()` (plus `hasPageAccess()`, `getEffectivePermissionStrings()`, `isPermissionManager()`).

```text
BaseController::requirePermission(module, action)
  → Auth::hasPermission(module, action)        (session user)
  → AuthorizationService::hasPermission(...)

AuthorizationMiddleware::check('module:action')
  → Auth::getInstance()->id()
  → AuthorizationService::hasPermission(...)

Gate::allows(...) / EmployeePolicy::...()
  → AuthorizationService::getInstance()->hasPermission(...)
```

### 4.2 Gate & EmployeePolicy

Thin conveniences wired to the same engine. Repaired so they never call `new AuthorizationService()` and never pass a role string where a user id is expected (F-01/F-02/F-03 fixed).

### 4.3 Hardcoded role checks — classification

| Class | Meaning | Handling |
|---|---|---|
| **A. Permission** | "only HR manager may create financial years" | Converted to catalog permissions where broken; new checks use permissions. |
| **B. Data scope** | "dept head sees only their department" | **Reusable scope policies** (`OrgScope`, per-module policies) — §11. |
| **C. Workflow** | "dept head is the first leave approver" | Legitimate business logic — left intact. |

The leave approval chain stays workflow (class C).
---

## 5. Super Admin Policy

- A user whose trusted role is `super_admin` (or legacy `admin`, normalized in RBAC) is **always allowed** for any catalog action.
- Super admin **cannot be restricted by a user override** — even a stray manual deny row is ignored (regression-locked: `testSuperAdminCannotBeRestrictedByAStrayOverrideRow`).
- Override **administration** against a super admin target is **rejected** (`PermissionService`).
- `getEffectivePermissionStrings()` returns the **full catalog** for super admin so the frontend context reflects the policy.

## 6. Permission Catalog — Single Source of Truth

`backend/config/permissions.php` defines every module + action + label + type (`page`/`action`) + official role list. Drift is prevented by:

- `PermissionCatalogTest::*` — structure, `view` actions, unique roles, grantable-module derivation, role_permissions row coverage, seed coverage.
- `RoutePermissionMapTest::*` — every mapped route permission exists in the catalog.
- A drift test asserts no controller/service uses permissions that don't exist in the catalog (e.g. the old `meetings.minutes.*` typo is gone).

`UserPagePermission::getAllModules()` / `getModuleNames()` derive straight from the catalog — no duplicated hand-maintained list (F-07 fixed).

---

## 7. User-Specific Overrides

Stored in `user_page_permissions` (key: `user_id` + `module` + `action`, unique constraint `uq_user_module_action`).

### 7.1 Values and hierarchy

| Override | Effect |
|---|---|
| `allow` | Grants the permission regardless of role |
| `deny` | Revokes the permission regardless of role |
| (no row) | **INHERIT** — falls through to role permission |

Resolution order (documented and locked by tests):

```text
Authenticated user id
  ↓
Explicit DENY override?      → DENY
Explicit ALLOW override?     → ALLOW
Role permission?             → ALLOW / DENY
No rule?                     → DEFAULT DENY
```

### 7.2 Override administration rules

Only holders of `permission_overrides:view` / `permission_overrides:manage` can view/manage overrides — enforced at the **route** level and in the service.
**Default (migration 038):** `super_admin` only. `hr_manager` had these revoked (explicit `is_granted = 0` rows) so HR settings/permission administration is locked down per the default role matrix (§2 of the Phase 6 report). Any role can be restored per-user through an explicit allow override.

- **No one may override their own permissions** (`PermissionService` rejects actor == target) — privilege escalation guard (F-08 fixed).
- **Super admin targets are protected** — no override rows may be written.
- `setOverride` validates module/action against the catalog and the value against `allow`/`deny`.
- Every change writes an **audit trail** (actor, target, changed permission, old/new value, timestamp).
- Writes clear the engine's per-user cache so changes are **immediate**.

---

## 8. Own-Profile Exception

A user may always view/edit their **own** profile (`profile` module) even without an explicit role grant (step 4 of the pipeline).

## 9. Default Deny

If no rule matches (or role lookup fails / table missing), the answer is **DENY**. `RBAC::lookup()` returns deny on missing rows and on query failure. Regression-locked by `testDefaultDenyForUngrantedPermission`.

---

## 10. Route → Permission Mapping (Server-Defined)

Every route in `api.php` carries its permission as the 6th argument of `$router->add()`, e.g.:

```php
$router->add('GET', '/employees', EmployeeController::class, 'index', [], 'employees:view');
```

- Routes **must** either declare a catalog permission **or** appear in `backend/config/authz_allowlist.php` (reviewed, justified).
- `RoutePermissionMapTest` fails if a route has neither, or if an allowlist entry is orphaned, or if a mapped permission isn't in the catalog.
- `AuthorizationMiddleware::check()`/`enforce()` take the permission as an **argument** and resolve identity via `Auth::id()`. Request parameters are never consulted (the old client-supplied permission parameters hole is gone and regression-locked).

### 10.1 Authorization matrix (key routes)

| Route | Method | Permission | Data Scope |
|---|---|---|---|
| `/auth/login`, `/auth/logout` | POST | `auth` (public / self) | n/a |
| `/auth/user` | GET | authenticated-only (self) | own record |
| `/employees` | GET | `employees:view` | organizational-wide (scope-aware via `EmployeePolicy`/OrgScope) |
| `/employees` | POST | `employees:create` | organizational |
| `/employees/{id}` | PUT | `employees:edit` | scope + IDOR check inside controller |
| `/employees/{id}` | DELETE | `employees:delete` | scope + IDOR check |
| `/departments` | GET | `departments:view` | organizational |
| `/attendance/**` records | GET | `attendance:view` / `attendance:manage` | scope (own vs unit) |
| `/attendance/clock-in|out` | POST | authenticated-only (self) | own record |
| `/leave` (applications) | GET/POST | `leave:view` / `leave:apply` | own records |
| `/leave/{id}/approve|reject` | POST | `leave:approve` / `leave:reject` | approval scope (workflow) + IDOR check |
| `/leave/manage` | GET | `leave:manage` | approval scope |
| `/leave/roster/**` | GET/POST/PUT/DELETE | `leave:roster` (Phase 10: dedicated HR-only permission; `/leave/roster/financial-years` stays `leave:view` for self-service pages) | HR-wide |
| `/reports/...` | GET | `reports:view` | scope-aware |
| `/permissions/roles` | GET | `permission_overrides:view` | n/a |
| `/permissions/users/{id}` | GET | `permission_overrides:view` | target user |
| `/permissions/users/{id}/overrides` | POST | `permission_overrides:manage` | protected (no self/SA target) |
| `/permissions/users/{id}/overrides` | DELETE | `permission_overrides:manage` | protected |
| `/meetings` CRUD | GET/POST/PUT/DELETE | `meetings:view/create/edit/delete` | scope (invitee/owner) |
| `/financial-year` | name/CRUD | `financial_year:view/create/edit` | organizational |
| `/holidays` | CRUD | `holidays:view/create/edit/delete` | organizational |
| `/strategy/**` | CRUD | `strategic_plan` / `performance_contract` / `workplan` / `kpi` / `sectional_objective` (`view`/`manage`) | OrgScope narrowed |

> The full machine-checked matrix is enforced by `RoutePermissionMapTest` against the live `api.php` route table.
---

## 11. Data Scope (Separate From Permission)

Permission answers *"can this user perform action X?"*; **scope** answers *"on which records?"*. The two are never mixed in the same check.

### 11.1 Reusable scope policy

- `OrgScope` provides reusable organizational scope resolution (org-wide / department / section / unit / own).
- `EmployeePolicy` (and per-module policies) apply action permission **and** resource ownership/scope.
- Controllers that used ad-hoc scope logic are progressively wired to these shared policies (or document a module-specific scope rule).

### 11.2 Organizational roles → scope (documented)

| Role | Scope |
|---|---|
| `super_admin` | Organization-wide |
| `hr_manager` | Organization-wide |
| `managing_director` | Organization-wide (oversight) |
| `dept_head` | Department |
| `section_head` | Section |
| `sub_section_head` | Sub-section |
| `manager` | Unit / own |
| `officer` | Own / assigned |
| `employee` | Own records only |

---

## 12. Frontend Authorization (UX Only)

- `/auth/user` and `/auth/login` return the **effective permission strings** computed by `AuthorizationService::getEffectivePermissionStrings()`.
- `AuthContext` exposes `can(module, action)` and `canAny([[...]])`.
- `Sidebar` visibility is driven by `can()` — the former hardcoded role arrays are gone.
- `ProtectedRoute` accepts an optional `permission="module:action"` prop for redirect-guard UX.
- **Security boundary remains exclusively on the backend.** The frontend permission set is UX convenience; every request is independently authorized.

---

## 13. Permission Administration & Anti-Escalation

- Only `permission_overrides:view` / `permission_overrides:manage` holders can use the permission management endpoints (route-gated).
- No actor may write an override for **themselves** (F-08).
- Super admin accounts cannot receive overrides.
- `users` role changes are hub-gated: **only a super admin can assign/change the `super_admin` role**; roles outside the catalog are rejected (`UserService::updateUserRole` guards — escalation tests).
- IDOR hardening: override endpoints operate on an explicit `{userId}` and revalidate actor authorization server-side; `EmployeePolicy` checks resource ownership.

---

## 14. Adding a New Permission / Role / Endpoint (Checklist)

1. **Add to catalog** `backend/config/permissions.php` (module, label, actions, type).
2. **Seed** `role_permissions` (additive migration + `ON DUPLICATE KEY UPDATE`).
3. **Assign** the route permission in `api.php` (6th arg) or justify an allowlist entry.
4. **Rebuild** effective sets: existing sessions refresh from `/auth/user`; the engine derives role-permissions from the DB.
5. **Test** — add/extend catalog + route-map + engine tests.

Renames/removals: perform **catalog-first**, then migrate `role_permissions`/`user_page_permissions` rows, then update route mappings, then re-run the drift tests.

---

## 15. Tests

`backend/tests/Unit/Authorization/`:

| File | Locks |
|---|---|
| `AuthorizationServiceTest.php` | resolution order, overrides, cache invalidation, super-admin policy, profile exception, target-role resolution, effective sets |
| `PrivilegeEscalationTest.php` | self-override ban, SA-target ban, catalog validation, role-assignment guards |
| `RoutePermissionMapTest.php` | every route mapped or allowlisted, catalog integrity, middleware never reads request params, admin endpoints gated |
| `PermissionCatalogTest.php` | catalog structure, grantable modules derivation, DB drift, seed coverage, no non-catalog checks |

Run: `php vendor/bin/phpunit --testsuite Unit --filter Authorization`

---

## 16. Known Remaining Work

- `ErrorTrackerFailSafeTest` (pre-existing) fails on the current branch due to singleton error-tracker state; **unrelated to authorization** — tracked separately.
- The Laravel scaffold in `backend/routes/` and `Gate.php` are documented removal candidates under Phase 3 (backend architecture).
- Data-scope policies continue to be extended module by module (they share `OrgScope` / per-module policies but are applied progressively).

---

## 17. Phase 6 — Role, Page & Permission Restriction System

The **default role access matrix** documented in this phase (requirements Section 4)
is applied deterministically by migration  38_role_permission_matrix.sql
(re-runnable — 
un_migration_038.php always reconciles to the current matrix),
and locked by ackend/tests/Unit/Authorization/RolePermissionMatrixTest.php.

Highlights on top of everything above:

- **Settings lockdown (Section 27):** the whole settings module is a protected
  module. settings:view and every administrative tab action
  (settings:profile|security|audit|users|permissions|monitoring) are seeded to
  **super_admin only**. The self-service **settings:notifications** tab is the
  sole exception and is seeded to **ALL roles** (own preferences only — the
  underlying APIs are authenticated-only self-service).
- **Settings API closure:** the system settings endpoints are gated dmin:view /
  dmin:manage. Migration 038 revoked the hr_manager seeds for dmin:*,
  permission_overrides:* and users:* (explicit 0 rows) so HR can no longer
  read/mutate system settings or administer permissions/users directly, closing
  the previous Settings-API bypass. Permissions remain per-user overridable.
- **Officer/Employee pruning (Section 4):** non-manager roles hold only
  dashboard, profile, ttendance [own], leave [apply+view],
  meetings [view+confirm] + settings:notifications. Everything else is absent
  -> default deny.
- **Roster/Oversight (Phase 10):** /leave/roster/** requires the dedicated
  **leave:roster** permission (no longer coupled to leave:manage, which heads
  need for scoped Leave Management). Sidebar, page registry and backend route
  gates all enforce the same permission.
- **Attendance data scope:** ttendance:view opens the page; *which* records a
  caller sees is decided by OrgScope::attendanceWhere() /
  ttendanceEmployeeAllowed() — own (officer/employee), own unit (heads),
  org-wide (HR/super admin/MD). The target identity is always derived from the
  session, never request data.
- **Permission refresh (Section 31):** AuthContext refreshes /auth/user
  permissions on mount, window focus / tab visibility, a 5-minute interval, and
  exposes 
efreshPermissions() for callers that hit a 403.
- **Auditing (Section 32):** denied attempts against the settings /
  permission_overrides modules are written to the audit trail with who/what/
  when (path only — never query strings or payloads).
- **Hardcoded role checks removed (Section 26):** isManager()/MANAGER_ROLES,
  FULL_FILTER_ROLES, 
ole === 'hr_manager' etc. were replaced by centralized
  can(module, action) checks in ManageLeaveLayout, LeaveProfile,
  AppraisalCycles and the Sidebar. The frontend Page Permission Registry
  (rontend/src/config/pagePermissions.jsx) is the single source for URLs ->
  required permissions, consumed by routes, sidebar and the Settings layout.

The full audit, the default matrix, per-role restrictions and the API-bypass
test results are in **docs/PHASE6_RBAC_AUDIT_REPORT.md**.

### 17.1 Follow-up: Leave Profile for all roles + Meetings Dashboard senior-only

- **Leave Profile page (/leave/profile) is open to every role** (leave:view).
  manager and bod_chairman were granted leave:view/apply; the page is linked in
  the sidebar for both the manager and non-manager Leave branches. Data scope
  stays server-side: non-manager roles see only their OWN record
  (LeaveProfileService::canViewProfile), heads see their unit, HR/MD/super
  admin see anyone.
- **Meetings Dashboard (/meetings + /meetings/stats) is restricted to
  meetings:dashboard** - a new catalog action seeded to hr_manager,
  managing_director and super_admin only. Personal invitations remain at
  /my-meetings (meetings:view) for every invited role. /meetings/create and
  /meetings/eligible-employees are gated meetings:create.

### 17.2 Phase 10 — Employees / Roster / Reports are HR-only modules

Business-rule correction: Employees, Roster and Reports are restricted HR
administrative modules. Default baseline:

| Module     | Officer | Sub-section Head | Section Head | Dept Head | HR Manager | Managing Director | Super Admin |
|------------|---------|------------------|--------------|-----------|------------|-------------------|-------------|
| Employees  | DENY    | DENY             | DENY         | DENY      | ALLOW      | DENY              | ALLOW       |
| Roster     | DENY    | DENY             | DENY         | DENY      | ALLOW      | DENY              | ALLOW       |
| Reports    | DENY    | DENY             | DENY         | DENY      | ALLOW      | ALLOW (see 17.3)  | ALLOW       |

- The legacy migration-004 employees/reports seeds for the three head roles and
  the Phase-6 MD reconciliation grants are flipped to explicit
  `is_granted = 0` rows (migration 038 section 8) so the permission UI shows
  "role default: denied" and an authorized override can still re-grant them
  per user.
- **Roster decoupling:** a new `leave:roster` catalog action now gates the
  roster APIs, the `/leave/roster` + `/leave/oversight` page registry entries
  and the sidebar Roster group. Heads keep `leave:manage` (scoped Leave
  Management) but no longer inherit Roster access. `hr_manager` and
  `super_admin` are the only seeded grants.
- **HR Admin vs HR Manager:** there is no distinct `hr_admin` role in this
  system — the "HR Admin" sidebar group (Financial Year, Appraisal Cycles,
  Consent, Holidays) is a module group, and the "HR Admin" persona maps 1:1
  onto `hr_manager` (legacy `admin` normalizes to `super_admin`). Both share
  one operational baseline, differentiated only through the permission
  override system.
- Organizational heads keep their management modules (scoped Attendance
  oversight, Leave Management, Workplans, Strategy) — page access remains
  separate from data scope, which stays OrgScope-enforced server-side.
- Locked by `RolePermissionMatrixTest` (officer/heads/MD denials; HR/super
  admin allows) and the migration runner verification checks.

### 17.3 Phase 11 - HR Admin group + Attendance Dashboard HR-only; Reports re-granted to MD

Business-rule update (migration `039_hr_restricted_modules.sql`): the **HR
Admin** sidebar group (Financial Year, Appraisal Cycles, Consent Management,
Holidays), the **Attendance Dashboard** (`/attendance/dashboard`) and the
**Reports** module are restricted to **hr_manager, managing_director and
super_admin**.

- `attendance:manage` and `financial_year:view/create/edit` are revoked from
  sub_section_head / section_head / dept_head (explicit 0 rows). Heads keep
  `attendance:view` (unit-scoped Attendance Records), `leave:manage` (scoped
  Leave Management), `performance:view` (standalone Appraisal page) and
  `performance:manage` (appraisal create/submit/approve).
- **Appraisal Cycles** is keyed on the dedicated **`performance:cycles`** page
  permission (same pattern as `leave:roster` / `meetings:dashboard`): the HR
  Admin page + `POST/PUT/DELETE /appraisal-cycles` require it, seeded to
  hr_manager / managing_director / super_admin only. `GET /appraisal-cycles`
  stays authenticated-only - the heads' workplan quarter pickers consume the
  minimal read-only payload (`AppraisalCycleController::index`).
- **Reports** was re-granted to the **Managing Director**
  (`reports:view` + `reports:export`) - the MD remains an oversight consumer;
  the three head roles stay denied.
- Active per-user **allow overrides** for the restricted permissions held by
  users outside the three roles are deactivated (rows kept with `active = 0`
  for the audit trail) - overrides outrank role defaults in the
  AuthorizationService hierarchy, so a stale grant would otherwise leak an
  HR-restricted page back to a head.
- Locked by `RolePermissionMatrixTest` (Phase 11 assertions) and
  `backend/database/run_migration_039.php` verification checks.

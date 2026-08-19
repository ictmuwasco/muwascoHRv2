# Application Audit — Pages, Routes & Endpoints (August 2026)

This document records a full audit of the MUWASCO HR application after merging the
outstanding feature branches (PR #16): what was broken, what was missing, what was
fixed, and what is still outstanding.

---

## 1. `Failed to resolve import "./pages/LeaveRoster"`

Reported symptom (Windows/XAMPP dev machine):

```
[vite] Internal server error: Failed to resolve import "./pages/LeaveRoster" from "src/App.jsx".
File: C:/xampp/htdocs/hrdemo/frontend/src/App.jsx:17:24
```

### Diagnosis

The error is **not** a missing file in the repository. Both
`frontend/src/pages/LeaveRoster.jsx` and `frontend/src/pages/LeaveOversight.jsx` exist
on `main` (added by commit `23bc00d`, "Remove test files and cleanup"). The failing
working copy had an `App.jsx` that imported those pages while the `pages/` directory
in that same working copy was stale — i.e. a partially-updated checkout.

### Resolution

1. Sync the working copy with the branch that contains everything:

   ```powershell
   cd C:\xampp\htdocs\hrdemo
   git fetch --all --prune
   git status                # commit or stash local edits first
   git checkout main
   git pull
   cd frontend
   npm install
   npm run dev
   ```

2. If Vite still reports a stale import, clear its cache:

   ```powershell
   Remove-Item -Recurse -Force node_modules\.vite
   ```

3. Verify the two files exist before starting the dev server:

   ```powershell
   git ls-files frontend/src/pages/LeaveRoster.jsx frontend/src/pages/LeaveOversight.jsx
   ```

`App.jsx` on `main` now imports and routes both pages (see section 2), so a clean
checkout resolves the import.

---

## 2. Orphaned pages — components existed but were never routed

These pages were committed but not reachable: no import in `App.jsx`, no sidebar entry.

| Page file | Route added | Sidebar entry |
|---|---|---|
| `pages/LeaveRoster.jsx` | `/leave/roster` | Leave → Leave Roster |
| `pages/LeaveOversight.jsx` | `/leave/oversight` | Leave → Leave Oversight (managers) |
| `pages/MeetingsDashboard.tsx` | `/meetings` | Meetings |
| `pages/CreateMeeting.tsx` | `/meetings/create`, `/meetings/:id/edit` | via Meetings page |
| `pages/MyMeetings.tsx` | `/my-meetings` | via Meetings page |

Also fixed: the Settings → Permissions tab rendered an 18-line placeholder
(`pages/SettingsPermissionsTab.jsx`, "Role-based access management will be available
here"). The real 500-line implementation lives in
`components/settings/SettingsPermissionsTab.jsx`. `App.jsx` now imports the real
component and the placeholder file has been deleted.

### Still not routed (intentionally)

`MeetingsDashboard`/`MyMeetings` link to `/meetings/:id/details`. **No meeting-details
page exists in the repository** — the route is deliberately not registered rather than
pointed at an unrelated component. Clicking "View details" is currently a no-op route.
This needs a `MeetingDetails` page to be written; it was never committed, so there is
nothing to restore from git history.

---

## 3. Backend endpoints — coverage audit

The active router is the repo-root `api.php` (dispatched via `.htaccess`). Every
`api.*()` call in the frontend was extracted and matched against it.

### Endpoints that were missing (now added to `api.php`)

Leave roster — `LeaveRosterController`:

```
GET    /api/leave/roster
GET    /api/leave/roster/stats
GET    /api/leave/roster/distribution
GET    /api/leave/roster/upcoming
GET    /api/leave/roster/departments
GET    /api/leave/roster/matrix
GET    /api/leave/roster/export
GET    /api/leave/roster/employees
GET    /api/leave/roster/financial-years
POST   /api/leave/roster
PUT    /api/leave/roster/{id}
DELETE /api/leave/roster/{id}
```

Meetings — `MeetingController`:

```
GET    /api/meetings
POST   /api/meetings
GET    /api/meetings/eligible-employees
GET    /api/my-meetings
GET    /api/meetings/{id}
PUT    /api/meetings/{id}
POST   /api/meetings/{id}/cancel | /confirm | /decline | /attendance
GET    /api/meetings/{id}/participants
POST   /api/meetings/{id}/participants
DELETE /api/meetings/{id}/participants/{employeeId}
```

Before this change, every Leave Roster, Leave Oversight and Meetings screen would have
failed with a 404 from the router even though the controllers were fully implemented.

### Endpoints still missing (not implemented anywhere)

| Frontend call | Status |
|---|---|
| `GET /api/appraisals/employee/me` (`pages/Appraisal.tsx`) | No `AppraisalController` exists. Page will 404. |
| `GET /api/reports/{type}/export/{format}` | `backend/app/Controllers/ReportsController.php` is an **empty file** (0 bytes). |
| Strategic plan endpoints (`pages/StrategicPlan.jsx`) | No backend controller. |

These are feature gaps, not regressions — nothing exists in git history to restore.
They need to be implemented before the corresponding pages can work.

---

## 4. Missing database tables

`backend/database/migrations/` contained only migrations 004, 007, 008 and 015 — there
is no base schema dump in the repository, and the new controllers referenced tables
that no migration ever created:

| Table | Used by | Migration added |
|---|---|---|
| `leave_roster` | `LeaveRosterController` | `016_leave_roster.sql` |
| `meetings` | `MeetingController`, `Meeting` model | `017_meetings.sql` |
| `meeting_invitations` | `MeetingController`, `MeetingInvitation` model | `017_meetings.sql` |

Column sets were derived from the controllers' SQL and the models' `$fillable` arrays.

Also fixed: `007_financial_years.sql` declared
`INDEX idx_financial_year (financial_year_year_id)` — a column that does not exist, so
the migration aborted on a fresh database. Corrected to `financial_year_id`.

---

## 5. Permissions feature defects (from PR #16 runtime testing)

### 5.1 Saving an override returned HTTP 500

```
Call to undefined method App\Models\UserPagePermission::getByUserAndModuleAction()
```

`PermissionService` was written against a module/action permission model, but
`UserPagePermission` still only implemented the older page-level API, and
`PermissionService::setOverride()` passed 7 arguments to a 5-argument
`setPermission()`.

Fixes in `backend/app/Models/UserPagePermission.php`:

- added `getByUserAndModuleAction(int $userId, string $module, string $action)`;
- `setPermission()` now takes `(userId, module, action, permissionType, grantedBy, updatedBy, notes)`
  and upserts with `ON DUPLICATE KEY UPDATE`, which also reactivates a previously
  deactivated row instead of colliding with the `(user_id, module, action)` unique key;
- `removePermission()` now takes `(userId, module, action)`;
- `getByUserId()` / `getAllWithUserInfo()` select, filter and order by `module`/`action`.

`backend/app/Helpers/AuthorizationService.php` keeps the legacy page-level API working
by mapping a page id to `module = $pageId, action = 'view'` (`UserPagePermission::DEFAULT_ACTION`),
and ignores non-`view` overrides when building the page-access cache.

### 5.2 Effective permissions all rendered as "Unknown"

The UI looks up `effective.find(e => e.module === module && e.action === action)`, but
the backend returned page-shaped records keyed by `page_id`.
`PermissionService::getEffectivePermissions()` now builds records from the permission
catalog as `{ module, action, allowed, source }` with `source` ∈
`Super Admin | Override | Role | Default`.

### 5.3 Meetings module was absent from the permission catalog

`MeetingController` calls `requirePermission('meetings', ...)` for
`view / create / edit / manage / confirm / view_attendance`, but `meetings` did not
exist in `backend/config/permissions.php` nor in `role_permissions`, so every role
except `super_admin` was denied. Added the module to the catalog and seeded role grants
in `018_meetings_role_permissions.sql`.

---

## 5b. Defects found by the PR #17 runtime test (and fixed)

Running the newly-routed screens end-to-end surfaced five further defects in the
previously-unreachable code. All are fixed on this branch.

| Defect | Cause | Fix |
|---|---|---|
| `POST /api/leave/roster` → 500 `Undefined array key "start_date"` — no roster entry could be created | `LeaveRosterController` selected `id, year_name` from `financial_years` but then read `$fy['start_date']` | added `start_date` to the SELECT |
| Roster ✏️ Edit did nothing | `onEdit` navigated to `/leave/roster/:id/edit`, a route with no edit UI behind it | `ScheduleSlideOver` now takes an `editEntry` prop (prefills, hides the employee search, `PUT`s instead of `POST`s); the dead route is removed |
| `GET /api/meetings` → 403 for every role including `super_admin` | `Auth::hasPermission($m, 'view')` goes through `AuthorizationService::hasPageAccess()`, whose page list was a hardcoded array without `meetings` | `getPageNames()`/`getAllPageIds()` now derive from the `config/permissions.php` catalog, so adding a module to the catalog is sufficient |
| Every meetings write → 500 `Undefined constant AuditService::MODULE_MEETINGS`, *after* the DB commit (UI showed failure while the row existed) | `MeetingController` used `MODULE_MEETINGS`, `ACTION_CANCEL_MEETING`, `ACTION_CONFIRM`, `ACTION_DECLINE`, none of which were declared | added the four constants to `AuditService` |
| `POST /api/meetings/{id}/confirm` → 500 `Data truncated for column 'response_status'` | controller and frontend use `accepted`; the new migration's enum said `confirmed` | enum is now `('pending','accepted','declined')`; `attendance_status` also gained `excused`, which the controller accepts |

Also fixed: the `PERMISSION_CHANGE` audit entry recorded `target_name` as `"User #2"`
because the user row was fetched with `SELECT id, role`. It now selects the name fields
and records the user's name (falling back to email).

Verified working in the same run: the real Permissions UI, effective values with real
sources, override grant → persist → audit → remove → re-grant (the upsert/reactivate
path), all nine `/leave/roster/*` GETs, `DELETE /api/leave/roster/{id}`, the whole
Leave Oversight page, `/my-meetings`, and `npm run build`.

---

## 6. Other findings from the runtime test run (PR #16)

| Finding | Assessment |
|---|---|
| Wrong password returns 401 but the login page shows no error message | Real UX bug — error is swallowed in the login page. Not yet fixed. |
| `pages/Audit.tsx` expects `user_name` / `resource` / `details`; API returns different field names | Real field-mapping bug. Not yet fixed. |
| `/api/dashboard/charts/*` 500 — missing `attendance.is_late` | Likely a test-environment schema artifact: the repo has no base schema dump, so the test DB was synthesised. Verify against production schema. |
| Leave approval warning on `employee_id` type | Same cause — the synthesised table used `INT` where the app uses `EMP001`-style string ids. |

Verified working during that run: login, data-protection consent gate, failed-login
audit (`LOGIN_FAILED`), leave list, leave approval (with applicant `target_name` in the
audit trail), dashboard, employees, departments.

---

## 7. Running the application locally

### Windows / XAMPP (the supported dev setup)

```powershell
# 1. Apache + MySQL from the XAMPP control panel, app at C:\xampp\htdocs\hrdemo
# 2. Apply migrations (in filename order)
cd C:\xampp\htdocs\hrdemo\backend\database\migrations
Get-ChildItem *.sql | Sort-Object Name | ForEach-Object {
  & C:\xampp\mysql\bin\mysql.exe -u root muwasco_hr -e "source $($_.FullName)"
}

# 3. Frontend
cd C:\xampp\htdocs\hrdemo\frontend
npm install
npm run dev            # http://localhost:5173
```

The frontend talks to `http://localhost/hrdemo/api` in dev (see
`frontend/src/utils/api.js`); the `/hrdemo` prefix must be preserved or PHP sessions
and the router's endpoint matching break.

### Troubleshooting checklist

| Symptom | Cause / fix |
|---|---|
| `Failed to resolve import "./pages/X"` | Stale checkout — see section 1. |
| 404 on `/api/...` | Endpoint not registered in root `api.php` — see section 3. |
| 403 "You do not have permission to X Y" | Module/action missing from `role_permissions` — see section 5.3. |
| 500 on roster/meetings endpoints | Migrations 016–018 not applied. |
| Blank Permissions tab | Old build importing `pages/SettingsPermissionsTab` (deleted). |

---

## 8. Outstanding work

1. Write a `MeetingDetails` page and register `/meetings/:id/details`.
1b. Re-test meetings create/confirm/decline/cancel end-to-end — the fixes above are
    code-verified but the previous run could not get past the 403/500s.
2. Implement `AppraisalController` + `/api/appraisals/*`, or hide the Appraisal page.
3. Implement `ReportsController` (currently an empty file) + `/api/reports/*`.
4. Implement strategic-plan endpoints, or hide the page.
5. Surface the 401 message on the login page.
6. Align `pages/Audit.tsx` field names with the audit API payload.
7. Commit a base schema dump (`schema.sql`) so a database can be built from scratch —
   the absence of one is why several failures could only be classified as "probably
   environmental".

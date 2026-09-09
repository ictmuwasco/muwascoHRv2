# Authorization & RBAC

Phase 7 (2026-09) companion to `docs/AUTHORIZATION.md` (root tree). This file
documents the mandate's authorization controls as implemented in this custom
PHP framework, plus the Phase 7 verification status.

## 1. Policy: never trust the frontend

React hides buttons and pages for UX. **All authorization is enforced
server-side**:

1. Every route carries server-defined permission metadata in `api.php`
   (6th argument `'module:action'`), never derived from the request.
2. `AuthorizationMiddleware::enforce($permission)` runs in the router after
   the authentication gate and before controller dispatch.
3. Permission-less routes are restricted to the reviewed allowlist
   (`backend/config/authz_allowlist.php`), which requires an explicit
   justification per entry.
4. Per-record ownership/scope lives in services/controllers (IDOR layer).

## 2. Role & permission model

- Users carry one role (`users.role` ENUM) — the RBAC baseline.
- `AuthorizationService` (`Helpers/AuthorizationService.php`) evaluates
  **role-based permissions + per-user overrides** with **deny-over-grant**
  semantics: rows in `role_permissions` and `user_permissions` are grants;
  any failure denies.
- The permission catalog `backend/config/permissions.php` is the single
  source of truth for valid `module:action` pairs and roles.
- Privilege escalation guard: only an actor whose session role is
  `super_admin`/`admin` may assign the `super_admin` role
  (`UserService::assertAuthorizedRoleChange`). The acting identity always
  comes from the session/DB, never the payload.

## 3. Route permission map

`backend/tests/Unit/Authorization/RoutePermissionMapTest.php` enforces:

1. Every `$router->add(...)` carries a valid catalog permission **or** a
   reviewed allowlist entry;
2. Every catalog-mapped permission exists;
3. Every allowlist entry matches a registered route;
4. **Phase 7:** every route in `backend/config/rate_limits.php` declares a
   server-defined throttle; throttle format is `positive-int:positive-int`.

## 4. Module gate examples

| Area | Route permission patterns |
|---|---|
| Employees | `employees:view/create/edit/delete` |
| Leave | `leave:view/apply/manage/approve/reject/invalidate` |
| Attendance | `attendance:view` (self-service clock is allowlisted + ownership-enforced) |
| Meetings | `meetings:view/create/edit/delete/invite/confirm/manage` |
| Reports | `reports:view/export` |
| Users | `users:view/create/edit/delete` |
| Permissions | `permission_overrides:view/manage` |
| Audit | `audit:view/export` |
| System errors | `system_errors:view/manage` |

## 5. Record-level scope checks (IDOR)

Verified ownership/scope guards (pinned by
`backend/tests/Unit/Security/IdorOwnershipEnforcementTest.php`):

| Endpoint | Guard |
|---|---|
| `POST /notifications/{id}/read` | SQL scoped `AND user_id = ?` |
| `PUT /leave/{id}/cancel` | applicant's employee id must equal application's employee_id |
| `GET /profile/documents/{id}` | owner employee OR `employees:view` |
| `GET /leave/{id}/documents/{documentId}` | `getDocument($applicationId, $userId)` |
| `POST /complaints` | own complaints to employees; `complaints:view` holders see all |
| Meeting minutes view | `MeetingMinutesService` restricts draft/publish visibility |
| Attendance by employee | `attendance:view` + org-scope/DashboardService |

## 6. Mass assignment (Phase 7)

`UserService` and `EmployeeService` now filter client payloads through
explicit allowlists before the repositories build column lists.

| Service call | Allowlist |
|---|---|
| `UserService::createUser` | `USER_WRITABLE_FIELDS` + create-only `is_active` |
| `UserService::updateUser` | `USER_WRITABLE_FIELDS` (no `is_active`; status via toggle-status) |
| `EmployeeService::createEmployee/updateEmployee/updateEmployeeProfile` | `EMPLOYEE_WRITABLE_FIELDS` |

Blocked writes: `session_token`, `login_identifier`, `last_activity`,
`profile_token`, `profile_image_url` (endpoint-owned), `salary`,
`id`, timestamps, `is_active` on update — all covered by
`backend/tests/Unit/Services/MassAssignmentTest.php`.

## 7. Verification matrix

| Actor | Operation | Result |
|---|---|---|
| Unauthenticated | any protected route | 401 |
| Employee | own resource | allowed |
| Employee | another employee's resource | denied (service scope) |
| HR | employee-level management | allowed via `employees:*` |
| Admin/SuperAdmin | restricted administration | allowed via catalog |
| Manager | admin-only op | 403 |
| Non-super-admin | assign `super_admin` | 403/InvalidArgumentException |
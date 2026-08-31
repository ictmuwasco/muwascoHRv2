# Employees & Organisation Structure

> Status: **Implemented.** Audited against `api.php`, `EmployeeController` / `EmployeeService` / `EmployeeRepository`, the org-structure controllers, migrations and employee test suites.

## Overview

Employee master data, the org hierarchy (departments → sections → subsections), and user accounts with hybrid role/permission assignment.

| | |
|---|---|
| Controllers | `EmployeeController`, `DepartmentController`, `SectionController`, `SubsectionController`, `UserController` |
| Services | `EmployeeService`, `DepartmentService`, `UserService`, `PermissionService` |
| Repositories | `EmployeeRepository`, `DepartmentRepository`, `SectionRepository`, `UserRepository` |
| Migrations | 005 (dependants column; **also** a second `005_sections_and_subsections` — see `database.md`), 009 (contract dates), 019 (profile picture), 003/004/014/015 (permissions) |
| Tests | `Integration/Api/EmployeeApiTest`, `Unit/Controllers/EmployeeControllerTest`, `Unit/Repositories/EmployeeRepositoryTest`, `Unit/Services/EmployeeServiceTest` |

## Organisation hierarchy

```text
Department  ──< Section  ──< Subsection
     │
     └──< Employees (each assigned to a department / section / subsection)
```

- Full CRUD for departments, sections and subsections (`/departments`, `/sections`, `/subsections`); section/subsection listings can be filtered by parent.
- Historical note: an earlier round of section/subsection UI + API fixes is documented in `DEPARTMENTS_PAGE_FIXES.md` (archived under [archive/](archive/)); its schema reference is superseded by `database.md`.

## Employee records

- CRUD + search: `GET /employees`, `GET /employees/search`, `GET /employees/reference` (reference data for forms).
- Profile fields include dependants (migration 005), contract dates (migration 009) and a profile picture (migration 019).
- Employee identities flow into every other module (leave, attendance, meetings, payroll, complaints) — employee **numbers** are string identifiers and several UIs display them via the shared `humanize()`/`displayNameOf()` helpers; backend list endpoints resolve names server-side (the pattern added in `MeetingRepository::findByIdWithInvitations`).

## Users, roles & permissions

- `users` CRUD + `PUT /users/{id}/toggle-status` + `POST /users/{id}/change-password` (admin-driven; self-service password change is `POST /auth/change-password`).
- Access control is the **hybrid permission model**: role permissions (`role_permissions`, migrations 004) plus per-user page overrides (`user_page_permissions`, migrations 003/014/015). Resolution logic lives in `PermissionService` / `RBAC` / `AuthorizationService` helpers — see `SECURITY_AUDIT.md` and `AUTHENTICATION_MIDDLEWARE_ANALYSIS.md`.

## Employee ↔ user relationship

Every employee may have a linked user account (login identity). Profile sync between the two records is the subject of the small archived note `EMPLOYEE_PROFILE_SYNC.md`; treat that file as history, not as a current-state reference.

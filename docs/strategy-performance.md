# Strategy & Performance Module

> Status: **Implemented.** Audited against `api.php`, the `HR\*` controllers (`StrategicPlanController`, `WorkplanController`, `KPIController`, `PerformanceContractController`, `SectionalObjectiveController`, `AppraisalController`, `AppraisalCycleController`) and the `frontend/src/pages/strategic-plan` + `pages/strategy` page trees (18 pages).

## Overview

The corporate performance chain, from strategic plan down to individual appraisal:

```text
Strategic Plan ──< Goals / Targets
       │
       └──< Workplans  (cascaded to sections; tracked; audited via workplan_logs)
                 │
Performance Contract ──< KPIs          Appraisal Cycle (tied to financial year)
                 │                              │
                 └──────────────< Appraisals ───┘  (submit → approve)
```

- **Strategic plans** — CRUD plus nested goal and target creation (`POST /strategic-plans/{id}/goals|targets`).
- **Performance contracts** — per-employee/period contracts (`/performance-contracts` CRUD) carrying **KPIs** (`/contracts/{id}/kpis`, `/kpis` list/update/delete).
- **Workplans** — the operational layer: list, create, **bulk create**, an **integrated view**, **summary**, **export**, **section sources**, per-item **cascade** (migration 030), **traceability**, **progress history** (logs table from migration 029), **progress updates** (tracking fields from migration 028) and **dependencies**.
- **Appraisals** — appraisal records with pending queue, per-employee history, submit and approve transitions (`/appraisals*`); cycles are managed separately (`/appraisal-cycles`, migration 031 ties cycles to financial years).
- **Dashboards & reports** — `/dashboard/strategic-performance` and `/reports/strategic-performance` aggregate the whole chain.

## Permissions

Migration `027_strategy_performance_permissions` seeds the strategy/workplan permission family into the hybrid RBAC (role permissions + per-user overrides). Every endpoint is permission-gated server-side; the 18 frontend pages only mirror server state.

## Endpoint families

| Family | Endpoints (summary) |
|---|---|
| Strategic plans | CRUD + `POST /{id}/goals` + `POST /{id}/targets` |
| Workplans | list, store, **bulk**, integrated-view, export, summary, section-sources, `{id}/cascade`, `{id}/traceability`, `{id}/progress-history`, `{id}/progress` (PUT), `{id}/dependencies`, show/update/destroy |
| Performance contracts | CRUD (`/performance-contracts`) |
| KPIs | nested under contracts (`/contracts/{id}/kpis`) + global list/update/destroy |
| Appraisal cycles | CRUD (`/appraisal-cycles`) |
| Appraisals | CRUD + `pending`, `employee/{id}`, `{id}/submit`, `{id}/approve` |
| Reports | `/reports/appraisal`, `/reports/strategic-performance` |

Full parameter-level detail: [API_REFERENCE.md](API_REFERENCE.md).

## Workplan tracking & cascade (migrations 028–030b, 113)

- 028 adds tracking fields (progress/status bookkeeping) to workplans.
- 029 adds a `workplan_logs` table — every progress update is traceable via `GET /workplans/{id}/progress-history`.
- 030 adds cascade fields + 030b fixes auto-increment behaviour; `POST /workplans/{id}/cascade` pushes objectives down the hierarchy, with `traceability` showing lineage both ways.
- 113 fills `workplan_objectives.level` only where it is `NULL` or `''`, using the existing subsection/section/contract assignments. Existing non-empty levels are not changed. See [database.md](database.md#workplan-level-backfill-and-tier-scope) for the exact mapping and rollout precautions.

## Workplan tier visibility (implemented)

The list and summary endpoints share `WorkplanService::viewScope()`. The tier filters are a strict one-level cascade: each normal unit head sees their own level plus the next level below it, not deeper work or neighboring units.

```text
Department head
  department activities
  └── section activities
      (no subsection activities)

Section head
  this section's activities
  └── this section's subsection activities
      (department contract must also belong to the head's department)

Subsection head
  this subsection's activities only
```

The section head may also request `view=subsection`; that view returns subsection activities whose subsection belongs to the head's section, with the same department guard.

| View / caller | Implemented row scope |
|---|---|
| `department`, department head (including `hr_manager` on the department view) | `c.department_id = caller department` and effective level is `department` or `section` |
| `section`, section head | `w.section_id = caller section`, `c.department_id = caller department`, and effective level is `section` or `subsection` |
| `subsection`, subsection head | `w.subsection_id = caller subsection`, `c.department_id = caller department`, and effective level is `subsection` |
| `subsection`, section head | Subsection ID is selected from `subsections` for caller's section; contract department and effective level are also checked as above |

For these filters, effective level is evaluated in SQL as:

```sql
COALESCE(
  NULLIF(w.level, ''),
  CASE
    WHEN w.subsection_id IS NOT NULL THEN 'subsection'
    WHEN w.section_id IS NOT NULL THEN 'section'
    ELSE 'department'
  END
)
```

Consequently, a legacy row with a missing level is classified from its unit assignment at read time. A row with neither `section_id` nor `subsection_id` falls back to `department`; it still must pass the applicable `c.department_id` condition to appear in a unit tier. Queries use prepared placeholders for the department and unit IDs.

### Boundaries and operational notes

- The department guard on section and subsection views is deliberate. Do not remove it: it prevents rows with a section/subsection ID inconsistent with the caller's department from leaking into that view.
- The department view does not filter by `created_by`. Section and subsection rows are included based on unit and effective level, not on who created them.
- In the KPI assignment form, a department head's activity list includes non-deleted department- and section-level activities in their department. Once employees are selected, the form narrows activities to those whose department/section/subsection assignment matches at least one selected employee. The API validates the same unit match when saving.
- For a department head assigning to section heads, the selected employee's section is required: department-wide activities are not offered as that section head's KPI objectives. A department-wide objective must first be cascaded/assigned to the relevant section. Section-specific objectives for another section and subsection objectives are rejected for a section-head assignment. If no matching section activity exists, the form explains that section work must be created or cascaded first.
- `GET /workplans` still supports the optional `created_by_self=1` filter. The current tier hook does not send it; adding it would intentionally hide rows created by other users, including cascaded rows with `created_by IS NULL`.
- Responsible-officer placement is validated by the existing cascade/write path. The tier queries do not add a responsible-officer join.
- `super_admin` and `managing_director` retain the broad `viewScope()` path. `hr_manager` retains its broad `md` view and its department-scoped `department` view. This section documents row scoping, not endpoint permission grants.
- List results are filtered to `soft_deleted = 0`. Optional list filters such as status, parent, level, search, contract and financial year further narrow those results.
- `financial_year_id` narrows list rows and summary aggregates when supplied. Without it, list requests are not assigned a default year. Summary aggregates are defaulted to the latest active year only for `view=md`; department/section/subsection summaries without an FY parameter aggregate all financial years. In that case `active_financial_year` reports the latest active year as metadata, not the aggregate's filter. The selected year is returned as `active_financial_year` when an FY parameter is supplied.
- The summary period behavior does not update or move performance contracts between financial years. Any contract/FY data correction is a separate database operation.
- The section-sources endpoint reuses `viewScope()` and joins performance contracts for its department guard; it then filters to not-self-created source rows and the appropriate section/subsection assignment.

### Verification coverage and limits

`backend/tests/Unit/Services/WorkplanServiceScopeTest.php` contains five focused tests for the generated department, section, and subsection predicates, parameter order, HR-manager department scope, and section-head subsection descendants. These tests inspect generated SQL and parameters without a database connection. They do **not** prove production row counts or exercise the predicates against the production dump.

`backend/tests/Unit/Controllers/SectionalObjectiveControllerAssignmentTest.php` covers the department/section/subsection matching rule used when validating KPI activity assignments, including rejection of department-wide or subsection activities for a section-head assignment. It does not execute the activity-list SQL or validate saved assignments against production data.

The production examples and expected row counts must be verified against the intended database after the SQL backfill and any separate financial-year data correction have been applied. Do not describe those counts as test results until that database query has actually been run.

## Frontend

The `pages/strategic-plan` and `pages/strategy` directories hold the 18 strategy/workplan pages (plan editor, goals/targets, contracts, KPI editors, workplan grids, integrated view, progress tracking). Navigation is defined in `frontend/src/components/Sidebar.jsx`; routes in `frontend/src/App.jsx`.

## Known gaps

- The focused Workplan scope and KPI unit-matching tests do not cover controller SQL execution, a database-backed fixture, production data counts, or cascade/write validation end to end.
- Workplan cascade and bulk operations are powerful but one-way; there is no un-cascade/rollback endpoint.

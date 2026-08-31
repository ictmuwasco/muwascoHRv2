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

## Workplan tracking & cascade (migrations 028–030b)

- 028 adds tracking fields (progress/status bookkeeping) to workplans.
- 029 adds a `workplan_logs` table — every progress update is traceable via `GET /workplans/{id}/progress-history`.
- 030 adds cascade fields + 030b fixes auto-increment behaviour; `POST /workplans/{id}/cascade` pushes objectives down the hierarchy, with `traceability` showing lineage both ways.

## Frontend

The `pages/strategic-plan` and `pages/strategy` directories hold the 18 strategy/workplan pages (plan editor, goals/targets, contracts, KPI editors, workplan grids, integrated view, progress tracking). Navigation is defined in `frontend/src/components/Sidebar.jsx`; routes in `frontend/src/App.jsx`.

## Known gaps

- No automated tests for the strategy/performance controllers or services — see `testing.md`.
- Workplan cascade and bulk operations are powerful but one-way; there is no un-cascade/rollback endpoint.

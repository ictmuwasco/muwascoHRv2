# Reports Module

> Status: **Implemented.** Audited against `api.php`, `backend/app/Controllers/Reports/*`, the report services, report-index migrations and the report test suites.

## Overview

Two dedicated report engines (Attendance and Leave) plus a generic export endpoint. All report endpoints are **read-only**, parameterised (department / employee / date range) and paginated; each module exposes its own `export` endpoint and the generic `GET /reports/{type}/export/{format}` route handles file downloads.

| | |
|---|---|
| Controllers | `Reports\AttendanceReportController`, `Reports\LeaveReportController`, `ReportController` (generic export) |
| Services | `LeaveReportQueryService`, `LeaveReportAnalyticsService`, `LeaveReportStatisticsService`, `LeaveReportExportService` (leave); the attendance query service underpins `AttendanceReportController` |
| Index support | migration 032 (leave report indexes), 033 (attendance report indexes) |
| Tests | `backend/tests/LeaveReportQueryServiceTest.php`, `backend/tests/AttendanceReportQueryServiceTest.php`; frontend `AttendanceReport.test.jsx`, `LeaveReports.test.jsx`, `Reports.test.jsx` |
| Docs of record | also see [attendance.md](attendance.md) and [leave.md](leave.md) |

## Attendance reports (12 endpoints)

`options` (filter sources: departments, employees, dates) · `summary` · `trends` · `by-status` · `by-department` · `late-arrivals` · `working-hours` · `insights` · `compliance` · `employees` · `records` (drill-down) · `export`

## Leave reports

`summary` · `trends` · `by-type` · `by-department` · `by-status` · `duration` · `insights` · `records` · `export`

## Design conventions

- **Options pattern** — every report UI first calls its `options` endpoint to populate filters; queries always go through the query service (single source of filtering/aggregation logic).
- **Query services are unit-tested** — filtering, aggregation and pagination logic lives in `*QueryService` classes, which is what the backend tests cover.
- **Exports** reuse the same query layer (`LeaveReportExportService` / the generic `{format}` route), so a report page and its export can never disagree.
- Report-facing tables carry dedicated indexes (032 / 033) so reports don't degrade the transactional tables.

## Access

Report endpoints require authentication; department-scoped views respect the same RBAC scoping as the underlying modules. See `SECURITY_AUDIT.md` for the authorisation model.

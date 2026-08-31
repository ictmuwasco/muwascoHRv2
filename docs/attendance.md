# Attendance Module

> Status: **Implemented.** Audited against `api.php`, `backend/app/Controllers/AttendanceController.php`, `AttendanceReportController.php`, the `Attendance*` services and repositories, migrations 006/020/021/022/026/033 and the attendance test suites.

## Overview

Employees clock in and out (with geo-location and IP capture); HR monitors organisation-wide presence in real time and through the reporting module. This repository's active branch also adds **push/SMS clock-in reminders** (see [Notifications](NOTIFICATIONS.md)).

| | |
|---|---|
| Controller | `App\Controllers\AttendanceController`, `App\Controllers\Reports\AttendanceReportController` |
| Services | `AttendanceService`, `AttendanceDashboardService`, `AttendanceReminderEligibilityService` (+ `EligibilityResult`), `CalendarContextService` |
| Repositories | `AttendanceRepository` |
| Helpers | `AppTime` (timezone consistency), `GeoLocation` (distance / geofence checks) |
| Tables | `attendance` (see migrations 006, 020–022, 026; indexes 006 + 033) |
| Tests | `tests/Unit/Services/AttendanceStatusResolutionTest.php`, `tests/AttendanceReportQueryServiceTest.php`; frontend `AttendanceDashboard.test.jsx`, `AttendanceReport.test.jsx` |
| Frontend | attendance dashboard(s), **My Attendance**, HR employee history, Reports → Attendance |

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/attendance/today` | Current user's today status (clocked in? open session?) |
| POST | `/attendance/clock-in` | Clock in — captures coordinates + IP, geofence-checked |
| POST | `/attendance/clock-out` | Close the open session, compute worked time |
| POST | `/attendance/auto-clockout` | Cron endpoint that closes forgotten open sessions (scheduled in [DEPLOYMENT.md](DEPLOYMENT.md)) |
| GET | `/attendance/my-records` | Current user's history |
| GET | `/attendance/dashboard` | Personal attendance dashboard |
| GET | `/attendance/hr-dashboard` | HR organisation-wide view |
| GET | `/attendance/hr-employee-history` | Per-employee history for HR |
| GET | `/attendance/employee/{id}` | Attendance for one employee |
| GET/POST | `/attendance` | Admin list / manual create |
| GET/PUT/DELETE | `/attendance/{id}` | Admin record detail / correction / removal |

Reporting endpoints live under `/reports/attendance/*` (options, summary, trends, by-status, by-department, late-arrivals, working-hours, insights, compliance, employees, records, export) — see [reports.md](reports.md).

## Clock-in / clock-out behaviour

- `clockIn` refuses a second open session; `clockOut` closes the current one. Both record **where** (lat/lng — nullable per migration 021) and **from which IP** (migration 022).
- `GeoLocation` validates the clock-in point against the configured office location (geofence distance check).
- Status (e.g. present vs late) is resolved in `AttendanceService` from clock-in time against the configured grace window — the rules live in one place and are pinned by `AttendanceStatusResolutionTest`.
- All day boundaries use `AppTime` so server timezone never skews "today".
- Forgotten open sessions are closed by the scheduled `auto-clockout` job.
- Migration 026 added audit fields (who created/updated a record) so manual corrections are attributable.

## HR dashboards & reminders

- `hr-dashboard` aggregates live presence for the HR view; `AttendanceDashboardService` assembles the widgets.
- `AttendanceReminderEligibilityService` (with its `EligibilityResult` value object) decides who should receive a clock-in reminder — e.g. not yet clocked in, working day, not on approved leave — before notifications fan out through the Notifications module (push / SMS; see [NOTIFICATIONS.md](NOTIFICATIONS.md)). `CalendarContextService` supplies calendar context (holidays, weekends) to that decision.

## Data & performance

- Migration 006 (`attendance_optimization`) and 033 (`attendance_report_indexes`) keep list and report queries indexed; 020 introduced the dedicated `attendance_date` column so queries filter on the business date rather than the row timestamp.
- Report-facing endpoints are read-only and Paginated; see [reports.md](reports.md).

## Known gaps

- No shift/roster or overtime management.
- No biometric/hardware device integration.
- Manual corrections by HR rely on the audit fields (026) — there is no separate amendment workflow.

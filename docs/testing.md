# Development & Testing Guide

> Status: **Implemented test suites documented.** Frontend tests run under **vitest**; backend tests are PHPUnit-style classes bootstrapped by `backend/tests/bootstrap.php` with a shared `TestCase.php`.

## Test suites

### Backend (`backend/tests/`)

| Location | Files | Covers |
|---|---|---|
| tests root | `AttendanceReportQueryServiceTest`, `LeaveValidationTest`, `LeaveDateCalculationTest`, `LeaveReportQueryServiceTest`, `LeaveRoleBasedAccessTest` | Attendance & leave query services, date math, validation, RBAC |
| `Unit/Services/` | `AttendanceStatusResolutionTest`, `AuthServiceTest`, `EmployeeServiceTest` | Attendance status engine, auth, employee logic |
| `Unit/Controllers/` | `EmployeeControllerTest` | Controller behaviour |
| `Unit/Repositories/` | `EmployeeRepositoryTest` | Repository queries |
| `Unit/Services/ErrorTracking/` | `ErrorFingerprintTest`, `ErrorTrackerFailSafeTest`, `RequestIdServiceTest`, `RequestSanitizerTest`, `SeverityClassifierTest` | Observability stack |
| `Integration/Api/` | `EmployeeApiTest` | End-to-end employee API |

Shared infra: `tests/bootstrap.php`, `tests/TestCase.php`. Current status: **27/27 passing** at last full run.

### Frontend (`frontend/src/__tests__/`)

| File | Covers |
|---|---|
| `components/AttendanceDashboard.test.jsx` | Attendance dashboard |
| `components/AttendanceReport.test.jsx` | Attendance reports |
| `components/LeaveReports.test.jsx` | Leave reports |
| `components/Reports.test.jsx` | Generic reports |
| `components/Button.test.tsx` | Shared UI |
| `components/ErrorBoundary.test.jsx` | Error boundary |
| `utils/errorReporting.test.ts` | Client error reporting |
| `setup.ts` | Vitest setup |

Current status: **42/42 passing**. Run with the project's script (see `package.json` → `test`); build check via `vite build`.


## Commands

| What | Command |
|---|---|
| Backend tests (all) | `php backend/run_tests.php` |
| Frontend tests | `npm run test` from `frontend/` (script `vitest run`) |
| Frontend watch mode | `npm run test:watch` |
| Lint (frontend) | `npm run lint` (ESLint, zero-warning policy) |
| Production build check | `npm run build` (Vite) |
| Backend smoke checks | `php backend/smoke_observability.php`, `backend/test_operations.php` (operational smoke scripts, not unit suites) |

The backend has **no Composer autoload** (`backend/composer.json` does not exist); the suite bootstraps itself via `tests/bootstrap.php` and the shared `TestCase.php`.

## Coverage — what is and isn't tested

- **Covered:** leave validation / date calculation / role-based access / report queries; attendance status resolution + attendance report queries; employee controller/repository/service/API; auth service; error-tracking services; frontend report, attendance and error-reporting components.
- **Not covered (tracked gaps):** meetings & meeting-minutes services/controllers, the notifications pipeline, strategy/performance controllers and services, payroll / complaints / consent, and frontend pages outside the reports/attendance components.

## Adding tests

- **Backend:** add `*Test.php` under `backend/tests/` (group under `Unit/…` or `Integration/…` to match the existing layout), extending `TestCase.php`; the bootstrap wires up the test environment.
- **Frontend:** add `*.test.jsx` / `*.test.ts` under `frontend/src/__tests__/` (components or utils), matching the existing file placement; `setup.ts` is loaded by Vitest automatically.


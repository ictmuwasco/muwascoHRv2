# API Reference

> Generated from the route registrations in `api.php` — **270 routes** in total. Handler notation `Controller::action`; controllers live under `backend/app/Controllers/**`.

## Conventions

- **Base URL** — the API is served by `api.php`; in development the Vite proxy maps `/api/*` to it (see `API_DOCUMENTATION.md` for the proxy and auth-flow details).
- **Authentication** — every route requires the JWT bearer header except `POST /auth/login`:

```http
Authorization: Bearer <token>
```

- **Responses** — JSON everywhere: `{ "success": bool, "message": string|null, "data": ... }`.
- **Errors** — typed HTTP codes: 401 unauthenticated, 403 forbidden, 404 unknown id, 409 conflict, 422 validation. Catalogue: `API_DOCUMENTATION.md`.
- **Identity** — "my" endpoints (`/my-meetings`, `/attendance/my-records`, `/leave` own-scope) always resolve the actor from the session, never from the request body.

## Route catalog

Grouped alphabetically by first path segment.


### /admin

| Method | Path | Handler |
|---|---|---|
| POST | /admin/financial-year/add | FinancialYearController::store |
| POST | /admin/financial-year/allocate | FinancialYearController::allocateLeave |
| GET | /admin/financial-years | FinancialYearController::index |
| GET | /admin/financial-years/employees | FinancialYearController::employees |
| GET | /admin/financial-years/leave-types | FinancialYearController::leaveTypes |
| GET | /admin/financial-years/status | FinancialYearController::status |
| GET | /admin/notifications/audit/{employeeId} | AdminNotificationController::audit |
| GET | /admin/notifications/stats | AdminNotificationController::stats |
| POST | /admin/notifications/test-send | NotificationTestController::send |

### /appraisal-cycles

| Method | Path | Handler |
|---|---|---|
| POST | /appraisal-cycles | AppraisalCycleController::store |
| GET | /appraisal-cycles | AppraisalCycleController::index |
| PUT | /appraisal-cycles/{id} | AppraisalCycleController::update |
| DELETE | /appraisal-cycles/{id} | AppraisalCycleController::destroy |

### /appraisals

| Method | Path | Handler |
|---|---|---|
| GET | /appraisals | AppraisalController::index |
| POST | /appraisals | AppraisalController::store |
| DELETE | /appraisals/{id} | AppraisalController::destroy |
| PUT | /appraisals/{id} | AppraisalController::update |
| GET | /appraisals/{id} | AppraisalController::show |
| PUT | /appraisals/{id}/approve | AppraisalController::approve |
| PUT | /appraisals/{id}/submit | AppraisalController::submit |
| GET | /appraisals/employee/{id} | AppraisalController::byEmployee |
| GET | /appraisals/pending | AppraisalController::pending |

### /attendance

| Method | Path | Handler |
|---|---|---|
| POST | /attendance | AttendanceController::store |
| GET | /attendance | AttendanceController::index |
| DELETE | /attendance/{id} | AttendanceController::destroy |
| PUT | /attendance/{id} | AttendanceController::update |
| GET | /attendance/{id} | AttendanceController::show |
| POST | /attendance/auto-clockout | AttendanceController::autoClockOut |
| POST | /attendance/clock-in | AttendanceController::clockIn |
| POST | /attendance/clock-out | AttendanceController::clockOut |
| GET | /attendance/dashboard | AttendanceController::dashboard |
| GET | /attendance/employee/{id} | AttendanceController::byEmployee |
| GET | /attendance/hr-dashboard | AttendanceController::hrDashboard |
| GET | /attendance/hr-employee-history | AttendanceController::hrEmployeeHistory |
| GET | /attendance/my-records | AttendanceController::myRecords |
| GET | /attendance/today | AttendanceController::today |

### /audit

| Method | Path | Handler |
|---|---|---|
| GET | /audit | AuditLogController::index |
| GET | /audit/{id} | AuditLogController::show |
| GET | /audit/export | AuditLogController::export |
| GET | /audit/filters | AuditLogController::filters |
| GET | /audit/statistics | AuditLogController::statistics |

### /audit-logs

| Method | Path | Handler |
|---|---|---|
| GET | /audit-logs | AuditLogController::index |

### /auth

| Method | Path | Handler |
|---|---|---|
| POST | /auth/change-password | AuthController::changePassword |
| POST | /auth/login | AuthController::login |
| POST | /auth/logout | AuthController::logout |
| POST | /auth/refresh | AuthController::refresh |
| GET | /auth/user | AuthController::me |

### /complaints

| Method | Path | Handler |
|---|---|---|
| GET | /complaints | ComplaintController::index |
| POST | /complaints | ComplaintController::store |
| POST | /complaints | ComplaintController::store |
| GET | /complaints | ComplaintController::index |
| PUT | /complaints/{id} | ComplaintController::update |
| PUT | /complaints/{id} | ComplaintController::update |

### /consent

| Method | Path | Handler |
|---|---|---|
| POST | /consent | ConsentController::storeConsent |
| GET | /consent/dashboard | ConsentController::dashboard |
| GET | /consent/employees | ConsentController::employees |
| GET | /consent/status | ConsentController::status |
| POST | /consent/verify-employee | ConsentController::verifyEmployeeId |

### /consents

| Method | Path | Handler |
|---|---|---|
| GET | /consents | ConsentController::index |
| PUT | /consents/{id} | ConsentController::update |

### /contracts

| Method | Path | Handler |
|---|---|---|
| GET | /contracts/{id}/kpis | KPIController::index |
| POST | /contracts/{id}/kpis | KPIController::store |

### /dashboard

| Method | Path | Handler |
|---|---|---|
| GET | /dashboard | DashboardController::index |
| GET | /dashboard/charts/attendance | DashboardController::chartsAttendance |
| GET | /dashboard/charts/departments | DashboardController::chartsDepartments |
| GET | /dashboard/charts/leave | DashboardController::chartsLeave |
| GET | /dashboard/stats | DashboardController::stats |
| GET | /dashboard/strategic-performance | DashboardController::strategicPerformance |

### /departments

| Method | Path | Handler |
|---|---|---|
| GET | /departments | DepartmentController::index |
| POST | /departments | DepartmentController::store |
| GET | /departments/{id} | DepartmentController::show |
| PUT | /departments/{id} | DepartmentController::update |
| DELETE | /departments/{id} | DepartmentController::destroy |

### /employees

| Method | Path | Handler |
|---|---|---|
| POST | /employees | EmployeeController::store |
| GET | /employees | EmployeeController::index |
| GET | /employees/{id} | EmployeeController::show |
| DELETE | /employees/{id} | EmployeeController::destroy |
| PUT | /employees/{id} | EmployeeController::update |
| GET | /employees/{id}/profile-image | EmployeeController::employeeProfileImage |
| POST | /employees/{id}/profile-image | EmployeeController::uploadEmployeeProfileImage |
| GET | /employees/reference | EmployeeController::reference |
| GET | /employees/search | EmployeeController::search |

### /goals

| Method | Path | Handler |
|---|---|---|
| DELETE | /goals/{id} | StrategicPlanController::destroyGoal |
| PUT | /goals/{id} | StrategicPlanController::updateGoal |

### /holidays

| Method | Path | Handler |
|---|---|---|
| GET | /holidays | HolidayController::index |
| POST | /holidays | HolidayController::store |
| PUT | /holidays/{id} | HolidayController::update |
| DELETE | /holidays/{id} | HolidayController::destroy |
| GET | /holidays/{id} | HolidayController::show |
| GET | /holidays/upcoming | HolidayController::upcoming |

### /kpis

| Method | Path | Handler |
|---|---|---|
| GET | /kpis | KPIController::list |
| PUT | /kpis/{id} | KPIController::update |
| DELETE | /kpis/{id} | KPIController::destroy |

### /leave

| Method | Path | Handler |
|---|---|---|
| GET | /leave | LeaveController::index |
| PUT | /leave/{id}/approve | LeaveController::approve |
| PUT | /leave/{id}/cancel | LeaveController::cancel |
| GET | /leave/{id}/documents | LeaveController::listDocuments |
| GET | /leave/{id}/documents/{documentId} | LeaveController::viewDocument |
| PUT | /leave/{id}/invalidate | LeaveController::invalidate |
| PUT | /leave/{id}/reject | LeaveController::reject |
| POST | /leave/apply | LeaveController::apply |
| GET | /leave/calculate | LeaveController::calculate |
| GET | /leave/delegates | LeaveController::delegates |
| GET | /leave/eligible-delegates | LeaveController::eligibleDelegates |
| GET | /leave/eligible-employees | LeaveController::eligibleEmployees |
| GET | /leave/manage | LeaveController::manage |
| GET | /leave/profile/{id} | LeaveController::profile |
| GET | /leave/profile/{id}/applications | LeaveController::profileApplications |
| GET | /leave/profile/{id}/balances | LeaveController::profileBalances |
| GET | /leave/profile/{id}/export | LeaveController::profileExport |
| GET | /leave/profile/{id}/summary | LeaveController::profileSummary |
| GET | /leave/profile/{id}/timeline | LeaveController::profileTimeline |
| GET | /leave/profile/employees | LeaveController::profileEmployees |
| POST | /leave/roster | LeaveRosterController::store |
| GET | /leave/roster | LeaveRosterController::index |
| PUT | /leave/roster/{id} | LeaveRosterController::update |
| DELETE | /leave/roster/{id} | LeaveRosterController::destroy |
| GET | /leave/roster/departments | LeaveRosterController::departments |
| GET | /leave/roster/distribution | LeaveRosterController::distribution |
| GET | /leave/roster/employees | LeaveRosterController::employees |
| GET | /leave/roster/export | LeaveRosterController::export |
| GET | /leave/roster/financial-years | LeaveRosterController::financialYears |
| GET | /leave/roster/matrix | LeaveRosterController::matrix |
| GET | /leave/roster/stats | LeaveRosterController::stats |
| GET | /leave/roster/upcoming | LeaveRosterController::upcoming |
| GET | /leave/types | LeaveController::types |

### /meetings

| Method | Path | Handler |
|---|---|---|
| POST | /meetings | MeetingController::store |
| GET | /meetings | MeetingController::index |
| PUT | /meetings/{id} | MeetingController::update |
| DELETE | /meetings/{id} | MeetingController::destroy |
| GET | /meetings/{id} | MeetingController::show |
| POST | /meetings/{id}/attendance | MeetingController::markAttendance |
| POST | /meetings/{id}/cancel | MeetingController::cancel |
| POST | /meetings/{id}/confirm | MeetingController::confirm |
| POST | /meetings/{id}/decline | MeetingController::decline |
| POST | /meetings/{id}/minutes | MeetingMinutesController::create |
| GET | /meetings/{id}/minutes | MeetingMinutesController::view |
| PUT | /meetings/{id}/minutes | MeetingMinutesController::update |
| GET | /meetings/{id}/minutes/options | MeetingMinutesController::options |
| POST | /meetings/{id}/minutes/publish | MeetingMinutesController::publish |
| POST | /meetings/{id}/minutes/reopen | MeetingMinutesController::reopen |
| GET | /meetings/{id}/minutes/status | MeetingMinutesController::status |
| GET | /meetings/{id}/participants | MeetingController::participants |
| POST | /meetings/{id}/participants | MeetingController::addParticipant |
| DELETE | /meetings/{id}/participants/{employeeId} | MeetingController::removeParticipant |
| GET | /meetings/eligible-employees | MeetingController::eligibleEmployees |
| GET | /meetings/stats | MeetingController::stats |

### /my-meetings

| Method | Path | Handler |
|---|---|---|
| GET | /my-meetings | MeetingController::myMeetings |

### /notification-preferences

| Method | Path | Handler |
|---|---|---|
| GET | /notification-preferences | NotificationPreferencesController::index |
| PUT | /notification-preferences | NotificationPreferencesController::update |

### /notifications

| Method | Path | Handler |
|---|---|---|
| GET | /notifications | NotificationController::index |
| POST | /notifications/{id}/read | NotificationController::markAsRead |
| POST | /notifications/read-all | NotificationController::markAllAsRead |

### /payroll

| Method | Path | Handler |
|---|---|---|
| GET | /payroll/periods | PayrollController::periods |
| GET | /payroll/periods | PayrollController::periods |
| POST | /payroll/periods | PayrollController::storePeriod |
| POST | /payroll/periods | PayrollController::storePeriod |
| GET | /payroll/records | PayrollController::records |
| GET | /payroll/records | PayrollController::records |
| POST | /payroll/records | PayrollController::storeRecord |
| POST | /payroll/records | PayrollController::storeRecord |

### /performance-contracts

| Method | Path | Handler |
|---|---|---|
| POST | /performance-contracts | PerformanceContractController::store |
| GET | /performance-contracts | PerformanceContractController::index |
| DELETE | /performance-contracts/{id} | PerformanceContractController::destroy |
| GET | /performance-contracts/{id} | PerformanceContractController::show |
| PUT | /performance-contracts/{id} | PerformanceContractController::update |

### /permissions

| Method | Path | Handler |
|---|---|---|
| GET | /permissions/catalog | PermissionController::catalog |
| GET | /permissions/overrides | PermissionController::overrides |
| GET | /permissions/roles | PermissionController::roles |
| GET | /permissions/statistics | PermissionController::statistics |
| GET | /permissions/users | PermissionController::users |
| GET | /permissions/users/{id} | PermissionController::userPermissions |
| POST | /permissions/users/{id}/overrides | PermissionController::setOverride |
| DELETE | /permissions/users/{id}/overrides | PermissionController::removeOverride |

### /profile

| Method | Path | Handler |
|---|---|---|
| GET | /profile | EmployeeController::profile |
| PUT | /profile | EmployeeController::updateProfile |
| POST | /profile/documents | EmployeeController::uploadProfileDocument |
| DELETE | /profile/documents/{id} | EmployeeController::deleteProfileDocument |
| GET | /profile/documents/{id} | EmployeeController::viewProfileDocument |
| GET | /profile/documents/{id}/view | EmployeeController::viewProfileDocument |
| GET | /profile/profile-image | EmployeeController::profileImage |
| POST | /profile/profile-image | EmployeeController::uploadProfileImage |

### /push

| Method | Path | Handler |
|---|---|---|
| DELETE | /push/subscribe | PushSubscriptionController::unsubscribe |
| POST | /push/subscribe | PushSubscriptionController::subscribe |
| GET | /push/subscriptions | PushSubscriptionController::index |
| GET | /push/vapid-public-key | PushSubscriptionController::vapidPublicKey |

### /reports

| Method | Path | Handler |
|---|---|---|
| GET | /reports/{type}/export/{format} | ReportController::export |
| GET | /reports/appraisal | ReportController::appraisal |
| GET | /reports/attendance | ReportController::attendance |
| GET | /reports/attendance/by-department | AttendanceReportController::byDepartment |
| GET | /reports/attendance/by-status | AttendanceReportController::byStatus |
| GET | /reports/attendance/compliance | AttendanceReportController::compliance |
| GET | /reports/attendance/employees | AttendanceReportController::employees |
| GET | /reports/attendance/export | AttendanceReportController::export |
| GET | /reports/attendance/insights | AttendanceReportController::insights |
| GET | /reports/attendance/late-arrivals | AttendanceReportController::lateArrivals |
| GET | /reports/attendance/options | AttendanceReportController::options |
| GET | /reports/attendance/records | AttendanceReportController::records |
| GET | /reports/attendance/summary | AttendanceReportController::summary |
| GET | /reports/attendance/trends | AttendanceReportController::trends |
| GET | /reports/attendance/working-hours | AttendanceReportController::workingHours |
| GET | /reports/documentation | ReportController::documentation |
| GET | /reports/employees | ReportController::employees |
| GET | /reports/leave | ReportController::leave |
| GET | /reports/leave/by-department | LeaveReportController::byDepartment |
| GET | /reports/leave/by-status | LeaveReportController::byStatus |
| GET | /reports/leave/by-type | LeaveReportController::byType |
| GET | /reports/leave/duration | LeaveReportController::duration |
| GET | /reports/leave/export | LeaveReportController::export |
| GET | /reports/leave/insights | LeaveReportController::insights |
| GET | /reports/leave/options | LeaveReportController::options |
| GET | /reports/leave/records | LeaveReportController::records |
| GET | /reports/leave/summary | LeaveReportController::summary |
| GET | /reports/leave/trends | LeaveReportController::trends |
| GET | /reports/strategic-performance | ReportController::strategicPerformance |

### /sectional-objectives

| Method | Path | Handler |
|---|---|---|
| GET | /sectional-objectives | SectionalObjectiveController::index |
| POST | /sectional-objectives | SectionalObjectiveController::store |
| DELETE | /sectional-objectives/{id} | SectionalObjectiveController::destroy |
| GET | /sectional-objectives/{id} | SectionalObjectiveController::show |
| PUT | /sectional-objectives/{id} | SectionalObjectiveController::update |

### /sections

| Method | Path | Handler |
|---|---|---|
| POST | /sections | SectionController::store |
| GET | /sections | SectionController::index |
| GET | /sections/{id} | SectionController::show |
| PUT | /sections/{id} | SectionController::update |
| DELETE | /sections/{id} | SectionController::destroy |

### /settings

| Method | Path | Handler |
|---|---|---|
| GET | /settings | SettingController::index |
| PUT | /settings | SettingController::update |

### /strategic-plans

| Method | Path | Handler |
|---|---|---|
| POST | /strategic-plans | StrategicPlanController::store |
| GET | /strategic-plans | StrategicPlanController::index |
| DELETE | /strategic-plans/{id} | StrategicPlanController::destroy |
| PUT | /strategic-plans/{id} | StrategicPlanController::update |
| POST | /strategic-plans/{id}/goals | StrategicPlanController::storeGoal |
| POST | /strategic-plans/{id}/targets | StrategicPlanController::storeTarget |
| GET | /strategic-plans/{id}/workplans | WorkplanController::index |

### /subsections

| Method | Path | Handler |
|---|---|---|
| GET | /subsections | SubsectionController::index |
| POST | /subsections | SubsectionController::store |
| PUT | /subsections/{id} | SubsectionController::update |
| DELETE | /subsections/{id} | SubsectionController::destroy |
| GET | /subsections/{id} | SubsectionController::show |

### /system

| Method | Path | Handler |
|---|---|---|
| POST | /system/client-errors | MonitoringController::clientError |
| GET | /system/errors/{uuid} | MonitoringController::show |
| GET | /system/errors/groups | MonitoringController::groups |
| POST | /system/errors/groups/{id}/manage | MonitoringController::manage |
| GET | /system/errors/stats | MonitoringController::stats |
| GET | /system/health | MonitoringController::health |
| GET | /system/performance | MonitoringController::performance |

### /targets

| Method | Path | Handler |
|---|---|---|
| PUT | /targets/{id} | StrategicPlanController::updateTarget |
| DELETE | /targets/{id} | StrategicPlanController::destroyTarget |

### /users

| Method | Path | Handler |
|---|---|---|
| GET | /users | UserController::index |
| POST | /users | UserController::store |
| PUT | /users/{id} | UserController::update |
| DELETE | /users/{id} | UserController::destroy |
| GET | /users/{id} | UserController::show |
| POST | /users/{id}/change-password | UserController::changePassword |
| PUT | /users/{id}/toggle-status | UserController::toggleStatus |

### /workplans

| Method | Path | Handler |
|---|---|---|
| POST | /workplans | WorkplanController::store |
| GET | /workplans | WorkplanController::list |
| GET | /workplans/{id} | WorkplanController::show |
| PUT | /workplans/{id} | WorkplanController::update |
| DELETE | /workplans/{id} | WorkplanController::destroy |
| POST | /workplans/{id}/cascade | WorkplanController::cascade |
| GET | /workplans/{id}/dependencies | WorkplanController::dependencies |
| PUT | /workplans/{id}/progress | WorkplanController::progressUpdate |
| GET | /workplans/{id}/progress-history | WorkplanController::progressHistory |
| GET | /workplans/{id}/traceability | WorkplanController::traceability |
| POST | /workplans/bulk | WorkplanController::bulk |
| GET | /workplans/export | WorkplanController::export |
| GET | /workplans/integrated-view | WorkplanController::integratedView |
| GET | /workplans/section-sources | WorkplanController::sectionSources |
| GET | /workplans/summary | WorkplanController::summary |


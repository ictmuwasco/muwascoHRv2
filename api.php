<?php

declare(strict_types=1);

// Load the application bootstrap (autoload, env, config, logger, etc.)
require_once __DIR__ . '/backend/bootstrap.php';

// Apply centralized CORS policy from backend/config/cors.php.
// Must run before the OPTIONS preflight short-circuit so preflight
// responses carry the correct Access-Control-* headers.
\App\Middleware\SecurityMiddleware::applyCorsHeaders();

// Handle CORS preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

use App\Controllers\Auth\AuthController;
use App\Controllers\Employee\EmployeeController;
use App\Controllers\HR\DepartmentController;
use App\Controllers\Leave\LeaveController;
use App\Controllers\Leave\LeaveRosterController;
use App\Controllers\Leave\LeaveReportController;
use App\Controllers\AttendanceController;
use App\Controllers\Employee\UserController;
use App\Controllers\HR\SectionController;
use App\Controllers\HR\SubsectionController;
use App\Controllers\DashboardController;
use App\Controllers\HR\ConsentController;
use App\Controllers\HR\FinancialYearController;
use App\Controllers\Settings\NotificationController;
use App\Controllers\Settings\AuditLogController;
use App\Controllers\HR\HolidayController;
use App\Controllers\Settings\PermissionController;
use App\Controllers\Meeting\MeetingController;
use App\Controllers\Meeting\MeetingMinutesController;
use App\Controllers\Reports\ReportsController as ReportController;
use App\Controllers\Reports\AttendanceReportController;
use App\Controllers\HR\AppraisalController;
use App\Controllers\HR\StrategicPlanController;
use App\Controllers\HR\WorkplanController;
use App\Controllers\HR\AppraisalCycleController;
use App\Controllers\HR\KPIController;
use App\Controllers\HR\PerformanceContractController;
use App\Controllers\HR\SectionalObjectiveController;
use App\Controllers\HR\PayrollController;
use App\Controllers\HR\ComplaintController;
use App\Controllers\Settings\SettingController;

// ===========================================================================
// Global security + authentication gate (Phase 1 consolidation).
//
// SecurityMiddleware::run() applies security headers, trusted-proxy
// normalization and session timeout enforcement ONCE per request.
//
// SecurityMiddleware::applyCorsHeaders() emits the centralized CORS policy
// from backend/config/cors.php (replacing the inline origin list below).
//
// AuthenticationMiddleware::process() is the single authentication gate:
// every API route requires an authenticated, active account unless the route
// is on the explicit public allowlist. Controllers keep their fine-grained
// permission checks (requirePermission) on top of this gate.
// ===========================================================================
\App\Middleware\SecurityMiddleware::applyCorsHeaders();
\App\Middleware\SecurityMiddleware::run();
\App\Middleware\AuthenticationMiddleware::process();

/**
 * Simple Router
 */
class ApiRouter
{
    private array $routes = [];

    public function add(string $method, string $path, string $controller, string $action, ?string $permission = null, ?string $throttle = null): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
            // Server-defined required permission as "module:action".
            // null = authenticated-only (self-service/ownership/scope logic
            // enforced inside the controller). NEVER taken from the request.
            'permission' => $permission,
            // Phase 7: server-defined rate limit as "max:windowSeconds",
            // enforced per authenticated user + client IP after the
            // permission gate. null = no throttle. Sensitive routes
            // (exports, identity/permission writes, uploads, approvals,
            // clock) MUST declare one — governance list:
            // backend/config/rate_limits.php, enforced by
            // backend/tests/Unit/Authorization/RoutePermissionMapTest.php.
            'throttle' => $throttle,
        ];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        
        // Remove /hrdemo prefix and /api prefix
        $path = preg_replace('#^/hrdemo#', '', $uri);
        $path = preg_replace('#^/api#', '', $path);
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);

                // Server-side authorization gate (Phase 2): the required
                // permission comes from the route definition above — never
                // from the request. Denied requests never reach a controller.
                if (!empty($route['permission'])) {
                    \App\Middleware\AuthorizationMiddleware::enforce($route['permission']);
                }

                // Phase 7: server-side rate limiting for sensitive routes.
                // Runs AFTER the permission gate so unauthorized callers get
                // 403 (not 429) and the bucket is only consumed by callers
                // who could actually execute the action. Keyed per user + IP
                // (see SecurityMiddleware::rateLimitKey) so one heavy
                // operator never locks out colleagues.
                if (!empty($route['throttle'])) {
                    [$throttleMax, $throttleWindow] = array_map('intval', explode(':', $route['throttle'], 2));
                    $throttleUser = isset($_SESSION['user_id']) ? (string) (int) $_SESSION['user_id'] : null;
                    \App\Middleware\SecurityMiddleware::protectAgainstBruteForce(
                        'route ' . $route['method'] . ' ' . $route['path'],
                        $throttleMax,
                        $throttleWindow,
                        $throttleUser
                    );
                }

                $controllerClass = $route['controller'];
                $action = $route['action'];
                
                $controller = new $controllerClass();
                
                // Try the Action-suffixed method first, then the plain method name
                $actionMethod = $action . 'Action';
                if (method_exists($controller, $actionMethod)) {
                    $this->callAction($controller, $actionMethod, $matches);
                } elseif (method_exists($controller, $action)) {
                    $this->callAction($controller, $action, $matches);
                } else {
                    \App\Helpers\ApiResponse::error(
                        "Method {$action} not found on " . $controllerClass,
                        'CONTROLLER_METHOD_NOT_FOUND',
                        [],
                        500
                    );
                }
                return;
            }
        }

        \App\Helpers\ApiResponse::error(
            'Route not found.',
            'ROUTE_NOT_FOUND',
            ['path' => $path],
            404
        );
    }

    /**
     * Call a controller action with route parameters.
     *
     * Route URI parameters are always extracted as strings (e.g. '535').
     * Controller methods declare typed parameters (e.g. `int $id`), so a
     * string would cause a TypeError under strict_types and produce a 500.
     * This method inspects the method signature via reflection and casts
     * each value to the declared type before invocation.
     */
    private function callAction(object $controller, string $methodName, array $params): void
    {
        $ref = new \ReflectionMethod($controller, $methodName);
        $args = [];
        foreach ($ref->getParameters() as $i => $param) {
            $value = $params[$i] ?? null;
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && $type->getName() === 'int') {
                $args[] = (int) $value;
            } else {
                $args[] = $value;
            }
        }
        $controller->$methodName(...$args);
    }
}

$router = new ApiRouter();

// Auth routes
$router->add('POST', '/auth/login', AuthController::class, 'login');
$router->add('POST', '/auth/logout', AuthController::class, 'logout');
$router->add('POST', '/auth/refresh', AuthController::class, 'refresh');
$router->add('GET', '/auth/user', AuthController::class, 'me');
$router->add('POST', '/auth/change-password', AuthController::class, 'changePassword');

// Holiday routes — reads are reference data (authenticated-only);
// writes are permission-gated in the controller and here.
$router->add('GET', '/holidays', HolidayController::class, 'index');
$router->add('GET', '/holidays/upcoming', HolidayController::class, 'upcoming');
$router->add('GET', '/holidays/{id}', HolidayController::class, 'show');
$router->add('POST', '/holidays', HolidayController::class, 'store', 'holidays:create');
$router->add('PUT', '/holidays/{id}', HolidayController::class, 'update', 'holidays:edit');
$router->add('DELETE', '/holidays/{id}', HolidayController::class, 'destroy', 'holidays:delete');

// Employee routes
$router->add('GET', '/employees/search', EmployeeController::class, 'search', 'employees:view');
$router->add('GET', '/employees', EmployeeController::class, 'index', 'employees:view');
$router->add('POST', '/employees', EmployeeController::class, 'store', 'employees:create', '30:300');
$router->add('GET', '/employees/reference', EmployeeController::class, 'reference', 'employees:view');
$router->add('GET', '/employees/{id}', EmployeeController::class, 'show', 'employees:view');
$router->add('PUT', '/employees/{id}', EmployeeController::class, 'update', 'employees:edit', '30:300');
$router->add('DELETE', '/employees/{id}', EmployeeController::class, 'destroy', 'employees:delete');

// Department routes (list is reference/filter data: authenticated-only;
// detail + writes are permission-gated)
$router->add('GET', '/departments', DepartmentController::class, 'index');
$router->add('POST', '/departments', DepartmentController::class, 'store', 'departments:create');
$router->add('GET', '/departments/{id}', DepartmentController::class, 'show', 'departments:view');
$router->add('PUT', '/departments/{id}', DepartmentController::class, 'update', 'departments:edit');
$router->add('DELETE', '/departments/{id}', DepartmentController::class, 'destroy', 'departments:delete');

// Section routes (list is reference/filter data; org-unit writes are
// departments:* — sections belong to the departments module)
$router->add('GET', '/sections', SectionController::class, 'index');
$router->add('POST', '/sections', SectionController::class, 'store', 'departments:create');
$router->add('GET', '/sections/{id}', SectionController::class, 'show', 'departments:view');
$router->add('PUT', '/sections/{id}', SectionController::class, 'update', 'departments:edit');
$router->add('DELETE', '/sections/{id}', SectionController::class, 'destroy', 'departments:delete');

// Subsection routes
$router->add('GET', '/subsections', SubsectionController::class, 'index');
$router->add('POST', '/subsections', SubsectionController::class, 'store', 'departments:create');
$router->add('GET', '/subsections/{id}', SubsectionController::class, 'show', 'departments:view');
$router->add('PUT', '/subsections/{id}', SubsectionController::class, 'update', 'departments:edit');
$router->add('DELETE', '/subsections/{id}', SubsectionController::class, 'destroy', 'departments:delete');

// User routes
$router->add('GET', '/users', UserController::class, 'index', 'users:view');
$router->add('POST', '/users', UserController::class, 'store', 'users:create', '30:300');
$router->add('GET', '/users/{id}', UserController::class, 'show', 'users:view');
$router->add('PUT', '/users/{id}', UserController::class, 'update', 'users:edit', '30:300');
$router->add('DELETE', '/users/{id}', UserController::class, 'destroy', 'users:delete', '30:300');
$router->add('PUT', '/users/{id}/toggle-status', UserController::class, 'toggleStatus', 'users:edit', '30:300');
$router->add('POST', '/users/{id}/change-password', UserController::class, 'changePassword', 'users:edit', '10:900');

// Attendance routes — clock-in/out are self-service (own record enforced in
// the controller); record administration requires attendance:manage.
$router->add('GET', '/attendance/today', AttendanceController::class, 'today', 'attendance:view');
$router->add('GET', '/attendance/dashboard', AttendanceController::class, 'dashboard', 'attendance:view');
// HR attendance monitoring dashboard + employee history (before /attendance/{id} wildcard)
$router->add('GET', '/attendance/hr-dashboard', AttendanceController::class, 'hrDashboard', 'attendance:view');
$router->add('GET', '/attendance/hr-employee-history', AttendanceController::class, 'hrEmployeeHistory', 'attendance:view');
$router->add('GET', '/attendance/my-records', AttendanceController::class, 'myRecords', 'attendance:view');
$router->add('GET', '/attendance/employee/{id}', AttendanceController::class, 'byEmployee', 'attendance:view');
$router->add('POST', '/attendance/clock-in', AttendanceController::class, 'clockIn', null, '10:300');
$router->add('POST', '/attendance/clock-out', AttendanceController::class, 'clockOut', null, '10:300');
$router->add('POST', '/attendance/auto-clockout', AttendanceController::class, 'autoClockOut', null, '10:300');
$router->add('GET', '/attendance', AttendanceController::class, 'index', 'attendance:view');
// Phase 5: legacy store/show/update/destroy routes removed — the controller
// never implemented those actions (they were latent 500s); attendance
// administration is served by the HR dashboard + eligibility service paths.

// Leave routes - only methods that exist in LeaveController.
// Approval workflow decisions are additionally scope-checked by
// LeaveWorkflowService (who may approve WHOM).
$router->add('GET', '/leave', LeaveController::class, 'index', 'leave:view');
$router->add('POST', '/leave/apply', LeaveController::class, 'apply', 'leave:apply');
$router->add('GET', '/leave/types', LeaveController::class, 'types', 'leave:view');
$router->add('GET', '/leave/eligible-employees', LeaveController::class, 'eligibleEmployees', 'leave:apply');
$router->add('GET', '/leave/eligible-delegates', LeaveController::class, 'eligibleDelegates', 'leave:apply');
$router->add('GET', '/leave/manage', LeaveController::class, 'manage', 'leave:manage');
$router->add('GET', '/leave/delegates', LeaveController::class, 'delegates', 'leave:apply');
$router->add('GET', '/leave/calculate', LeaveController::class, 'calculate', 'leave:view');
$router->add('GET', '/leave/{id}/documents', LeaveController::class, 'listDocuments');
$router->add('GET', '/leave/{id}/documents/{documentId}', LeaveController::class, 'viewDocument');
$router->add('PUT', '/leave/{id}/approve', LeaveController::class, 'approve', 'leave:approve', '120:300');
$router->add('PUT', '/leave/{id}/reject', LeaveController::class, 'reject', 'leave:reject', '120:300');
$router->add('PUT', '/leave/{id}/invalidate', LeaveController::class, 'invalidate', 'leave:invalidate', '120:300');
$router->add('PUT', '/leave/{id}/cancel', LeaveController::class, 'cancel', 'leave:apply', '120:300');

// Employee Leave Profile routes - must be registered before /leave/{id} wildcard routes.
// Profile reads are scope-checked per record by LeaveProfileService::canViewProfile().
$router->add('GET', '/leave/profile/employees', LeaveController::class, 'profileEmployees', 'leave:view');
$router->add('GET', '/leave/profile/{id}', LeaveController::class, 'profile', 'leave:view');
$router->add('GET', '/leave/profile/{id}/balances', LeaveController::class, 'profileBalances', 'leave:view');
$router->add('GET', '/leave/profile/{id}/applications', LeaveController::class, 'profileApplications', 'leave:view');
$router->add('GET', '/leave/profile/{id}/timeline', LeaveController::class, 'profileTimeline', 'leave:view');
$router->add('GET', '/leave/profile/{id}/summary', LeaveController::class, 'profileSummary', 'leave:view');
$router->add('GET', '/leave/profile/{id}/export', LeaveController::class, 'profileExport', 'leave:view', '20:300');

// Leave Roster routes - static sub-resource routes must be registered before /leave/roster/{id}.
// Roster planning is an approver/HR function: reads are leave:view, writes and
// the bulk export require leave:manage (previously these endpoints had NO
// permission gate at all — only the global authentication gate).
$router->add('GET', '/leave/roster/stats', LeaveRosterController::class, 'stats', 'leave:view');
$router->add('GET', '/leave/roster/distribution', LeaveRosterController::class, 'distribution', 'leave:view');
$router->add('GET', '/leave/roster/upcoming', LeaveRosterController::class, 'upcoming', 'leave:view');
$router->add('GET', '/leave/roster/departments', LeaveRosterController::class, 'departments', 'leave:view');
$router->add('GET', '/leave/roster/matrix', LeaveRosterController::class, 'matrix', 'leave:view');
$router->add('GET', '/leave/roster/export', LeaveRosterController::class, 'export', 'leave:manage', '20:300');
$router->add('GET', '/leave/roster/employees', LeaveRosterController::class, 'employees', 'leave:view');
$router->add('GET', '/leave/roster/financial-years', LeaveRosterController::class, 'financialYears', 'leave:view');
$router->add('GET', '/leave/roster', LeaveRosterController::class, 'index', 'leave:view');
$router->add('POST', '/leave/roster', LeaveRosterController::class, 'store', 'leave:manage');
$router->add('PUT', '/leave/roster/{id}', LeaveRosterController::class, 'update', 'leave:manage');
$router->add('DELETE', '/leave/roster/{id}', LeaveRosterController::class, 'destroy', 'leave:manage');

// Dashboard routes
$router->add('GET', '/dashboard', DashboardController::class, 'index', 'dashboard:view');
$router->add('GET', '/dashboard/stats', DashboardController::class, 'stats', 'dashboard:view');
$router->add('GET', '/dashboard/charts/attendance', DashboardController::class, 'chartsAttendance', 'dashboard:view');
$router->add('GET', '/dashboard/charts/departments', DashboardController::class, 'chartsDepartments', 'dashboard:view');
$router->add('GET', '/dashboard/charts/leave', DashboardController::class, 'chartsLeave', 'dashboard:view');

// Report routes
$router->add('GET', '/reports/employees', ReportController::class, 'employees', 'reports:view');
$router->add('GET', '/reports/leave', ReportController::class, 'leave', 'reports:view');
$router->add('GET', '/reports/attendance', ReportController::class, 'attendance', 'reports:view');
$router->add('GET', '/reports/appraisal', ReportController::class, 'appraisal', 'reports:view');
$router->add('GET', '/reports/documentation', ReportController::class, 'documentation', 'reports:view');

// Leave Reports module - dedicated analytics + reporting endpoints.
// Registered BEFORE the /reports/{type}/export/{format} wildcard so the
// specific leave routes take precedence.
$router->add('GET', '/reports/leave/options', LeaveReportController::class, 'options', 'reports:view');
$router->add('GET', '/reports/leave/summary', LeaveReportController::class, 'summary', 'reports:view');
$router->add('GET', '/reports/leave/trends', LeaveReportController::class, 'trends', 'reports:view');
$router->add('GET', '/reports/leave/by-type', LeaveReportController::class, 'byType', 'reports:view');
$router->add('GET', '/reports/leave/by-department', LeaveReportController::class, 'byDepartment', 'reports:view');
$router->add('GET', '/reports/leave/by-status', LeaveReportController::class, 'byStatus', 'reports:view');
$router->add('GET', '/reports/leave/duration', LeaveReportController::class, 'duration', 'reports:view');
$router->add('GET', '/reports/leave/insights', LeaveReportController::class, 'insights', 'reports:view');
$router->add('GET', '/reports/leave/records', LeaveReportController::class, 'records', 'reports:view');
$router->add('GET', '/reports/leave/export', LeaveReportController::class, 'export', 'reports:export', '20:300');

// Attendance Reports module - dedicated analytics + reporting endpoints.
// Registered BEFORE the /reports/{type}/export/{format} wildcard so the
// specific attendance routes take precedence. The legacy aggregated endpoint
// GET /reports/attendance (ReportsController::attendance) stays registered
// above for backward compatibility.
$router->add('GET', '/reports/attendance/options', AttendanceReportController::class, 'options', 'reports:view');
$router->add('GET', '/reports/attendance/summary', AttendanceReportController::class, 'summary', 'reports:view');
$router->add('GET', '/reports/attendance/trends', AttendanceReportController::class, 'trends', 'reports:view');
$router->add('GET', '/reports/attendance/by-status', AttendanceReportController::class, 'byStatus', 'reports:view');
$router->add('GET', '/reports/attendance/by-department', AttendanceReportController::class, 'byDepartment', 'reports:view');
$router->add('GET', '/reports/attendance/late-arrivals', AttendanceReportController::class, 'lateArrivals', 'reports:view');
$router->add('GET', '/reports/attendance/working-hours', AttendanceReportController::class, 'workingHours', 'reports:view');
$router->add('GET', '/reports/attendance/insights', AttendanceReportController::class, 'insights', 'reports:view');
$router->add('GET', '/reports/attendance/compliance', AttendanceReportController::class, 'compliance', 'reports:view');
$router->add('GET', '/reports/attendance/employees', AttendanceReportController::class, 'employees', 'reports:view');
$router->add('GET', '/reports/attendance/records', AttendanceReportController::class, 'records', 'reports:view');
$router->add('GET', '/reports/attendance/export', AttendanceReportController::class, 'export', 'reports:export', '20:300');

$router->add('GET', '/reports/{type}/export/{format}', ReportController::class, 'export', 'reports:export', '20:300');

// Payroll routes
$router->add('GET', '/payroll/periods', PayrollController::class, 'periods', 'payroll:view');
$router->add('POST', '/payroll/periods', PayrollController::class, 'storePeriod', 'payroll:manage');
$router->add('GET', '/payroll/records', PayrollController::class, 'records', 'payroll:view');
$router->add('POST', '/payroll/records', PayrollController::class, 'storeRecord', 'payroll:manage');

// Complaint routes — employees file and list their OWN complaints (self-scope
// enforced in the controller); HR triage/update requires complaints:view.
$router->add('GET', '/complaints', ComplaintController::class, 'index');
$router->add('POST', '/complaints', ComplaintController::class, 'store');
$router->add('PUT', '/complaints/{id}', ComplaintController::class, 'update', 'complaints:view');

// Consent routes — status/verify/store are the self-service onboarding flow;
// admin-side consent administration requires consent:* permissions.
$router->add('GET', '/consents', ConsentController::class, 'index', 'consent:view');
$router->add('PUT', '/consents/{id}', ConsentController::class, 'update', 'consent:manage');
$router->add('GET', '/consent/status', ConsentController::class, 'status');
$router->add('POST', '/consent/verify-employee', ConsentController::class, 'verifyEmployeeId');
$router->add('POST', '/consent', ConsentController::class, 'storeConsent');
$router->add('GET', '/consent/dashboard', ConsentController::class, 'dashboard', 'consent:view');
$router->add('GET', '/consent/employees', ConsentController::class, 'employees', 'consent:view');

// Financial Year routes
$router->add('GET', '/admin/financial-years', FinancialYearController::class, 'index', 'financial_year:view');
$router->add('GET', '/admin/financial-years/status', FinancialYearController::class, 'status', 'financial_year:view');
$router->add('POST', '/admin/financial-year/add', FinancialYearController::class, 'store', 'financial_year:create');
$router->add('POST', '/admin/financial-year/allocate', FinancialYearController::class, 'allocateLeave', 'financial_year:edit', '10:300');
$router->add('GET', '/admin/financial-years/leave-types', FinancialYearController::class, 'leaveTypes', 'financial_year:view');
$router->add('GET', '/admin/financial-years/employees', FinancialYearController::class, 'employees', 'financial_year:edit');

// Appraisal routes
$router->add('GET', '/appraisals', AppraisalController::class, 'index', 'performance:view');
$router->add('POST', '/appraisals', AppraisalController::class, 'store', 'performance:manage');
$router->add('GET', '/appraisals/{id}', AppraisalController::class, 'show', 'performance:view');
$router->add('PUT', '/appraisals/{id}', AppraisalController::class, 'update', 'performance:manage');
$router->add('DELETE', '/appraisals/{id}', AppraisalController::class, 'destroy', 'performance:manage');
$router->add('GET', '/appraisals/pending', AppraisalController::class, 'pending', 'performance:view');
$router->add('GET', '/appraisals/employee/{id}', AppraisalController::class, 'byEmployee', 'performance:view');
$router->add('PUT', '/appraisals/{id}/submit', AppraisalController::class, 'submit', 'performance:manage');
$router->add('PUT', '/appraisals/{id}/approve', AppraisalController::class, 'approve', 'performance:manage');

// ========================================================================
// Strategy & Performance module routes
// (strategic_plan -> goals -> strategic_targets -> performance_contracts
//  -> workplan_objectives -> kpis -> appraisals)
// ========================================================================

// Strategic plans, goals and strategic targets.
// Scope (WHO may manage WHAT) is enforced by OrgScope inside the controllers;
// the route gate mirrors the seeded strategic_plan permissions.
$router->add('GET',    '/strategic-plans',              StrategicPlanController::class, 'index', 'strategic_plan:view');
$router->add('POST',   '/strategic-plans',              StrategicPlanController::class, 'store', 'strategic_plan:manage');
$router->add('PUT',    '/strategic-plans/{id}',         StrategicPlanController::class, 'update', 'strategic_plan:manage');
$router->add('DELETE', '/strategic-plans/{id}',         StrategicPlanController::class, 'destroy', 'strategic_plan:manage');
$router->add('POST',   '/strategic-plans/{id}/goals',   StrategicPlanController::class, 'storeGoal', 'strategic_plan:manage');
$router->add('PUT',    '/goals/{id}',                   StrategicPlanController::class, 'updateGoal', 'strategic_plan:manage');
$router->add('DELETE', '/goals/{id}',                   StrategicPlanController::class, 'destroyGoal', 'strategic_plan:manage');
$router->add('POST',   '/strategic-plans/{id}/targets', StrategicPlanController::class, 'storeTarget', 'strategic_plan:manage');
$router->add('PUT',    '/targets/{id}',                 StrategicPlanController::class, 'updateTarget', 'strategic_plan:manage');
$router->add('DELETE', '/targets/{id}',                 StrategicPlanController::class, 'destroyTarget', 'strategic_plan:manage');

// Performance contracts — reads mirror the seeded performance_contract:view;
// writes are OrgScope-managed (scope logic).
$router->add('GET',    '/performance-contracts',        PerformanceContractController::class, 'index', 'performance_contract:view');
$router->add('POST',   '/performance-contracts',        PerformanceContractController::class, 'store', 'performance_contract:manage');
$router->add('GET',    '/performance-contracts/{id}',   PerformanceContractController::class, 'show', 'performance_contract:view');
$router->add('PUT',    '/performance-contracts/{id}',   PerformanceContractController::class, 'update', 'performance_contract:manage');
$router->add('DELETE', '/performance-contracts/{id}',   PerformanceContractController::class, 'destroy', 'performance_contract:manage');

// Appraisal cycles (quarterly quarters attached to every workplan activity).
// Read: any authenticated user. Write: HR managers / super admins
// (AppraisalCycleController::canManage -> performance:manage).
$router->add('GET',    '/appraisal-cycles',          AppraisalCycleController::class, 'index');
$router->add('POST',   '/appraisal-cycles',          AppraisalCycleController::class, 'store', 'performance:manage');
$router->add('PUT',    '/appraisal-cycles/{id}',     AppraisalCycleController::class, 'update', 'performance:manage');
$router->add('DELETE', '/appraisal-cycles/{id}',     AppraisalCycleController::class, 'destroy', 'performance:manage');

// Workplan objectives — reads mirror the seeded workplan:view; all writes are
// OrgScope-managed (organizational scope logic in the controllers).
$router->add('GET',    '/strategic-plans/{id}/workplans', WorkplanController::class, 'index', 'workplan:view');
$router->add('GET',    '/workplans',                    WorkplanController::class, 'list', 'workplan:view');
$router->add('POST',   '/workplans',                    WorkplanController::class, 'store', 'workplan:manage');
// Legacy-parity batch creation: one contract -> many activities (dept heads).
$router->add('POST',   '/workplans/bulk',               WorkplanController::class, 'bulk', 'workplan:manage');
// Workplan extension routes (must be declared BEFORE the /workplans/{id} wildcard)
$router->add('GET',    '/workplans/integrated-view',    WorkplanController::class, 'integratedView', 'workplan:view');
$router->add('GET',    '/workplans/export',             WorkplanController::class, 'export', 'workplan:view', '20:300');
// Cascading workplan system: dashboard summary, downward cascade, lineage.
$router->add('GET',    '/workplans/summary',            WorkplanController::class, 'summary', 'workplan:view');
$router->add('GET',    '/workplans/section-sources',    WorkplanController::class, 'sectionSources', 'workplan:view');
$router->add('POST',   '/workplans/{id}/cascade',       WorkplanController::class, 'cascade', 'workplan:manage');
$router->add('GET',    '/workplans/{id}/traceability',  WorkplanController::class, 'traceability', 'workplan:view');
$router->add('GET',    '/workplans/{id}/progress-history', WorkplanController::class, 'progressHistory', 'workplan:view');
$router->add('PUT',    '/workplans/{id}/progress',      WorkplanController::class, 'progressUpdate', 'workplan:manage');
$router->add('GET',    '/workplans/{id}/dependencies',  WorkplanController::class, 'dependencies', 'workplan:view');
$router->add('GET',    '/workplans/{id}',               WorkplanController::class, 'show', 'workplan:view');
$router->add('PUT',    '/workplans/{id}',               WorkplanController::class, 'update', 'workplan:manage');
$router->add('DELETE', '/workplans/{id}',               WorkplanController::class, 'destroy', 'workplan:manage');

// KPIs (linked to performance contracts)
$router->add('GET',    '/contracts/{id}/kpis',          KPIController::class, 'index', 'kpi:view');
$router->add('POST',   '/contracts/{id}/kpis',          KPIController::class, 'store', 'kpi:manage');
$router->add('GET',    '/kpis',                         KPIController::class, 'list', 'kpi:view');
$router->add('PUT',    '/kpis/{id}',                    KPIController::class, 'update', 'kpi:manage');
$router->add('DELETE', '/kpis/{id}',                    KPIController::class, 'destroy', 'kpi:manage');

// Sectional objectives / KPIs (performance indicators)
$router->add('GET',    '/sectional-objectives',         SectionalObjectiveController::class, 'index', 'sectional_objective:view');
$router->add('POST',   '/sectional-objectives',         SectionalObjectiveController::class, 'store', 'sectional_objective:manage');
$router->add('GET',    '/sectional-objectives/{id}',    SectionalObjectiveController::class, 'show', 'sectional_objective:view');
$router->add('PUT',    '/sectional-objectives/{id}',    SectionalObjectiveController::class, 'update', 'sectional_objective:manage');
$router->add('DELETE', '/sectional-objectives/{id}',    SectionalObjectiveController::class, 'destroy', 'sectional_objective:manage');

// Strategy & Performance dashboard + report endpoints
$router->add('GET',    '/dashboard/strategic-performance', DashboardController::class, 'strategicPerformance', 'dashboard:view');
$router->add('GET',    '/reports/strategic-performance',   ReportController::class, 'strategicPerformance', 'reports:view');

// Payroll routes (duplicate registration kept for backward compatibility —
// first match wins, identical controller targets)
$router->add('GET',    '/payroll/periods',           PayrollController::class, 'periods', 'payroll:view');
$router->add('POST',   '/payroll/periods',           PayrollController::class, 'storePeriod', 'payroll:manage');
$router->add('GET',    '/payroll/records',           PayrollController::class, 'records', 'payroll:view');
$router->add('POST',   '/payroll/records',           PayrollController::class, 'storeRecord', 'payroll:manage');

// Complaint routes (duplicate registration kept for backward compatibility)
$router->add('GET',    '/complaints',                ComplaintController::class, 'index');
$router->add('POST',   '/complaints',                ComplaintController::class, 'store');
$router->add('PUT',    '/complaints/{id}',           ComplaintController::class, 'update', 'complaints:view');


// Notification routes — every user manages their OWN notifications
// (ownership enforced in the controller).
$router->add('GET', '/notifications', NotificationController::class, 'index');
$router->add('POST', '/notifications/{id}/read', NotificationController::class, 'markAsRead');
$router->add('POST', '/notifications/read-all', NotificationController::class, 'markAllAsRead');

// Audit routes - plain method names
$router->add('GET', '/audit', AuditLogController::class, 'index', 'audit:view');
$router->add('GET', '/audit/statistics', AuditLogController::class, 'statistics', 'audit:view');
$router->add('GET', '/audit/filters', AuditLogController::class, 'filters', 'audit:view');
$router->add('GET', '/audit/export', AuditLogController::class, 'export', 'audit:export', '20:300');
$router->add('GET', '/audit/{id}', AuditLogController::class, 'show', 'audit:view');
$router->add('GET', '/audit-logs', AuditLogController::class, 'index', 'audit:view');

// Settings routes
$router->add('GET', '/settings', SettingController::class, 'index', 'admin:view');
$router->add('PUT', '/settings', SettingController::class, 'update', 'admin:manage');

// Profile routes — self-service (own record enforced in the controller)
$router->add('GET', '/profile', EmployeeController::class, 'profile', 'profile:view');
$router->add('PUT', '/profile', EmployeeController::class, 'updateProfile', 'profile:edit');
$router->add('POST', '/profile/documents', EmployeeController::class, 'uploadProfileDocument', 'profile:edit', '20:300');
$router->add('GET', '/profile/documents/{id}', EmployeeController::class, 'viewProfileDocument');
$router->add('GET', '/profile/documents/{id}/view', EmployeeController::class, 'viewProfileDocument');
$router->add('DELETE', '/profile/documents/{id}', EmployeeController::class, 'deleteProfileDocument', 'profile:edit');

// Profile picture routes
$router->add('POST', '/profile/profile-image', EmployeeController::class, 'uploadProfileImage', 'profile:edit', '20:300');
$router->add('GET', '/profile/profile-image', EmployeeController::class, 'profileImage', 'profile:view');
$router->add('POST', '/employees/{id}/profile-image', EmployeeController::class, 'uploadEmployeeProfileImage', 'employees:edit', '20:300');
$router->add('GET', '/employees/{id}/profile-image', EmployeeController::class, 'employeeProfileImage', 'employees:view');

// Permission routes - plain method names (permission administration itself
// is protected by permission_overrides:view / permission_overrides:manage)
$router->add('GET', '/permissions/catalog', PermissionController::class, 'catalog', 'permission_overrides:view');
$router->add('GET', '/permissions/statistics', PermissionController::class, 'statistics', 'permission_overrides:view');
$router->add('GET', '/permissions/roles', PermissionController::class, 'roles', 'permission_overrides:view');
$router->add('GET', '/permissions/users', PermissionController::class, 'users', 'permission_overrides:view');
$router->add('GET', '/permissions/users/{id}', PermissionController::class, 'userPermissions', 'permission_overrides:view');
$router->add('GET', '/permissions/overrides', PermissionController::class, 'overrides', 'permission_overrides:view');
$router->add('POST', '/permissions/users/{id}/overrides', PermissionController::class, 'setOverride', 'permission_overrides:manage', '30:300');
$router->add('DELETE', '/permissions/users/{id}/overrides', PermissionController::class, 'removeOverride', 'permission_overrides:manage', '30:300');

// Meeting routes - Laravel-style methods
$router->add('GET', '/my-meetings', MeetingController::class, 'myMeetings', 'meetings:view');
$router->add('GET', '/meetings', MeetingController::class, 'index', 'meetings:view');
$router->add('GET', '/meetings/stats', MeetingController::class, 'stats', 'meetings:view');
$router->add('GET', '/meetings/eligible-employees', MeetingController::class, 'eligibleEmployees', 'meetings:view');
$router->add('POST', '/meetings', MeetingController::class, 'store', 'meetings:create');
$router->add('GET', '/meetings/{id}', MeetingController::class, 'show', 'meetings:view');
$router->add('PUT', '/meetings/{id}', MeetingController::class, 'update', 'meetings:edit');
$router->add('DELETE', '/meetings/{id}', MeetingController::class, 'destroy', 'meetings:delete');
$router->add('POST', '/meetings/{id}/cancel', MeetingController::class, 'cancel', 'meetings:edit');
$router->add('GET', '/meetings/{id}/participants', MeetingController::class, 'participants', 'meetings:view');
$router->add('POST', '/meetings/{id}/participants', MeetingController::class, 'addParticipant', 'meetings:invite');
$router->add('DELETE', '/meetings/{id}/participants/{employeeId}', MeetingController::class, 'removeParticipant', 'meetings:invite');
// Confirm/decline are the invitee's own attendance response (any invited
// employee); marking attendance for others is an organiser capability.
$router->add('POST', '/meetings/{id}/confirm', MeetingController::class, 'confirm', 'meetings:confirm');
$router->add('POST', '/meetings/{id}/decline', MeetingController::class, 'decline', 'meetings:confirm');
$router->add('POST', '/meetings/{id}/attendance', MeetingController::class, 'markAttendance', 'meetings:view_attendance');

// Meeting Minutes routes - structured minutes management.
// Reads: meetings:view (viewing refined per-user in MeetingMinutesService:
// managers see drafts, confirmed invitees see published minutes).
// Writes: meetings:manage (refined per-user by the service as well).
$router->add('GET',  '/meetings/{id}/minutes/status',  MeetingMinutesController::class, 'status', 'meetings:view');
$router->add('GET',  '/meetings/{id}/minutes/options', MeetingMinutesController::class, 'options', 'meetings:manage');
$router->add('POST', '/meetings/{id}/minutes',           MeetingMinutesController::class, 'create', 'meetings:manage');
$router->add('GET',  '/meetings/{id}/minutes',           MeetingMinutesController::class, 'view', 'meetings:view');
$router->add('PUT',  '/meetings/{id}/minutes',           MeetingMinutesController::class, 'update', 'meetings:manage');
$router->add('POST', '/meetings/{id}/minutes/publish',   MeetingMinutesController::class, 'publish', 'meetings:manage');
$router->add('POST', '/meetings/{id}/minutes/reopen',    MeetingMinutesController::class, 'reopen', 'meetings:manage');

// Notification preferences (own settings)
$router->add('GET', '/notification-preferences', \App\Controllers\Notifications\NotificationPreferencesController::class, 'index');
$router->add('PUT', '/notification-preferences', \App\Controllers\Notifications\NotificationPreferencesController::class, 'update');

// Web Push subscriptions
$router->add('GET', '/push/vapid-public-key', \App\Controllers\Notifications\PushSubscriptionController::class, 'vapidPublicKey');
$router->add('GET', '/push/subscriptions', \App\Controllers\Notifications\PushSubscriptionController::class, 'index');
$router->add('POST', '/push/subscribe', \App\Controllers\Notifications\PushSubscriptionController::class, 'subscribe');
$router->add('DELETE', '/push/subscribe', \App\Controllers\Notifications\PushSubscriptionController::class, 'unsubscribe');

// Admin / HR notification visibility + controlled test sending
$router->add('GET', '/admin/notifications/stats', \App\Controllers\Notifications\AdminNotificationController::class, 'stats', 'notifications:view');
$router->add('GET', '/admin/notifications/audit/{employeeId}', \App\Controllers\Notifications\AdminNotificationController::class, 'audit', 'notifications:view');
$router->add('POST', '/admin/notifications/test-send', \App\Controllers\Notifications\NotificationTestController::class, 'send', 'notifications:manage');

// ===========================================================================
// System Monitoring / Error Tracking (RBAC module: system_errors)
// Correlates with audit_logs via request_id. Literal paths MUST be
// registered before the {uuid} wildcard route.
// ===========================================================================
$router->add('GET',  '/system/errors/stats',              \App\Controllers\System\MonitoringController::class, 'stats', 'system_errors:view');
$router->add('GET',  '/system/errors/groups',             \App\Controllers\System\MonitoringController::class, 'groups', 'system_errors:view');
$router->add('POST', '/system/errors/groups/{id}/manage', \App\Controllers\System\MonitoringController::class, 'manage', 'system_errors:manage');
$router->add('GET',  '/system/errors/{uuid}',             \App\Controllers\System\MonitoringController::class, 'show', 'system_errors:view');
// Pre-login browser error collector - PUBLIC (see AuthenticationMiddleware).
$router->add('POST', '/system/client-errors',             \App\Controllers\System\MonitoringController::class, 'clientError');
$router->add('GET',  '/system/performance',               \App\Controllers\System\MonitoringController::class, 'performance', 'system_errors:view');
$router->add('GET',  '/system/health',                    \App\Controllers\System\MonitoringController::class, 'health', 'system_errors:view');

$router->dispatch();

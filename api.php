<?php

declare(strict_types=1);

// Set CORS headers for all requests (required for both preflight and actual requests)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://localhost',
];

if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CORS preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load the application bootstrap
require_once __DIR__ . '/backend/bootstrap.php';

use App\Controllers\Auth\AuthController;
use App\Controllers\Employee\EmployeeController;
use App\Controllers\HR\DepartmentController;
use App\Controllers\Leave\LeaveController;
use App\Controllers\Leave\LeaveRosterController;
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
use App\Controllers\Reports\ReportsController as ReportController;
use App\Controllers\HR\AppraisalController;
use App\Controllers\HR\StrategicPlanController;
use App\Controllers\HR\WorkplanController;
use App\Controllers\HR\KPIController;
use App\Controllers\HR\PayrollController;
use App\Controllers\HR\ComplaintController;
use App\Controllers\Settings\SettingController;

/**
 * Simple Router
 */
class ApiRouter
{
    private array $routes = [];

    public function add(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'controller' => $controller,
            'action' => $action,
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
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => "Method {$action} not found on " . $controllerClass]);
                }
                return;
            }
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found', 'path' => $path]);
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

// Holiday routes
$router->add('GET', '/holidays', HolidayController::class, 'index');
$router->add('GET', '/holidays/upcoming', HolidayController::class, 'upcoming');
$router->add('GET', '/holidays/{id}', HolidayController::class, 'show');
$router->add('POST', '/holidays', HolidayController::class, 'store');
$router->add('PUT', '/holidays/{id}', HolidayController::class, 'update');
$router->add('DELETE', '/holidays/{id}', HolidayController::class, 'destroy');

// Employee routes
$router->add('GET', '/employees/search', EmployeeController::class, 'search');
$router->add('GET', '/employees', EmployeeController::class, 'index');
$router->add('POST', '/employees', EmployeeController::class, 'store');
$router->add('GET', '/employees/reference', EmployeeController::class, 'reference');
$router->add('GET', '/employees/{id}', EmployeeController::class, 'show');
$router->add('PUT', '/employees/{id}', EmployeeController::class, 'update');
$router->add('DELETE', '/employees/{id}', EmployeeController::class, 'destroy');

// Department routes
$router->add('GET', '/departments', DepartmentController::class, 'index');
$router->add('POST', '/departments', DepartmentController::class, 'store');
$router->add('GET', '/departments/{id}', DepartmentController::class, 'show');
$router->add('PUT', '/departments/{id}', DepartmentController::class, 'update');
$router->add('DELETE', '/departments/{id}', DepartmentController::class, 'destroy');

// Section routes
$router->add('GET', '/sections', SectionController::class, 'index');
$router->add('POST', '/sections', SectionController::class, 'store');
$router->add('GET', '/sections/{id}', SectionController::class, 'show');
$router->add('PUT', '/sections/{id}', SectionController::class, 'update');
$router->add('DELETE', '/sections/{id}', SectionController::class, 'destroy');

// Subsection routes
$router->add('GET', '/subsections', SubsectionController::class, 'index');
$router->add('POST', '/subsections', SubsectionController::class, 'store');
$router->add('GET', '/subsections/{id}', SubsectionController::class, 'show');
$router->add('PUT', '/subsections/{id}', SubsectionController::class, 'update');
$router->add('DELETE', '/subsections/{id}', SubsectionController::class, 'destroy');

// User routes
$router->add('GET', '/users', UserController::class, 'index');
$router->add('POST', '/users', UserController::class, 'store');
$router->add('GET', '/users/{id}', UserController::class, 'show');
$router->add('PUT', '/users/{id}', UserController::class, 'update');
$router->add('DELETE', '/users/{id}', UserController::class, 'destroy');
$router->add('PUT', '/users/{id}/toggle-status', UserController::class, 'toggleStatus');
$router->add('POST', '/users/{id}/change-password', UserController::class, 'changePassword');

// Attendance routes
$router->add('GET', '/attendance/today', AttendanceController::class, 'today');
$router->add('GET', '/attendance/dashboard', AttendanceController::class, 'dashboard');
// HR attendance monitoring dashboard + employee history (before /attendance/{id} wildcard)
$router->add('GET', '/attendance/hr-dashboard', AttendanceController::class, 'hrDashboard');
$router->add('GET', '/attendance/hr-employee-history', AttendanceController::class, 'hrEmployeeHistory');
$router->add('GET', '/attendance/my-records', AttendanceController::class, 'myRecords');
$router->add('GET', '/attendance/employee/{id}', AttendanceController::class, 'byEmployee');
$router->add('POST', '/attendance/clock-in', AttendanceController::class, 'clockIn');
$router->add('POST', '/attendance/clock-out', AttendanceController::class, 'clockOut');
$router->add('POST', '/attendance/auto-clockout', AttendanceController::class, 'autoClockOut');
$router->add('GET', '/attendance', AttendanceController::class, 'index');
$router->add('POST', '/attendance', AttendanceController::class, 'store');
$router->add('GET', '/attendance/{id}', AttendanceController::class, 'show');
$router->add('PUT', '/attendance/{id}', AttendanceController::class, 'update');
$router->add('DELETE', '/attendance/{id}', AttendanceController::class, 'destroy');

// Leave routes - only methods that exist in LeaveController
$router->add('GET', '/leave', LeaveController::class, 'index');
$router->add('POST', '/leave/apply', LeaveController::class, 'apply');
$router->add('GET', '/leave/types', LeaveController::class, 'types');
$router->add('GET', '/leave/eligible-employees', LeaveController::class, 'eligibleEmployees');
$router->add('GET', '/leave/eligible-delegates', LeaveController::class, 'eligibleDelegates');
$router->add('GET', '/leave/manage', LeaveController::class, 'manage');
$router->add('GET', '/leave/delegates', LeaveController::class, 'delegates');
$router->add('GET', '/leave/calculate', LeaveController::class, 'calculate');
$router->add('GET', '/leave/{id}/documents', LeaveController::class, 'listDocuments');
$router->add('GET', '/leave/{id}/documents/{documentId}', LeaveController::class, 'viewDocument');
$router->add('PUT', '/leave/{id}/approve', LeaveController::class, 'approve');
$router->add('PUT', '/leave/{id}/reject', LeaveController::class, 'reject');
$router->add('PUT', '/leave/{id}/invalidate', LeaveController::class, 'invalidate');
$router->add('PUT', '/leave/{id}/cancel', LeaveController::class, 'cancel');

// Employee Leave Profile routes - must be registered before /leave/{id} wildcard routes
$router->add('GET', '/leave/profile/employees', LeaveController::class, 'profileEmployees');
$router->add('GET', '/leave/profile/{id}', LeaveController::class, 'profile');
$router->add('GET', '/leave/profile/{id}/balances', LeaveController::class, 'profileBalances');
$router->add('GET', '/leave/profile/{id}/applications', LeaveController::class, 'profileApplications');
$router->add('GET', '/leave/profile/{id}/timeline', LeaveController::class, 'profileTimeline');
$router->add('GET', '/leave/profile/{id}/summary', LeaveController::class, 'profileSummary');
$router->add('GET', '/leave/profile/{id}/export', LeaveController::class, 'profileExport');

// Leave Roster routes - static sub-resource routes must be registered before /leave/roster/{id}
$router->add('GET', '/leave/roster/stats', LeaveRosterController::class, 'stats');
$router->add('GET', '/leave/roster/distribution', LeaveRosterController::class, 'distribution');
$router->add('GET', '/leave/roster/upcoming', LeaveRosterController::class, 'upcoming');
$router->add('GET', '/leave/roster/departments', LeaveRosterController::class, 'departments');
$router->add('GET', '/leave/roster/matrix', LeaveRosterController::class, 'matrix');
$router->add('GET', '/leave/roster/export', LeaveRosterController::class, 'export');
$router->add('GET', '/leave/roster/employees', LeaveRosterController::class, 'employees');
$router->add('GET', '/leave/roster/financial-years', LeaveRosterController::class, 'financialYears');
$router->add('GET', '/leave/roster', LeaveRosterController::class, 'index');
$router->add('POST', '/leave/roster', LeaveRosterController::class, 'store');
$router->add('PUT', '/leave/roster/{id}', LeaveRosterController::class, 'update');
$router->add('DELETE', '/leave/roster/{id}', LeaveRosterController::class, 'destroy');

// Dashboard routes
$router->add('GET', '/dashboard', DashboardController::class, 'index');
$router->add('GET', '/dashboard/stats', DashboardController::class, 'stats');
$router->add('GET', '/dashboard/charts/attendance', DashboardController::class, 'chartsAttendance');
$router->add('GET', '/dashboard/charts/departments', DashboardController::class, 'chartsDepartments');
$router->add('GET', '/dashboard/charts/leave', DashboardController::class, 'chartsLeave');

// Report routes
$router->add('GET', '/reports/employees', ReportController::class, 'employees');
$router->add('GET', '/reports/leave', ReportController::class, 'leave');
$router->add('GET', '/reports/attendance', ReportController::class, 'attendance');
$router->add('GET', '/reports/appraisal', ReportController::class, 'appraisal');
$router->add('GET', '/reports/documentation', ReportController::class, 'documentation');
$router->add('GET', '/reports/{type}/export/{format}', ReportController::class, 'export');

// Payroll routes
$router->add('GET', '/payroll/periods', PayrollController::class, 'periods');
$router->add('POST', '/payroll/periods', PayrollController::class, 'storePeriod');
$router->add('GET', '/payroll/records', PayrollController::class, 'records');
$router->add('POST', '/payroll/records', PayrollController::class, 'storeRecord');

// Complaint routes
$router->add('GET', '/complaints', ComplaintController::class, 'index');
$router->add('POST', '/complaints', ComplaintController::class, 'store');
$router->add('PUT', '/complaints/{id}', ComplaintController::class, 'update');

// Consent routes
$router->add('GET', '/consents', ConsentController::class, 'index');
$router->add('PUT', '/consents/{id}', ConsentController::class, 'update');
$router->add('GET', '/consent/status', ConsentController::class, 'status');
$router->add('POST', '/consent/verify-employee', ConsentController::class, 'verifyEmployeeId');
$router->add('POST', '/consent', ConsentController::class, 'storeConsent');
$router->add('GET', '/consent/dashboard', ConsentController::class, 'dashboard');
$router->add('GET', '/consent/employees', ConsentController::class, 'employees');

// Financial Year routes
$router->add('GET', '/admin/financial-years', FinancialYearController::class, 'index');
$router->add('GET', '/admin/financial-years/status', FinancialYearController::class, 'status');
$router->add('POST', '/admin/financial-year/add', FinancialYearController::class, 'store');
$router->add('POST', '/admin/financial-year/allocate', FinancialYearController::class, 'allocateLeave');
$router->add('GET', '/admin/financial-years/leave-types', FinancialYearController::class, 'leaveTypes');
$router->add('GET', '/admin/financial-years/employees', FinancialYearController::class, 'employees');

// Appraisal routes
$router->add('GET', '/appraisals', AppraisalController::class, 'index');
$router->add('POST', '/appraisals', AppraisalController::class, 'store');
$router->add('GET', '/appraisals/{id}', AppraisalController::class, 'show');
$router->add('PUT', '/appraisals/{id}', AppraisalController::class, 'update');
$router->add('DELETE', '/appraisals/{id}', AppraisalController::class, 'destroy');
$router->add('GET', '/appraisals/pending', AppraisalController::class, 'pending');
$router->add('GET', '/appraisals/employee/{id}', AppraisalController::class, 'byEmployee');
$router->add('PUT', '/appraisals/{id}/submit', AppraisalController::class, 'submit');
$router->add('PUT', '/appraisals/{id}/approve', AppraisalController::class, 'approve');

// Strategic Plan routes
$router->add('GET',    '/strategic-plans',          StrategicPlanController::class, 'index');
$router->add('POST',   '/strategic-plans',          StrategicPlanController::class, 'store');
$router->add('GET',    '/strategic-plans/{id}',     StrategicPlanController::class, 'show');
$router->add('PUT',    '/strategic-plans/{id}',     StrategicPlanController::class, 'update');
$router->add('DELETE', '/strategic-plans/{id}',     StrategicPlanController::class, 'destroy');

// Workplan routes
$router->add('GET',    '/strategic-plans/{id}/workplans', WorkplanController::class, 'index');
$router->add('POST',   '/strategic-plans/{id}/workplans', WorkplanController::class, 'store');
$router->add('PUT',    '/workplans/{id}',            WorkplanController::class, 'update');
$router->add('DELETE', '/workplans/{id}',            WorkplanController::class, 'destroy');

// KPI routes
$router->add('GET',    '/workplans/{id}/kpis',       KPIController::class, 'index');
$router->add('POST',   '/workplans/{id}/kpis',       KPIController::class, 'store');
$router->add('PUT',    '/kpis/{id}',                 KPIController::class, 'update');
$router->add('DELETE', '/kpis/{id}',                 KPIController::class, 'destroy');

// Payroll routes
$router->add('GET',    '/payroll/periods',           PayrollController::class, 'periods');
$router->add('POST',   '/payroll/periods',           PayrollController::class, 'storePeriod');
$router->add('GET',    '/payroll/records',           PayrollController::class, 'records');
$router->add('POST',   '/payroll/records',           PayrollController::class, 'storeRecord');

// Complaint routes
$router->add('GET',    '/complaints',                ComplaintController::class, 'index');
$router->add('POST',   '/complaints',                ComplaintController::class, 'store');
$router->add('PUT',    '/complaints/{id}',           ComplaintController::class, 'update');


// Notification routes
$router->add('GET', '/notifications', NotificationController::class, 'index');
$router->add('POST', '/notifications/{id}/read', NotificationController::class, 'markAsRead');
$router->add('POST', '/notifications/read-all', NotificationController::class, 'markAllAsRead');

// Audit routes - plain method names
$router->add('GET', '/audit', AuditLogController::class, 'index');
$router->add('GET', '/audit/statistics', AuditLogController::class, 'statistics');
$router->add('GET', '/audit/filters', AuditLogController::class, 'filters');
$router->add('GET', '/audit/export', AuditLogController::class, 'export');
$router->add('GET', '/audit/{id}', AuditLogController::class, 'show');
$router->add('GET', '/audit-logs', AuditLogController::class, 'index');

// Settings routes
$router->add('GET', '/settings', SettingController::class, 'index');
$router->add('PUT', '/settings', SettingController::class, 'update');

// Profile routes
$router->add('GET', '/profile', EmployeeController::class, 'profile');
$router->add('PUT', '/profile', EmployeeController::class, 'updateProfile');
$router->add('POST', '/profile/documents', EmployeeController::class, 'uploadProfileDocument');
$router->add('GET', '/profile/documents/{id}', EmployeeController::class, 'viewProfileDocument');
$router->add('GET', '/profile/documents/{id}/view', EmployeeController::class, 'viewProfileDocument');
$router->add('DELETE', '/profile/documents/{id}', EmployeeController::class, 'deleteProfileDocument');

// Profile picture routes
$router->add('POST', '/profile/profile-image', EmployeeController::class, 'uploadProfileImage');
$router->add('GET', '/profile/profile-image', EmployeeController::class, 'profileImage');
$router->add('POST', '/employees/{id}/profile-image', EmployeeController::class, 'uploadEmployeeProfileImage');
$router->add('GET', '/employees/{id}/profile-image', EmployeeController::class, 'employeeProfileImage');

// Permission routes - plain method names
$router->add('GET', '/permissions/catalog', PermissionController::class, 'catalog');
$router->add('GET', '/permissions/statistics', PermissionController::class, 'statistics');
$router->add('GET', '/permissions/roles', PermissionController::class, 'roles');
$router->add('GET', '/permissions/users', PermissionController::class, 'users');
$router->add('GET', '/permissions/users/{id}', PermissionController::class, 'userPermissions');
$router->add('GET', '/permissions/overrides', PermissionController::class, 'overrides');
$router->add('POST', '/permissions/users/{id}/overrides', PermissionController::class, 'setOverride');
$router->add('DELETE', '/permissions/users/{id}/overrides', PermissionController::class, 'removeOverride');

// Meeting routes - Laravel-style methods
$router->add('GET', '/my-meetings', MeetingController::class, 'myMeetings');
$router->add('GET', '/meetings', MeetingController::class, 'index');
$router->add('GET', '/meetings/stats', MeetingController::class, 'stats');
$router->add('GET', '/meetings/eligible-employees', MeetingController::class, 'eligibleEmployees');
$router->add('POST', '/meetings', MeetingController::class, 'store');
$router->add('GET', '/meetings/{id}', MeetingController::class, 'show');
$router->add('PUT', '/meetings/{id}', MeetingController::class, 'update');
$router->add('DELETE', '/meetings/{id}', MeetingController::class, 'destroy');
$router->add('POST', '/meetings/{id}/cancel', MeetingController::class, 'cancel');
$router->add('GET', '/meetings/{id}/participants', MeetingController::class, 'participants');
$router->add('POST', '/meetings/{id}/participants', MeetingController::class, 'addParticipant');
$router->add('DELETE', '/meetings/{id}/participants/{employeeId}', MeetingController::class, 'removeParticipant');
$router->add('POST', '/meetings/{id}/confirm', MeetingController::class, 'confirm');
$router->add('POST', '/meetings/{id}/decline', MeetingController::class, 'decline');
$router->add('POST', '/meetings/{id}/attendance', MeetingController::class, 'markAttendance');

// Notification preferences (own settings)
$router->add('GET', '/notification-preferences', \App\Controllers\Notifications\NotificationPreferencesController::class, 'index');
$router->add('PUT', '/notification-preferences', \App\Controllers\Notifications\NotificationPreferencesController::class, 'update');

// Web Push subscriptions
$router->add('GET', '/push/vapid-public-key', \App\Controllers\Notifications\PushSubscriptionController::class, 'vapidPublicKey');
$router->add('GET', '/push/subscriptions', \App\Controllers\Notifications\PushSubscriptionController::class, 'index');
$router->add('POST', '/push/subscribe', \App\Controllers\Notifications\PushSubscriptionController::class, 'subscribe');
$router->add('DELETE', '/push/subscribe', \App\Controllers\Notifications\PushSubscriptionController::class, 'unsubscribe');

// Admin / HR notification visibility + controlled test sending
$router->add('GET', '/admin/notifications/stats', \App\Controllers\Notifications\AdminNotificationController::class, 'stats');
$router->add('GET', '/admin/notifications/audit/{employeeId}', \App\Controllers\Notifications\AdminNotificationController::class, 'audit');
$router->add('POST', '/admin/notifications/test-send', \App\Controllers\Notifications\NotificationTestController::class, 'send');

$router->dispatch();

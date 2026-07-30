<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\Auth\LoginController;
use App\Controllers\Dashboard\DashboardController;
use App\Controllers\EmployeesController;
use App\Controllers\DepartmentsController;
use App\Controllers\AttendanceController;
use App\Controllers\Leave\ApplyLeaveController;
use App\Controllers\Leave\ManageLeaveController;
use App\Controllers\Leave\LeaveHistoryController;
use App\Controllers\Leave\HolidaysController;
use App\Controllers\Leave\LeaveProfileController;
use App\Controllers\Reports\EmployeeReportsController;
use App\Controllers\Reports\AttendanceReportsController;
use App\Controllers\Reports\LeaveReportsController;
use App\Controllers\Reports\AppraisalReportsController;
use App\Controllers\Reports\DocumentationReportsController;
use App\Controllers\AdminController;
use App\Controllers\UsersController;
use App\Controllers\AuditController;
use App\Controllers\ConsentController;
use App\Controllers\PersonalProfileController;
use App\Controllers\Appraisal\EmployeeAppraisalController;
use App\Controllers\Appraisal\PerformanceAppraisalController;
use App\Controllers\Appraisal\AppraisalManagementController;
use App\Controllers\Appraisal\CompletedAppraisalController;
use App\Controllers\StrategicPlan\StrategicPlanController;
use App\Controllers\StrategicPlan\WorkplansController;
use App\Controllers\StrategicPlan\KpiController;
use App\Controllers\StrategicPlan\ReportsController;
use App\Controllers\Attendance\AttendanceDashboardController;
use App\Controllers\Attendance\AttendanceProfileController;
use App\Controllers\PermissionOverridesController;

/**
 * Core Application Class
 * Handles routing and request dispatching
 */
class Application
{
    /**
     * Route the incoming request to the appropriate controller
     */
    public function route(string $uri, string $method): void
    {
        // Normalize via the shared helper (strips query string, subdir prefix, leading slash).
        $uri = Request::normalizeUri();
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // Route mapping
        $routes = [
            // Authentication
            'login' => [LoginController::class, 'indexAction'],
            'login/authenticate' => [LoginController::class, 'authenticateAction'],
            'login/consent' => [ConsentController::class, 'loginConsentAction'],
            'consent/submit' => [ConsentController::class, 'submitConsentAction'],
            'auth/login' => [LoginController::class, 'indexAction'],
            'auth/logout' => [LoginController::class, 'logoutAction'],
            'auth/consent' => [ConsentController::class, 'loginConsentAction'],
            
            // Dashboard
            'dashboard' => [DashboardController::class, 'indexAction'],
            
            // Employees
            'employees' => [EmployeesController::class, 'indexAction'],
            'employees/create' => [EmployeesController::class, 'createAction'],
            'employees/store' => [EmployeesController::class, 'storeAction'],
            'employees/edit' => [EmployeesController::class, 'editAction'],
            'employees/update' => [EmployeesController::class, 'updateAction'],
            'employees/delete' => [EmployeesController::class, 'deleteAction'],
            
            // Departments
            'departments' => [DepartmentsController::class, 'indexAction'],
            
            // Attendance
            'attendance' => [AttendanceController::class, 'indexAction'],
            'attendance/dashboard' => [AttendanceDashboardController::class, 'indexAction'],
            'attendance/profile' => [AttendanceProfileController::class, 'indexAction'],
            
            // Leave Management
            'leave/apply' => [ApplyLeaveController::class, 'indexAction'],
            'leave/manage' => [ManageLeaveController::class, 'indexAction'],
            'leave/history' => [LeaveHistoryController::class, 'indexAction'],
            'leave/holidays' => [HolidaysController::class, 'indexAction'],
            'leave/profile' => [LeaveProfileController::class, 'indexAction'],
            
            // Reports
            'reports/employees' => [EmployeeReportsController::class, 'indexAction'],
            'reports/attendance' => [AttendanceReportsController::class, 'indexAction'],
            'reports/leave' => [LeaveReportsController::class, 'indexAction'],
            'reports/appraisal' => [AppraisalReportsController::class, 'indexAction'],
            'reports/documentation' => [DocumentationReportsController::class, 'indexAction'],
            
            // Appraisal
            'appraisal/employee' => [EmployeeAppraisalController::class, 'indexAction'],
            'appraisal/performance' => [PerformanceAppraisalController::class, 'indexAction'],
            'appraisal/performance/escalated' => [PerformanceAppraisalController::class, 'escalatedAction'],
            'appraisal/performance/pending' => [PerformanceAppraisalController::class, 'pendingAction'],
            'appraisal/management' => [AppraisalManagementController::class, 'indexAction'],
            'appraisal/completed' => [CompletedAppraisalController::class, 'indexAction'],
            
            // Strategic Plan
            'strategic-plan' => [StrategicPlanController::class, 'indexAction'],
            'strategic-plan/workplans' => [WorkplansController::class, 'indexAction'],
            'strategic-plan/kpi' => [KpiController::class, 'indexAction'],
            'strategic-plan/reports' => [ReportsController::class, 'indexAction'],
            
            // Admin
            'admin' => [AdminController::class, 'indexAction'],
            'admin/permission-overrides' => [PermissionOverridesController::class, 'indexAction'],
            'admin/permission-overrides/manage' => [PermissionOverridesController::class, 'manageAction'],
            'admin/permission-overrides/save' => [PermissionOverridesController::class, 'saveAction'],
            'admin/permission-overrides/effective' => [PermissionOverridesController::class, 'effectivePermissionsAction'],
            'admin/permission-overrides/search' => [PermissionOverridesController::class, 'searchAction'],
            'consent-management' => [ConsentController::class, 'indexAction'],
            'consent-management/export' => [ConsentController::class, 'exportAction'],
            'users' => [UsersController::class, 'indexAction'],
            'audit' => [AuditController::class, 'indexAction'],
            
            // Profile
            'profile' => [PersonalProfileController::class, 'indexAction'],
            'personal' => [PersonalProfileController::class, 'indexAction'],
            
            // Leave (redirect to apply page)
            'leave' => [ApplyLeaveController::class, 'indexAction'],
        ];

        // Determine which route to use (query parameter takes priority for non-mod_rewrite)
        $route = $_GET['route'] ?? $uri;
        
        // Check if route exists in routes array
        if (isset($routes[$route])) {
            [$controllerClass, $action] = $routes[$route];
            $this->dispatch($controllerClass, $action);
            return;
        }

        // Default route
        if ($uri === '' || $uri === '/') {
            $this->redirectToLogin();
            return;
        }

        // Check for dynamic routes
        if (preg_match('#^employees/view/(\d+)$#', $route, $matches)) {
            $controller = new EmployeesController();
            $controller->viewAction((int)$matches[1]);
            return;
        }

        if (preg_match('#^employees/edit/(\d+)$#', $route, $matches)) {
            $controller = new EmployeesController();
            $controller->editAction((int)$matches[1]);
            return;
        }

        if (preg_match('#^departments/view/(\d+)$#', $route, $matches)) {
            $controller = new DepartmentsController();
            $controller->viewAction((int)$matches[1]);
            return;
        }

        if (preg_match('#^admin/permission-overrides/manage/(\d+)$#', $route, $matches)) {
            $controller = new PermissionOverridesController();
            $controller->manageAction((int)$matches[1]);
            return;
        }

        if (preg_match('#^admin/permission-overrides/save/(\d+)$#', $route, $matches)) {
            $controller = new PermissionOverridesController();
            $controller->saveAction((int)$matches[1]);
            return;
        }

        if (preg_match('#^admin/permission-overrides/effective/(\d+)$#', $route, $matches)) {
            $controller = new PermissionOverridesController();
            $controller->effectivePermissionsAction((int)$matches[1]);
            return;
        }

        if ($route === 'admin/permission-overrides/search') {
            $controller = new PermissionOverridesController();
            $controller->searchAction();
            return;
        }

        // 404 Not Found
        http_response_code(404);
        echo "404 - Page Not Found";
        exit();
    }

    /**
     * Dispatch request to controller action
     */
    private function dispatch(string $controllerClass, string $action): void
    {
        // Check if controller class exists
        if (!class_exists($controllerClass)) {
            http_response_code(500);
            echo "Controller not found: {$controllerClass}";
            exit();
        }

        $controller = new $controllerClass();

        // Check if action method exists
        if (!method_exists($controller, $action)) {
            http_response_code(500);
            echo "Action not found: {$action}";
            exit();
        }

        // Call the action
        $controller->$action();
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(): void
    {
        // Detect the subdirectory dynamically
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = rtrim(dirname($scriptName), '/');
        
        // Build the redirect URL
        if ($scriptDir !== '/' && $scriptDir !== '') {
            $loginUrl = $scriptDir . '/login';
        } else {
            $loginUrl = '/login';
        }
        
        header('Location: ' . $loginUrl);
        exit();
    }
}
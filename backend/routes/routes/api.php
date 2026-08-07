<?php

use Illuminate\Support\Facades\Route;
use App\Controllers\AuthController;
use App\Controllers\EmployeeController;
use App\Controllers\DepartmentController;
use App\Controllers\LeaveController;
use App\Controllers\AttendanceController;
use App\Controllers\UserController;
use App\Controllers\SectionController;
use App\Controllers\SubsectionController;
use App\Controllers\DashboardController;
use App\Controllers\ReportController;
use App\Controllers\PayrollController;
use App\Controllers\ConsentController;
use App\Controllers\FinancialYearController;
use App\Controllers\ComplaintController;
use App\Controllers\AppraisalController;
use App\Controllers\StrategicPlanController;
use App\Controllers\WorkplanController;
use App\Controllers\KPIController;
use App\Controllers\NotificationController;
use App\Controllers\AuditLogController;
use App\Controllers\SettingController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['jwt.auth'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    // Employee routes - search must be before apiResource
    Route::get('employees/search', [EmployeeController::class, 'search']);
    Route::apiResource('employees', EmployeeController::class);

    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('sections', SectionController::class);
    Route::apiResource('users', UserController::class);

    // Attendance routes - today/employee/dashboard must be before apiResource's {attendance}
    Route::get('/attendance/today', [AttendanceController::class, 'today']);
    Route::get('/attendance/dashboard', [AttendanceController::class, 'dashboard']);
    Route::get('/attendance/employee/{employeeId}', [AttendanceController::class, 'byEmployee']);
    Route::apiResource('attendance', AttendanceController::class);

    // Leave routes - apply/types/pending/holidays must be before apiResource's {leaveApplication}
    Route::get('/leave/types', [LeaveController::class, 'types']);
    Route::get('/leave/holidays', [LeaveController::class, 'holidays']);
    Route::get('/leave/pending', [LeaveController::class, 'pending']);
    Route::get('/leave/balance/{employeeId}', [LeaveController::class, 'balance']);
    Route::get('/leave/employee/{employeeId}', [LeaveController::class, 'byEmployee']);
    Route::post('/leave/apply', [LeaveController::class, 'apply']);
    Route::apiResource('leave', LeaveController::class);
    Route::put('/leave/{leaveApplication}/approve', [LeaveController::class, 'approve']);
    Route::put('/leave/{leaveApplication}/reject', [LeaveController::class, 'reject']);
    Route::put('/leave/{leaveApplication}/cancel', [LeaveController::class, 'cancel']);

    Route::put('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::post('/users/{user}/change-password', [UserController::class, 'changePassword']);

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/charts/attendance', [DashboardController::class, 'chartsAttendance']);
    Route::get('/dashboard/charts/departments', [DashboardController::class, 'chartsDepartments']);
    Route::get('/dashboard/charts/leave', [DashboardController::class, 'chartsLeave']);

    Route::get('/reports/employees', [ReportController::class, 'employees']);
    Route::get('/reports/leave', [ReportController::class, 'leave']);
    Route::get('/reports/attendance', [ReportController::class, 'attendance']);
    Route::get('/reports/appraisal', [ReportController::class, 'appraisal']);
    Route::get('/reports/documentation', [ReportController::class, 'documentation']);
    Route::get('/reports/{type}/export/{format}', [ReportController::class, 'export']);

    Route::get('/payroll/periods', [PayrollController::class, 'periods']);
    Route::post('/payroll/periods', [PayrollController::class, 'storePeriod']);
    Route::get('/payroll/records', [PayrollController::class, 'records']);
    Route::post('/payroll/records', [PayrollController::class, 'storeRecord']);

    Route::get('/complaints', [ComplaintController::class, 'index']);
    Route::post('/complaints', [ComplaintController::class, 'store']);
    Route::put('/complaints/{complaint}', [ComplaintController::class, 'update']);

    Route::get('/consents', [ConsentController::class, 'index']);
    Route::put('/consents/{consent}', [ConsentController::class, 'update']);

    Route::get('/admin/financial-years', [FinancialYearController::class, 'index']);
    Route::post('/admin/financial-year/add', [FinancialYearController::class, 'store']);

    // Appraisal routes - submit/approve/pending/employee must be before apiResource's {appraisal}
    Route::get('/appraisals/pending', [AppraisalController::class, 'pending']);
    Route::get('/appraisals/employee/{employeeId}', [AppraisalController::class, 'byEmployee']);
    Route::put('/appraisals/{appraisal}/submit', [AppraisalController::class, 'submit']);
    Route::put('/appraisals/{appraisal}/approve', [AppraisalController::class, 'approve']);
    Route::apiResource('appraisals', AppraisalController::class);

    // Strategic plan nested routes - workplans and KPIs
    Route::get('/strategic-plans/{plan}/workplans', [WorkplanController::class, 'index']);
    Route::post('/strategic-plans/{plan}/workplans', [WorkplanController::class, 'store']);
    Route::get('/workplans/{workplan}/kpis', [KPIController::class, 'index']);
    Route::post('/workplans/{workplan}/kpis', [KPIController::class, 'store']);
    Route::put('/kpis/{kpi}', [KPIController::class, 'update']);
    Route::get('/strategic-plans', [StrategicPlanController::class, 'index']);
    Route::post('/strategic-plans', [StrategicPlanController::class, 'store']);
    Route::put('/strategic-plans/{plan}', [StrategicPlanController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);

    // Profile routes
    Route::get('/profile', [EmployeeController::class, 'profileAction']);
    Route::put('/profile', [EmployeeController::class, 'updateProfileAction']);
    Route::post('/profile/documents', [EmployeeController::class, 'uploadProfileDocumentAction']);
    Route::delete('/profile/documents/{documentId}', [EmployeeController::class, 'deleteProfileDocumentAction']);
});

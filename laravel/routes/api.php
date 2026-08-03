<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\AppraisalController;
use App\Http\Controllers\Api\StrategicPlanController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\SettingController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['jwt.auth'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::get('/auth/profile', [AuthController::class, 'user']);

    Route::apiResource('employees', EmployeeController::class);
    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('leave', LeaveController::class);
    Route::post('/leave/{leaveApplication}/approve', [LeaveController::class, 'approve']);
    Route::post('/leave/{leaveApplication}/reject', [LeaveController::class, 'reject']);
    Route::apiResource('attendance', AttendanceController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('sections', SectionController::class);

    Route::post('/users/{user}/change-password', [UserController::class, 'changePassword']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/reports/employees', [ReportController::class, 'employees']);
    Route::get('/reports/leave', [ReportController::class, 'leave']);
    Route::get('/reports/attendance', [ReportController::class, 'attendance']);

    Route::get('/payroll/periods', [PayrollController::class, 'periods']);
    Route::post('/payroll/periods', [PayrollController::class, 'storePeriod']);
    Route::get('/payroll/records', [PayrollController::class, 'records']);
    Route::post('/payroll/records', [PayrollController::class, 'storeRecord']);

    Route::get('/complaints', [ComplaintController::class, 'index']);
    Route::post('/complaints', [ComplaintController::class, 'store']);
    Route::put('/complaints/{complaint}', [ComplaintController::class, 'update']);

    Route::get('/appraisals', [AppraisalController::class, 'index']);
    Route::post('/appraisals', [AppraisalController::class, 'store']);
    Route::put('/appraisals/{appraisal}', [AppraisalController::class, 'update']);

    Route::get('/strategic-plans', [StrategicPlanController::class, 'index']);
    Route::post('/strategic-plans', [StrategicPlanController::class, 'store']);
    Route::put('/strategic-plans/{plan}', [StrategicPlanController::class, 'update']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/settings', [SettingController::class, 'index']);
    Route::put('/settings', [SettingController::class, 'update']);
});
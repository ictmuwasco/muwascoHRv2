<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SectionController;

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
});
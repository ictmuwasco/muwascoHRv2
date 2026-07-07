<?php

declare(strict_types=1);

 

return [
    // Authentication Routes
    'POST' => [
        '/api/auth/login' => ['controller' => 'Auth\LoginController', 'method' => 'login', 'auth' => false],
        '/api/auth/logout' => ['controller' => 'Auth\LoginController', 'method' => 'logout', 'auth' => true],
        '/api/auth/refresh' => ['controller' => 'Auth\LoginController', 'method' => 'refresh', 'auth' => false],
        '/api/auth/change-password' => ['controller' => 'Auth\LoginController', 'method' => 'changePassword', 'auth' => true],
        
        // Employee Routes
        '/api/employees' => ['controller' => 'EmployeeController', 'method' => 'store', 'auth' => true],
        '/api/employees/import' => ['controller' => 'EmployeeController', 'method' => 'import', 'auth' => true],
        
        // Attendance Routes
        '/api/attendance/clock-in' => ['controller' => 'AttendanceController', 'method' => 'clockIn', 'auth' => true],
        '/api/attendance/clock-out' => ['controller' => 'AttendanceController', 'method' => 'clockOut', 'auth' => true],
        
        // Leave Routes
        '/api/leaves' => ['controller' => 'LeaveController', 'method' => 'store', 'auth' => true],
        '/api/leaves/{id}/approve' => ['controller' => 'LeaveController', 'method' => 'approve', 'auth' => true],
        '/api/leaves/{id}/reject' => ['controller' => 'LeaveController', 'method' => 'reject', 'auth' => true],
        
        // Complaint Routes
        '/api/complaints' => ['controller' => 'ComplaintController', 'method' => 'store', 'auth' => true],
        '/api/complaints/{id}/assign' => ['controller' => 'ComplaintController', 'method' => 'assign', 'auth' => true],
        '/api/complaints/{id}/resolve' => ['controller' => 'ComplaintController', 'method' => 'resolve', 'auth' => true],
        
        // Payroll Routes
        '/api/payroll/process' => ['controller' => 'PayrollController', 'method' => 'process', 'auth' => true],
        '/api/payroll/release' => ['controller' => 'PayrollController', 'method' => 'release', 'auth' => true],
        
        // Appraisal Routes
        '/api/appraisals' => ['controller' => 'AppraisalController', 'method' => 'store', 'auth' => true],
        '/api/appraisals/{id}/submit' => ['controller' => 'AppraisalController', 'method' => 'submit', 'auth' => true],
        
        // Notification Routes
        '/api/notifications/mark-read' => ['controller' => 'NotificationController', 'method' => 'markRead', 'auth' => true],
        '/api/notifications/mark-all-read' => ['controller' => 'NotificationController', 'method' => 'markAllRead', 'auth' => true],
        
        // User Management Routes
        '/api/users' => ['controller' => 'UserController', 'method' => 'store', 'auth' => true],
        
        // Theme Routes
        '/api/theme' => ['controller' => 'ThemeController', 'method' => 'update', 'auth' => true],
    ],
    
    'GET' => [
        // Authentication Routes
        '/api/auth/logout' => ['controller' => 'Auth\LoginController', 'method' => 'logout', 'auth' => true],
        
        // Dashboard Routes
        '/api/dashboard/stats' => ['controller' => 'DashboardController', 'method' => 'stats', 'auth' => true],
        '/api/dashboard/attendance-today' => ['controller' => 'DashboardController', 'method' => 'attendanceToday', 'auth' => true],
        '/api/dashboard/pending-leaves' => ['controller' => 'DashboardController', 'method' => 'pendingLeaves', 'auth' => true],
        '/api/dashboard/recent-complaints' => ['controller' => 'DashboardController', 'method' => 'recentComplaints', 'auth' => true],
        '/api/dashboard/employee-count' => ['controller' => 'DashboardController', 'method' => 'employeeCount', 'auth' => true],
        '/api/dashboard/notifications' => ['controller' => 'DashboardController', 'method' => 'notifications', 'auth' => true],
        
        // Chart Routes
        '/api/charts/employee-distribution' => ['controller' => 'Dashboard\\ChartsController', 'method' => 'employeeDistribution', 'auth' => true],
        '/api/charts/sections-per-dept' => ['controller' => 'Dashboard\\ChartsController', 'method' => 'sectionsPerDept', 'auth' => true],
        '/api/charts/leave-stats' => ['controller' => 'Dashboard\\ChartsController', 'method' => 'leaveStats', 'auth' => true],
        '/api/charts/appraisal-completion' => ['controller' => 'Dashboard\\ChartsController', 'method' => 'appraisalCompletion', 'auth' => true],
        
        // Employee Routes
        '/api/employees' => ['controller' => 'EmployeeController', 'method' => 'index', 'auth' => true],
        '/api/employees/{id}' => ['controller' => 'EmployeeController', 'method' => 'show', 'auth' => true],
        '/api/employees/search' => ['controller' => 'EmployeeController', 'method' => 'search', 'auth' => true],
        '/api/employees/reference' => ['controller' => 'EmployeeController', 'method' => 'reference', 'auth' => true],
        '/api/employees/{id}/attendance' => ['controller' => 'EmployeeController', 'method' => 'attendance', 'auth' => true],
        
        // Attendance Routes
        '/api/attendance' => ['controller' => 'AttendanceController', 'method' => 'index', 'auth' => true],
        '/api/attendance/today' => ['controller' => 'AttendanceController', 'method' => 'today', 'auth' => true],
        '/api/attendance/report' => ['controller' => 'AttendanceController', 'method' => 'report', 'auth' => true],
        
        // Leave Routes
        '/api/leaves' => ['controller' => 'LeaveController', 'method' => 'index', 'auth' => true],
        '/api/leaves/{id}' => ['controller' => 'LeaveController', 'method' => 'show', 'auth' => true],
        '/api/leaves/balances' => ['controller' => 'LeaveController', 'method' => 'balances', 'auth' => true],
        '/api/leaves/types' => ['controller' => 'LeaveController', 'method' => 'types', 'auth' => true],
        
        // Complaint Routes
        '/api/complaints' => ['controller' => 'ComplaintController', 'method' => 'index', 'auth' => true],
        '/api/complaints/{id}' => ['controller' => 'ComplaintController', 'method' => 'show', 'auth' => true],
        '/api/complaints/categories' => ['controller' => 'ComplaintController', 'method' => 'categories', 'auth' => true],
        
        // Payroll Routes
        '/api/payroll' => ['controller' => 'PayrollController', 'method' => 'index', 'auth' => true],
        '/api/payroll/{id}' => ['controller' => 'PayrollController', 'method' => 'show', 'auth' => true],
        '/api/payroll/history' => ['controller' => 'PayrollController', 'method' => 'history', 'auth' => true],
        
        // Appraisal Routes
        '/api/appraisals' => ['controller' => 'AppraisalController', 'method' => 'index', 'auth' => true],
        '/api/appraisals/{id}' => ['controller' => 'AppraisalController', 'method' => 'show', 'auth' => true],
        '/api/appraisals/cycles' => ['controller' => 'AppraisalController', 'method' => 'cycles', 'auth' => true],
        
        // Report Routes
        '/api/reports/attendance' => ['controller' => 'ReportController', 'method' => 'attendance', 'auth' => true],
        '/api/reports/leave' => ['controller' => 'ReportController', 'method' => 'leave', 'auth' => true],
        '/api/reports/payroll' => ['controller' => 'ReportController', 'method' => 'payroll', 'auth' => true],
        '/api/reports/employee' => ['controller' => 'ReportController', 'method' => 'employee', 'auth' => true],
        
        // Audit Routes
        '/api/audit/logs' => ['controller' => 'AuditController', 'method' => 'index', 'auth' => true],
        '/api/audit/logs/{id}' => ['controller' => 'AuditController', 'method' => 'show', 'auth' => true],
        
        // Notification Routes
        '/api/notifications' => ['controller' => 'NotificationController', 'method' => 'index', 'auth' => true],
        '/api/notifications/unread-count' => ['controller' => 'NotificationController', 'method' => 'unreadCount', 'auth' => true],
        
        // User Management Routes
        '/api/users' => ['controller' => 'UserController', 'method' => 'index', 'auth' => true],
        '/api/users/{id}' => ['controller' => 'UserController', 'method' => 'show', 'auth' => true],
        '/api/users/profile' => ['controller' => 'UserController', 'method' => 'profile', 'auth' => true],
        
        // RBAC Routes
        '/api/roles' => ['controller' => 'RoleController', 'method' => 'index', 'auth' => true],
        '/api/roles/{role}/permissions' => ['controller' => 'RoleController', 'method' => 'permissions', 'auth' => true],
        '/api/modules' => ['controller' => 'RoleController', 'method' => 'modules', 'auth' => true],
        '/api/actions' => ['controller' => 'RoleController', 'method' => 'actions', 'auth' => true],
        
        // Settings Routes
        '/api/settings' => ['controller' => 'SettingsController', 'method' => 'index', 'auth' => true],
    ],
    
    'PUT' => [
        '/api/employees/{id}' => ['controller' => 'EmployeeController', 'method' => 'update', 'auth' => true],
        '/api/users/{id}' => ['controller' => 'UserController', 'method' => 'update', 'auth' => true],
        '/api/settings' => ['controller' => 'SettingsController', 'method' => 'update', 'auth' => true],
        '/api/roles/{role}/permissions' => ['controller' => 'RoleController', 'method' => 'updatePermissions', 'auth' => true],
    ],
    
    'DELETE' => [
        '/api/employees/{id}' => ['controller' => 'EmployeeController', 'method' => 'destroy', 'auth' => true],
        '/api/leaves/{id}' => ['controller' => 'LeaveController', 'method' => 'destroy', 'auth' => true],
        '/api/complaints/{id}' => ['controller' => 'ComplaintController', 'method' => 'destroy', 'auth' => true],
        '/api/users/{id}' => ['controller' => 'UserController', 'method' => 'destroy', 'auth' => true],
    ],
];
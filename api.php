<?php

declare(strict_types=1);

// API Entry Point
// This file handles all API requests

require_once __DIR__ . '/backend/bootstrap.php';

// Apply security headers, session timeout, and proxy normalization
\App\Middleware\SecurityMiddleware::run();

// Handle CORS for credentialed requests
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'http://localhost:5173',  // Vite dev server
    'http://localhost:3000',  // Alternative dev port
    'http://localhost',       // Production
];

if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Remove /hrdemo prefix if present
if (strpos($requestPath, '/hrdemo') === 0) {
    $requestPath = substr($requestPath, 7);
}

// Remove /api prefix to get the endpoint
$endpoint = preg_replace('#^/api#', '', $requestPath);

// Simple routing
try {
    // Public routes that do not require authentication
    $isPublicRoute = str_starts_with($endpoint, '/auth')
        || str_starts_with($endpoint, '/holidays')
        || $endpoint === '/consent/verify-employee';

    // Enforce authentication for all non-public routes (F-04)
    if (!$isPublicRoute && !\App\Helpers\Auth::getInstance()->check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
        exit;
    }

    // Auth routes
    if (strpos($endpoint, '/auth') === 0) {
        require_once __DIR__ . '/backend/app/Controllers/AuthController.php';
        $controller = new \App\Controllers\AuthController();
        
        if ($endpoint === '/auth/login' && $requestMethod === 'POST') {
            $controller->loginAction();
        } elseif ($endpoint === '/auth/logout' && $requestMethod === 'POST') {
            $controller->logoutAction();
        } elseif ($endpoint === '/auth/refresh' && $requestMethod === 'POST') {
            $controller->refreshAction();
        } elseif ($endpoint === '/auth/me' && $requestMethod === 'GET') {
            $controller->meAction();
        } elseif ($endpoint === '/auth/change-password' && $requestMethod === 'POST') {
            $controller->changePasswordAction();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
    } 
    // Employee routes
    elseif ($endpoint === '/employees' || $endpoint === '/employees/') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        
        if ($requestMethod === 'GET') {
            $controller->indexAction();
        } elseif ($requestMethod === 'POST') {
            $controller->storeAction();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif ($endpoint === '/employees/reference' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->referenceAction();
    }
    elseif ($endpoint === '/employees/search' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->searchAction();
    }
    elseif (preg_match('#^/employees/(\d+)/profile$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $id = (int)$matches[1];
        
        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif ($endpoint === '/employees/documents' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->uploadDocumentAction();
    }
    elseif (preg_match('#^/employees/documents/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $docId = (int)$matches[1];
        
        if ($requestMethod === 'DELETE') {
            $controller->deleteDocumentAction($docId);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif (preg_match('#^/employees/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $id = (int)$matches[1];
        
        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    // Department routes
    elseif ($endpoint === '/departments' || $endpoint === '/departments/') {
        require_once __DIR__ . '/backend/app/Controllers/DepartmentController.php';
        $controller = new \App\Controllers\DepartmentController();
        
        if ($requestMethod === 'GET') {
            $controller->indexAction();
        } elseif ($requestMethod === 'POST') {
            $controller->storeAction();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif (preg_match('#^/departments/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/DepartmentController.php';
        $controller = new \App\Controllers\DepartmentController();
        $id = (int)$matches[1];
        
        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    // Section routes
    elseif ($endpoint === '/sections' || $endpoint === '/sections/') {
        require_once __DIR__ . '/backend/app/Controllers/SectionController.php';
        $controller = new \App\Controllers\SectionController();
        
        if ($requestMethod === 'GET') {
            $controller->indexAction();
        } elseif ($requestMethod === 'POST') {
            $controller->storeAction();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif (preg_match('#^/sections/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/SectionController.php';
        $controller = new \App\Controllers\SectionController();
        $id = (int)$matches[1];
        
        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    // Subsection routes
    elseif ($endpoint === '/subsections' || $endpoint === '/subsections/') {
        require_once __DIR__ . '/backend/app/Controllers/SubsectionController.php';
        $controller = new \App\Controllers\SubsectionController();
        
        if ($requestMethod === 'GET') {
            $controller->indexAction();
        } elseif ($requestMethod === 'POST') {
            $controller->storeAction();
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif (preg_match('#^/subsections/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/SubsectionController.php';
        $controller = new \App\Controllers\SubsectionController();
        $id = (int)$matches[1];
        
        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    // Attendance routes
    elseif ($endpoint === '/attendance' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->indexAction();
    }
    elseif ($endpoint === '/attendance/dashboard' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->dashboardAction();
    }
    elseif ($endpoint === '/attendance/today' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->todayAction();
    }
    elseif ($endpoint === '/attendance/clock-in' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->clockInAction();
    }
    elseif ($endpoint === '/attendance/clock-out' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->clockOutAction();
    }
    elseif (preg_match('#^/attendance/employee/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $employeeId = (int)$matches[1];
        $controller->byEmployeeAction($employeeId);
    }
    elseif ($endpoint === '/attendance/my-records' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $controller = new \App\Controllers\AttendanceController();
        $controller->myRecordsAction();
    }
    // Consent routes
    elseif ($endpoint === '/consent/status' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        $controller = new \App\Controllers\ConsentController();
        $controller->statusAction();
    }
    elseif ($endpoint === '/consent/verify-employee' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        $controller = new \App\Controllers\ConsentController();
        $controller->verifyEmployeeIdAction();
    }
    elseif ($endpoint === '/consent' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        $controller = new \App\Controllers\ConsentController();
        $controller->storeConsentAction();
    }
    elseif ($endpoint === '/consent/dashboard' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        $controller = new \App\Controllers\ConsentController();
        $controller->dashboardAction();
    }
    elseif ($endpoint === '/consent/employees' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        $controller = new \App\Controllers\ConsentController();
        $controller->employeesAction();
    }
    // Notification routes
    elseif ($endpoint === '/notifications' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        $controller = new \App\Controllers\NotificationController();
        $controller->indexAction();
    }
    elseif (preg_match('#^/notifications/(\d+)/read$#', $endpoint, $matches) && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        $controller = new \App\Controllers\NotificationController();
        $id = (int)$matches[1];
        $controller->markAsReadAction($id);
    }
    elseif ($endpoint === '/notifications/read-all' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        $controller = new \App\Controllers\NotificationController();
        $controller->markAllReadAction();
    }
    // Leave list
    elseif ($endpoint === '/leave' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->indexAction();
    }
    // Leave delegates
    elseif ($endpoint === '/leave/delegates' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->delegatesAction();
    }
    // Leave eligible employees (role-based)
    elseif ($endpoint === '/leave/eligible-employees' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->eligibleEmployeesAction();
    }
    // Leave eligible delegates (role-based)
    elseif ($endpoint === '/leave/eligible-delegates' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->eligibleDelegatesAction();
    }
    // Leave types with balances
    elseif ($endpoint === '/leave/types' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->typesAction();
    }
    // Leave application routes
    elseif ($endpoint === '/leave/applications' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->applyAction();
    }
    elseif ($endpoint === '/leave/calculate' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->calculateAction();
    }
    elseif (preg_match('#^/leave/applications/(\d+)/documents$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $controller->listDocumentsAction($applicationId);
    }
    elseif (preg_match('#^/leave/applications/(\d+)/documents/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $documentId = (int)$matches[2];
        $controller->viewDocumentAction($applicationId, $documentId);
    }
    // Leave manage (supervisor approvals tab)
    elseif ($endpoint === '/leave/manage' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $controller->manageAction();
    }
    // Leave approve / reject / invalidate / cancel
    elseif (preg_match('#^/leave/applications/(\d+)/approve$#', $endpoint, $matches)
        && ($requestMethod === 'PUT' || $requestMethod === 'POST')) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $controller->approveAction($applicationId);
    }
    elseif (preg_match('#^/leave/applications/(\d+)/reject$#', $endpoint, $matches)
        && ($requestMethod === 'PUT' || $requestMethod === 'POST')) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $controller->rejectAction($applicationId);
    }
    elseif (preg_match('#^/leave/applications/(\d+)/invalidate$#', $endpoint, $matches)
        && ($requestMethod === 'PUT' || $requestMethod === 'POST')) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $controller->invalidateAction($applicationId);
    }
    elseif (preg_match('#^/leave/applications/(\d+)/cancel$#', $endpoint, $matches)
        && ($requestMethod === 'PUT' || $requestMethod === 'POST')) {
        require_once __DIR__ . '/backend/app/Controllers/LeaveController.php';
        $controller = new \App\Controllers\LeaveController();
        $applicationId = (int)$matches[1];
        $controller->cancelAction($applicationId);
    }
    // Holiday routes
    elseif ($endpoint === '/holidays' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/HolidayController.php';
        $controller = new \App\Controllers\HolidayController();
        $controller->indexAction();
    }
    elseif ($endpoint === '/holidays/upcoming' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/HolidayController.php';
        $controller = new \App\Controllers\HolidayController();
        $controller->upcomingAction();
    }
    elseif (preg_match('#^/holidays/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/HolidayController.php';
        $controller = new \App\Controllers\HolidayController();
        $id = (int)$matches[1];

        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif ($endpoint === '/holidays' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/HolidayController.php';
        $controller = new \App\Controllers\HolidayController();
        $controller->storeAction();
    }
    // Profile routes
    elseif ($endpoint === '/profile' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->profileAction();
    }
    elseif ($endpoint === '/profile' && $requestMethod === 'PUT') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->updateProfileAction();
    }
    elseif ($endpoint === '/profile/documents' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $controller->uploadProfileDocumentAction();
    }
    elseif (preg_match('#^/profile/documents/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $documentId = (int)$matches[1];
        
        if ($requestMethod === 'DELETE') {
            $controller->deleteProfileDocumentAction($documentId);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    // Financial Year routes
    elseif ($endpoint === '/admin/financial-years' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->indexAction();
    }
    elseif ($endpoint === '/admin/financial-years/status' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->statusAction();
    }
    elseif ($endpoint === '/admin/financial-year/add' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->storeAction();
    }
    elseif ($endpoint === '/admin/financial-year/allocate' && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->allocateLeaveAction();
    }
    elseif ($endpoint === '/admin/financial-years/leave-types' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->leaveTypesAction();
    }
    elseif ($endpoint === '/admin/financial-years/employees' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        $controller = new \App\Controllers\FinancialYearController();
        $controller->employeesAction();
    }
    // Dashboard routes
    elseif (strpos($endpoint, '/dashboard') === 0) {
        require_once __DIR__ . '/backend/app/Controllers/DashboardController.php';
        $controller = new \App\Controllers\DashboardController();

        if ($endpoint === '/dashboard/stats' && $requestMethod === 'GET') {
            $controller->statsAction();
        } elseif ($endpoint === '/dashboard/charts/attendance' && $requestMethod === 'GET') {
            $controller->chartsAttendanceAction();
        } elseif ($endpoint === '/dashboard/charts/departments' && $requestMethod === 'GET') {
            $controller->chartsDepartmentsAction();
        } elseif ($endpoint === '/dashboard/charts/leave' && $requestMethod === 'GET') {
            $controller->chartsLeaveAction();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
    }
    // Users routes
    elseif ($endpoint === '/users' && $requestMethod === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/UserController.php';
        $controller = new \App\Controllers\UserController();
        $controller->indexAction();
    }
    elseif (preg_match('#^/users/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/UserController.php';
        $controller = new \App\Controllers\UserController();
        $id = (int)$matches[1];

        if ($requestMethod === 'GET') {
            $controller->showAction($id);
        } elseif ($requestMethod === 'PUT' || $requestMethod === 'POST') {
            $controller->updateAction($id);
        } elseif ($requestMethod === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        }
    }
    elseif (preg_match('#^/users/(\d+)/toggle-status$#', $endpoint, $matches)
        && ($requestMethod === 'PUT' || $requestMethod === 'POST')) {
        require_once __DIR__ . '/backend/app/Controllers/UserController.php';
        $controller = new \App\Controllers\UserController();
        $id = (int)$matches[1];
        $controller->toggleStatus($id);
    }
    elseif (preg_match('#^/users/(\d+)/change-password$#', $endpoint, $matches)
        && $requestMethod === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/UserController.php';
        $controller = new \App\Controllers\UserController();
        $id = (int)$matches[1];
        $controller->changePassword($id);
    }
    else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Not found']);
    }
} catch (\Throwable $e) {
    // Log the error
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    
    // Check if it's a database connection error
    $message = 'Internal server error';
    $errorCode = 'INTERNAL_ERROR';
    
    if (str_contains($e->getMessage(), 'SQLSTATE[HY000]') || 
        str_contains($e->getMessage(), 'No connection could be made') ||
        str_contains($e->getMessage(), 'Connection refused')) {
        $message = 'Database connection failed. Please try again later.';
        $errorCode = 'DATABASE_ERROR';
    } elseif (env('APP_DEBUG', false)) {
        $message = $e->getMessage();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => $errorCode,
        'debug' => env('APP_DEBUG', false) ? [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ] : null
    ]);
}
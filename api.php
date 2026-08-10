<?php

declare(strict_types=1);

/**
 * Professional API Entry Point
 * 
 * Improvements over original:
 * - Clear separation of routing logic
 * - Consistent error handling
 * - Better maintainability
 * - Proper HTTP status codes
 * - Debug mode support
 */

require_once __DIR__ . '/backend/bootstrap.php';

// Set API headers
header('Content-Type: application/json');

// CORS headers - must be specific origin when using credentials
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'http://localhost:8000',
    'http://localhost/hrdemo',
];

// Allow the requesting origin if it's in our whitelist
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin'); // Important for caching
} else {
    // For development, be more permissive
    if (str_starts_with($origin, 'http://localhost:')) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept');
header('Access-Control-Max-Age: 86400'); // 24 hours for preflight cache

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Send JSON error response and exit
 */
function sendError(int $statusCode, string $message, string $errorCode = 'ERROR', array $extra = []): void
{
    http_response_code($statusCode);
    
    $response = array_merge([
        'success' => false,
        'message' => $message,
        'error' => $errorCode
    ], $extra);
    
    echo json_encode($response);
    exit;
}

/**
 * Send JSON success response and exit
 */
function sendSuccess(array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    
    $response = array_merge(['success' => true], $data);
    
    echo json_encode($response);
    exit;
}

try {
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
    
    // Route to appropriate controller
    routeRequest($endpoint, $requestMethod);
    
} catch (\Throwable $e) {
    // Log the error
    error_log('API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    $message = 'Internal server error';
    $errorCode = 'INTERNAL_ERROR';
    
    // Check if it's a database connection error
    if (str_contains($e->getMessage(), 'SQLSTATE[HY000]') || 
        str_contains($e->getMessage(), 'No connection could be made') ||
        str_contains($e->getMessage(), 'Connection refused')) {
        $message = 'Database connection failed. Please try again later.';
        $errorCode = 'DATABASE_ERROR';
    } elseif (env('APP_DEBUG', false)) {
        $message = $e->getMessage();
    }
    
    $debugInfo = null;
    if (env('APP_DEBUG', false)) {
        $debugInfo = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    sendError(500, $message, $errorCode, ['debug' => $debugInfo]);
}

/**
 * Route requests to appropriate controllers
 */
function routeRequest(string $endpoint, string $method): void
{
    // Auth routes
    if (strpos($endpoint, '/auth') === 0) {
        require_once __DIR__ . '/backend/app/Controllers/AuthController.php';
        $controller = new \App\Controllers\AuthController();
        
        if ($endpoint === '/auth/login' && $method === 'POST') {
            $controller->loginAction();
        } elseif ($endpoint === '/auth/logout' && $method === 'POST') {
            $controller->logoutAction();
        } elseif ($endpoint === '/auth/refresh' && $method === 'POST') {
            $controller->refreshAction();
        } elseif ($endpoint === '/auth/me' && $method === 'GET') {
            $controller->meAction();
        } elseif ($endpoint === '/auth/change-password' && $method === 'POST') {
            $controller->changePasswordAction();
        } else {
            sendError(404, 'Not found', 'ROUTE_NOT_FOUND');
        }
        return;
    }
    
    // Employee routes
    if ($endpoint === '/employees' || $endpoint === '/employees/') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        
        if ($method === 'GET') {
            $controller->indexAction();
        } elseif ($method === 'POST') {
            $controller->storeAction();
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if ($endpoint === '/employees/reference' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->referenceAction();
        return;
    }
    
    if ($endpoint === '/employees/search' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->searchAction();
        return;
    }
    
    if (preg_match('#^/employees/(\d+)/profile$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $controller->showAction($id);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if ($endpoint === '/employees/documents' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->uploadDocumentAction();
        return;
    }
    
    if (preg_match('#^/employees/documents/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $docId = (int)$matches[1];
        
        if ($method === 'DELETE') {
            $controller->deleteDocumentAction($docId);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if (preg_match('#^/employees/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $controller->showAction($id);
        } elseif ($method === 'PUT' || $method === 'POST') {
            $controller->updateAction($id);
        } elseif ($method === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    // Department routes
    if ($endpoint === '/departments' || $endpoint === '/departments/') {
        require_once __DIR__ . '/backend/app/Controllers/DepartmentController.php';
        $controller = new \App\Controllers\DepartmentController();
        
        if ($method === 'GET') {
            $controller->indexAction();
        } elseif ($method === 'POST') {
            $controller->storeAction();
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if (preg_match('#^/departments/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/DepartmentController.php';
        $controller = new \App\Controllers\DepartmentController();
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $controller->showAction($id);
        } elseif ($method === 'PUT' || $method === 'POST') {
            $controller->updateAction($id);
        } elseif ($method === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    // Section routes
    if ($endpoint === '/sections' || $endpoint === '/sections/') {
        require_once __DIR__ . '/backend/app/Controllers/SectionController.php';
        $controller = new \App\Controllers\SectionController();
        
        if ($method === 'GET') {
            $controller->indexAction();
        } elseif ($method === 'POST') {
            $controller->storeAction();
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if (preg_match('#^/sections/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/SectionController.php';
        $controller = new \App\Controllers\SectionController();
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $controller->showAction($id);
        } elseif ($method === 'PUT' || $method === 'POST') {
            $controller->updateAction($id);
        } elseif ($method === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    // Subsection routes
    if ($endpoint === '/subsections' || $endpoint === '/subsections/') {
        require_once __DIR__ . '/backend/app/Controllers/SubsectionController.php';
        $controller = new \App\Controllers\SubsectionController();
        
        if ($method === 'GET') {
            $controller->indexAction();
        } elseif ($method === 'POST') {
            $controller->storeAction();
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    if (preg_match('#^/subsections/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/SubsectionController.php';
        $controller = new \App\Controllers\SubsectionController();
        $id = (int)$matches[1];
        
        if ($method === 'GET') {
            $controller->showAction($id);
        } elseif ($method === 'PUT' || $method === 'POST') {
            $controller->updateAction($id);
        } elseif ($method === 'DELETE') {
            $controller->destroyAction($id);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    // Attendance routes
    if ($endpoint === '/attendance' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->indexAction();
        return;
    }
    
    if ($endpoint === '/attendance/dashboard' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->dashboardAction();
        return;
    }
    
    if ($endpoint === '/attendance/today' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->todayAction();
        return;
    }
    
    if ($endpoint === '/attendance/clock-in' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->clockInAction();
        return;
    }
    
    if ($endpoint === '/attendance/clock-out' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->clockOutAction();
        return;
    }
    
    if (preg_match('#^/attendance/employee/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        $employeeId = (int)$matches[1];
        (new \App\Controllers\AttendanceController())->byEmployeeAction($employeeId);
        return;
    }
    
    if ($endpoint === '/attendance/my-records' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/AttendanceController.php';
        (new \App\Controllers\AttendanceController())->myRecordsAction();
        return;
    }
    
    // Consent routes
    if ($endpoint === '/consent/status' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        (new \App\Controllers\ConsentController())->statusAction();
        return;
    }
    
    if ($endpoint === '/consent/verify-employee' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        (new \App\Controllers\ConsentController())->verifyEmployeeIdAction();
        return;
    }
    
    if ($endpoint === '/consent' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/ConsentController.php';
        (new \App\Controllers\ConsentController())->storeConsentAction();
        return;
    }
    
    // Notification routes
    if ($endpoint === '/notifications' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        (new \App\Controllers\NotificationController())->indexAction();
        return;
    }
    
    if (preg_match('#^/notifications/(\d+)/read$#', $endpoint, $matches) && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        $id = (int)$matches[1];
        (new \App\Controllers\NotificationController())->markAsReadAction($id);
        return;
    }
    
    if ($endpoint === '/notifications/read-all' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/NotificationController.php';
        (new \App\Controllers\NotificationController())->markAllReadAction();
        return;
    }
    
    // Profile routes
    if ($endpoint === '/profile' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->profileAction();
        return;
    }
    
    if ($endpoint === '/profile' && $method === 'PUT') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->updateProfileAction();
        return;
    }
    
    if ($endpoint === '/profile/documents' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        (new \App\Controllers\EmployeeController())->uploadProfileDocumentAction();
        return;
    }
    
    if (preg_match('#^/profile/documents/(\d+)$#', $endpoint, $matches)) {
        require_once __DIR__ . '/backend/app/Controllers/EmployeeController.php';
        $controller = new \App\Controllers\EmployeeController();
        $documentId = (int)$matches[1];
        
        if ($method === 'DELETE') {
            $controller->deleteProfileDocumentAction($documentId);
        } else {
            sendError(405, 'Method not allowed', 'METHOD_NOT_ALLOWED');
        }
        return;
    }
    
    // Financial Year routes
    if ($endpoint === '/admin/financial-years' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->indexAction();
        return;
    }
    
    if ($endpoint === '/admin/financial-years/status' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->statusAction();
        return;
    }
    
    if ($endpoint === '/admin/financial-year/add' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->storeAction();
        return;
    }
    
    if ($endpoint === '/admin/financial-year/allocate' && $method === 'POST') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->allocateLeaveAction();
        return;
    }
    
    if ($endpoint === '/admin/financial-years/leave-types' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->leaveTypesAction();
        return;
    }
    
    if ($endpoint === '/admin/financial-years/employees' && $method === 'GET') {
        require_once __DIR__ . '/backend/app/Controllers/FinancialYearController.php';
        (new \App\Controllers\FinancialYearController())->employeesAction();
        return;
    }
    
    // Dashboard routes
    if (strpos($endpoint, '/dashboard') === 0) {
        require_once __DIR__ . '/backend/app/Controllers/DashboardController.php';
        $controller = new \App\Controllers\DashboardController();

        if ($endpoint === '/dashboard/stats' && $method === 'GET') {
            $controller->statsAction();
        } elseif ($endpoint === '/dashboard/charts/attendance' && $method === 'GET') {
            $controller->chartsAttendanceAction();
        } elseif ($endpoint === '/dashboard/charts/departments' && $method === 'GET') {
            $controller->chartsDepartmentsAction();
        } elseif ($endpoint === '/dashboard/charts/leave' && $method === 'GET') {
            $controller->chartsLeaveAction();
        } else {
            sendError(404, 'Not found', 'ROUTE_NOT_FOUND');
        }
        return;
    }
    
    // If no route matched
    sendError(404, 'Not found', 'ROUTE_NOT_FOUND');
}
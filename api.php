<?php

declare(strict_types=1);

// API Entry Point
// This file handles all API requests

require_once __DIR__ . '/backend/bootstrap.php';

// Set API headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

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
    // Dashboard routes
    elseif (strpos($endpoint, '/dashboard') === 0) {
        require_once __DIR__ . '/backend/app/Controllers/DashboardController.php';
        $controller = new \App\Controllers\DashboardController();

        if ($endpoint === '/dashboard/stats' && $requestMethod === 'GET') {
            $controller->statsAction();
        } elseif ($endpoint === '/dashboard/charts/attendance' && $requestMethod === 'GET') {
            $controller->attendanceTodayAction();
        } elseif ($endpoint === '/dashboard/charts/departments' && $requestMethod === 'GET') {
            $controller->employeeCountAction();
        } elseif ($endpoint === '/dashboard/charts/leave' && $requestMethod === 'GET') {
            $controller->pendingLeavesAction();
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Not found']);
        }
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

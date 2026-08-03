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
            echo json_encode(['error' => 'Not found']);
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
            echo json_encode(['error' => 'Method not allowed']);
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
            echo json_encode(['error' => 'Method not allowed']);
        }
    }
    else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Internal server error',
        'file' => env('APP_DEBUG', false) ? $e->getFile() : null,
        'line' => env('APP_DEBUG', false) ? $e->getLine() : null,
    ]);
}

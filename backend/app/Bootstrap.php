<?php

declare(strict_types=1);

namespace App;

/**
 * Bootstrap Class for Integration Tests
 * 
 * Provides a simple application bootstrap for testing purposes.
 */
class Bootstrap
{
    private bool $initialized = false;

    public function __construct()
    {
        $this->initialize();
    }

    /**
     * Initialize the application
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Load bootstrap file if not already loaded
        if (!defined('BASE_PATH')) {
            require_once dirname(__DIR__) . '/bootstrap.php';
        }

        $this->initialized = true;
    }

    /**
     * Run the application (handle the request)
     */
    public function run(): void
    {
        // Simple routing for integration tests
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Remove query string
        $requestUri = parse_url($requestUri, PHP_URL_PATH);

        // Route to appropriate controller
        if (preg_match('#^/api/employees/?$#', $requestUri)) {
            $controller = new \App\Controllers\EmployeeController();
            
            if ($requestMethod === 'GET') {
                $controller->indexAction();
            } elseif ($requestMethod === 'POST') {
                $controller->storeAction();
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
        } elseif (preg_match('#^/api/employees/(\d+)/?$#', $requestUri, $matches)) {
            $id = (int)$matches[1];
            $controller = new \App\Controllers\EmployeeController();
            
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
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Base Controller - All controllers should extend this class.
 * 
 * Provides common functionality like JSON responses, input handling,
 * and authentication checks.
 */
abstract class BaseController
{
    /**
     * Send a JSON response.
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Send a success response.
     */
    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Send an error response.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param mixed $error Detailed error for logs (optional)
     * @param array $meta Additional metadata (e.g., distance, code)
     */
    protected function error(string $message, int $statusCode = 400, mixed $error = null, array $meta = []): void
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];
        
        if ($error !== null) {
            $response['error'] = $error;
        }
        
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        
        $this->json($response, $statusCode);
    }

    /**
     * Send a 404 not found response.
     */
    protected function notFound(string $message = 'Resource not found'): void
    {
        $this->error($message, 404);
    }

    /**
     * Send a 403 forbidden response.
     */
    protected function forbidden(string $message = 'Access denied'): void
    {
        $this->error($message, 403);
    }

    /**
     * Send a 401 unauthorized response.
     */
    protected function unauthorized(string $message = 'Authentication required'): void
    {
        $this->error($message, 401);
    }

    /**
     * Get the current authenticated user ID.
     */
    protected function getUserId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /**
     * Get the current authenticated user ID (alias for getUserId).
     */
    protected function getAuthUserId(): int
    {
        return $this->getUserId();
    }

    /**
     * Get the current user role.
     */
    protected function getUserRole(): string
    {
        return $_SESSION['user_role'] ?? '';
    }

    /**
     * Check if the current user has permission.
     * Uses hybrid authorization (RBAC + user page permissions).
     */
    protected function hasPermission(string $module, string $action): bool
    {
        // Use the Auth helper which implements hybrid authorization
        $auth = \App\Helpers\Auth::getInstance();
        return $auth->hasPermission($module, $action);
    }

    /**
     * Require permission or return error.
     */
    protected function requirePermission(string $module, string $action): void
    {
        if (!$this->hasPermission($module, $action)) {
            $this->forbidden("You do not have permission to {$action} {$module}");
        }
    }

    /**
     * Get JSON request body.
     */
    protected function getJsonBody(): array
    {
        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Validate required fields.
     */
    protected function validateRequired(array $data, array $fields): ?array
    {
        $missing = [];
        
        foreach ($fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }
        
        return !empty($missing) ? $missing : null;
    }

    /**
     * Get pagination parameters from request.
     */
    protected function getPaginationParams(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        
        return [$page, $perPage];
    }

    /**
     * Get search query from request.
     */
    protected function getSearchQuery(): string
    {
        return trim($_GET['search'] ?? $_GET['q'] ?? '');
    }

    /**
     * Get filter parameters from request.
     */
    protected function getFilters(): array
    {
        $filters = [];
        $allowedParams = [
            'search', 'status', 'department', 'department_id', 'section_id',
            'role', 'type', 'employee_type', 'employee_status',
            'date_from', 'date_to'
        ];
        
        foreach ($allowedParams as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $filters[$param] = $_GET[$param];
            }
        }
        
        return $filters;
    }
}
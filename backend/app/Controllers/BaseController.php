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
     *
     * Delegates to the standardized ApiResponse envelope so that every
     * response — controller-level errors, middleware exits, and exceptions —
     * shares the same shape: { success, message, data } | { success, message, error: {...} }.
     *
     * CORS is handled centrally by SecurityMiddleware::applyCorsHeaders()
     * (called from api.php before routing), so it is not duplicated here.
     */
    protected function json(mixed $data, int $statusCode = 200): void
    {
        // Guarantee a pure-JSON body: strip any stray output (third-party
        // notices, debug echoes) that accumulated in the output buffers.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Send a standardized success response.
     *
     * Delegates to ApiResponse (single envelope source of truth). The output
     * buffer cleanup from json() is applied first for parity.
     */
    protected function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        \App\Helpers\ApiResponse::success($data, $message, $statusCode);
    }

    /**
     * Send a standardized error response.
     *
     * @param string $message    Safe, user-facing error message.
     * @param int    $statusCode HTTP status code.
     * @param string $errorCode  Application error code (e.g. 'VALIDATION_ERROR').
     * @param array  $details    Optional validation/field context.
     */
    protected function error(string $message, int $statusCode = 400, string $errorCode = 'ERROR', array $details = []): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        \App\Helpers\ApiResponse::error($message, $errorCode, $details, $statusCode);
    }

    /**
     * Validate request data against a validator and return it.
     *
     * Request validation covers input SHAPE and FORMAT only (required fields,
     * formats, lengths). Business rules — uniqueness, existence, balances,
     * conflicts, permissions — are owned by the service layer and are
     * intentionally NOT duplicated here.
     *
     * On failure, emits a standardized 422 envelope where `message` is the
     * first validation error and `error.details` maps field => message:
     * { success: false, message: "...", error: { code: "VALIDATION_ERROR", details: {...} } }
     *
     * @param \App\Validators\Contracts\ValidatorInterface $validator Validator to run.
     * @param array|null $data                                        Payload to validate; defaults to the JSON request body.
     * @return array The validated data, for fluent delegation to services.
     */
    protected function validateRequest(\App\Validators\Contracts\ValidatorInterface $validator, ?array $data = null): array
    {
        $data = $data ?? $this->getJsonBody();

        if ($validator->fails($data)) {
            $errors = $validator->errors();
            $first = $errors ? (string) reset($errors) : 'Validation failed.';
            $this->error($first, 422, 'VALIDATION_ERROR', $errors);
        }

        return $data;
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
     * Get the current authenticated user ID from session or JWT.
     *
     * Checks the PHP session first, then falls back to JWT token
     * authentication via the Authorization header OR the httpOnly
     * access_token cookie (set by the server during login).
     */
    protected function getUserId(): int
    {
        // First check PHP session
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
            return (int) $_SESSION['user_id'];
        }

        // Fallback to JWT token from Authorization header or access_token cookie
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        $hasBearerToken = strpos($authHeader, 'Bearer ') === 0;
        $hasCookieToken = isset($_COOKIE['access_token']);

        if ($hasBearerToken || $hasCookieToken) {
            // Use Auth helper to authenticate from JWT token.
            // Auth::check() reads the token from the Authorization header
            // OR the httpOnly access_token cookie, then restores the session.
            try {
                $auth = \App\Helpers\Auth::getInstance();
                if ($auth->check()) {
                    return (int) ($_SESSION['user_id'] ?? 0);
                }
            } catch (\Throwable $authError) {
                error_log('JWT auth error: ' . $authError->getMessage());
            }
        }

        return 0;
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

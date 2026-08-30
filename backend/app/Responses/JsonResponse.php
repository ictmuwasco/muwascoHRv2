<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * JsonResponse
 *
 * Standardizes JSON API responses across the application.
 */
class JsonResponse
{
    /**
     * Return a successful response.
     */
    public static function success(array $data, string $message = '', int $statusCode = 200): void
    {
        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $statusCode);
    }

    /**
     * Return an error response.
     */
    public static function error(string $message, int $statusCode = 400, array $errors = []): void
    {
        self::send([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $statusCode);
    }

    /**
     * Return a validation error response.
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], 422);
    }

    /**
     * Return a not found response.
     */
    public static function notFound(string $message = 'Resource not found'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
        ], 404);
    }

    /**
     * Return an unauthorized response.
     */
    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
        ], 401);
    }

    /**
     * Return a forbidden response.
     */
    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    /**
     * Return a server error response.
     */
    public static function serverError(string $message = 'Internal server error'): void
    {
        self::send([
            'success' => false,
            'message' => $message,
        ], 500);
    }

    /**
     * Return a paginated response.
     */
    public static function paginated(array $data, int $total, int $page, int $perPage, string $message = ''): void
    {
        $lastPage = (int)ceil($total / $perPage);

        self::send([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => ($page - 1) * $perPage + 1,
                'to' => min($page * $perPage, $total),
            ],
        ], 200);
    }

    /**
     * Send the JSON response.
     */
    private static function send(array $data, int $statusCode): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');

        // Correlation id on EVERY response so the SPA can bind errors,
        // audit entries and support tickets to one traceable request.
        try {
            if (class_exists(\App\Services\ErrorTracking\RequestIdService::class)) {
                \App\Services\ErrorTracking\RequestIdService::applyHeader();
            }
        } catch (\Throwable $ignored) {
            // Observability must never break a response.
        }
        
        // CORS configuration - must be specific origin when using credentials
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = [
            'http://localhost:5173',  // Vite dev server
            'http://localhost:3000',  // Alternative dev port
            'http://localhost',       // Production
        ];
        
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
}
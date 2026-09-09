<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Exception Handler
 * 
 * Centralized exception handler that converts exceptions to HTTP responses.
 * Handles both custom AppExceptions and standard PHP exceptions.
 */
class ExceptionHandler
{
    /**
     * Handle an exception and return appropriate HTTP response
     */
    public static function handle(\Throwable $exception): void
    {
        // Clean output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($exception instanceof AppException) {
            self::handleAppException($exception);
        } else {
            self::handleGenericException($exception);
        }
    }

    /**
     * Handle custom application exceptions
     */
    private static function handleAppException(AppException $exception): void
    {
        http_response_code($exception->getHttpStatusCode());
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($exception->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Handle generic PHP exceptions
     */
    private static function handleGenericException(\Throwable $exception): void
    {
        $statusCode = self::getHttpStatusCode($exception);
        
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        $response = [
            'success' => false,
            'message' => $statusCode >= 500 ? 'An internal error occurred' : $exception->getMessage(),
            'error' => [
                'code' => self::getErrorCode($exception),
            ],
        ];

        // Include details in debug mode
        if (env('APP_DEBUG', false)) {
            $response['error']['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    /**
     * Determine HTTP status code from exception
     */
    private static function getHttpStatusCode(\Throwable $exception): int
    {
        $code = $exception->getCode();
        
        if ($code >= 400 && $code < 600) {
            return $code;
        }

        // Map common exception types to status codes
        return match (true) {
            $exception instanceof \InvalidArgumentException => 400,
            $exception instanceof \RuntimeException => 500,
            $exception instanceof \PDOException => 503,
            default => 500,
        };
    }

    /**
     * Get error code from exception class name
     */
    private static function getErrorCode(\Throwable $exception): string
    {
        $class = get_class($exception);
        $shortName = substr($class, strrpos($class, '\\') + 1);
        
        // Convert CamelCase to SCREAMING_SNAKE_CASE
        $code = preg_replace('/([a-z])([A-Z])/', '$1_$2', $shortName);
        return strtoupper($code);
    }

    /**
     * Register the exception handler
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
    }
}

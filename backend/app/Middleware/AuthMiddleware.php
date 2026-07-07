<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Auth Middleware - Handles authentication validation and session checking.
 * 
 * Protects routes by verifying user is authenticated, session is valid,
 * and permissions are appropriate.
 */
class AuthMiddleware
{
    /**
     * Require the user to be authenticated.
     * Redirects to login page if not authenticated.
     */
    public static function requireAuth(): void
    {
        $isLoggedIn = isset($_SESSION['user_id']) 
            && isset($_SESSION['session_valid']) 
            && $_SESSION['session_valid'] === true;

        if (!$isLoggedIn) {
            // Check for JWT token as alternative authentication
            $jwt = \App\Helpers\JWT::getInstance();
            $user = $jwt->getAuthenticatedUser();
            
            if ($user) {
                // Auto-login with JWT
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['session_valid'] = true;
                return;
            }

            $_SESSION['flash_message'] = 'Please sign in to access this page.';
            $_SESSION['flash_type'] = 'info';
            header('Location: login.php');
            exit();
        }
    }

    /**
     * Require a specific user role.
     */
    public static function requireRole(string|array $roles): void
    {
        self::requireAuth();
        
        $userRole = $_SESSION['user_role'] ?? '';
        
        if (is_array($roles)) {
            if (!in_array($userRole, $roles, true)) {
                self::denyAccess();
            }
        } elseif ($userRole !== $roles) {
            self::denyAccess();
        }
    }

    /**
     * Require permission for a module action.
     */
    public static function requirePermission(string $module, string $action): void
    {
        self::requireAuth();
        
        $rbac = \App\Helpers\RBAC::getInstance();
        $rbac->requirePermission($module, $action);
    }

    /**
     * Deny access and show error.
     */
    private static function denyAccess(): void
    {
        http_response_code(403);
        
        if (self::isApiRequest()) {
            echo json_encode(['error' => 'Unauthorized access.']);
            exit();
        }
        
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php');
        exit();
    }

    /**
     * Check if the current request is an API request.
     */
    private static function isApiRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\AuthServiceInterface;
use App\Services\AuthService;

/**
 * Auth Controller - REST API for authentication.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to AuthService.
 */
class AuthController extends BaseController
{
    private AuthServiceInterface $authService;

    public function __construct()
    {
        // Dependency injection - services are injected via setter methods
        $this->authService = new AuthService();
        
        // Set repository dependencies
        $this->authService->setUserRepository(new \App\Repositories\UserRepository());
        $this->authService->setEmployeeRepository(new \App\Repositories\EmployeeRepository());
    }

    /**
     * POST /api/auth/login - Authenticate user.
     */
    public function loginAction(): void
    {
        // Security: Rate-limit login attempts (F-06)
        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login');

        try {
            $data = $this->getJsonBody();
            
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $rememberMe = $data['remember_me'] ?? false;

            if (empty($email) || empty($password)) {
                $this->error('Email and password are required', 400);
            }

            $result = $this->authService->login($email, $password, $rememberMe);

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_AUTH,
                \App\Services\AuditService::ACTION_LOGIN,
                'User logged in',
                ['target_type' => 'User', 'target_id' => $result['user']['id'] ?? null, 'target_name' => ($result['user']['first_name'] ?? '') . ' ' . ($result['user']['last_name'] ?? '')]
            );

            // Note: we deliberately do NOT call setcookie(...) here.
            // PHP's session module emits the correct Set-Cookie header
            // automatically at script shutdown, using the cookie params
            // configured in bootstrap.php. Manually re-issuing the cookie
            // before AuthService populated $_SESSION was emitting an
            // empty-session cookie that clobbered the real one.

            $this->success($result, 'Login successful');
        } catch (\InvalidArgumentException $e) {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_AUTH,
                \App\Services\AuditService::ACTION_LOGIN_FAILED,
                'Login failed: ' . $e->getMessage(),
                ['status' => \App\Services\AuditService::STATUS_FAILED, 'metadata' => ['email' => $email ?? '']]
            );
            $this->error($e->getMessage(), 401);
        } catch (\Exception $e) {
            \logger()->error('Login error', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            
            // Check if it's a database connection error
            if (str_contains($e->getMessage(), 'SQLSTATE[HY000]') || 
                str_contains($e->getMessage(), 'No connection could be made') ||
                str_contains($e->getMessage(), 'Connection refused')) {
                $this->error('Database connection failed. Please contact administrator.', 500, 'DATABASE_ERROR');
            } else {
                $this->error('Login failed. Please try again.', 500);
            }
        }
    }

    /**
     * POST /api/auth/logout - Logout user.
     */
    public function logoutAction(): void
    {
        try {
            // Get user ID from session, if available
            $userId = $this->getAuthUserId();
            
            // Only attempt logout if user is authenticated
            if ($userId > 0) {
                \App\Services\AuditService::getInstance()->log(
                    \App\Services\AuditService::MODULE_AUTH,
                    \App\Services\AuditService::ACTION_LOGOUT,
                    'User logged out',
                    ['target_type' => 'User', 'target_id' => $userId]
                );
                $this->authService->logout($userId);
            }
            
            // Always return success, even if session was already cleared
            $this->success(null, 'Logout successful');
        } catch (\Exception $e) {
            \logger()->error('Logout error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            
            // Even on error, clear the session and return success
            try {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_destroy();
                }
            } catch (\Exception $cleanupError) {
                // Ignore cleanup errors
            }
            
            $this->success(null, 'Logout successful');
        }
    }

    /**
     * POST /api/auth/refresh - Refresh access token.
     */
    public function refreshAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            $token = $this->authService->refreshToken($userId);
            $this->success(['token' => $token], 'Token refreshed');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 401);
        } catch (\Exception $e) {
            \logger()->error('Token refresh error', ['error' => $e->getMessage()]);
            $this->error('Token refresh failed. Please try again.', 500);
        }
    }

    /**
     * GET /api/auth/me - Get current user.
     */
    public function meAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            $user = $this->authService->getUserById($userId);
            
            if (!$user) {
                $this->notFound('User not found');
            }

            $this->success($user);
        } catch (\Exception $e) {
            \logger()->error('Get user error', ['error' => $e->getMessage()]);
            $this->error('Failed to get user information.', 500);
        }
    }

    /**
     * POST /api/auth/change-password - Change user password.
     */
    public function changePasswordAction(): void
    {
        // Security: Rate-limit password change attempts (F-06)
        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('change_password');

        try {
            $userId = $this->getAuthUserId();
            $data = $this->getJsonBody();
            
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword)) {
                $this->error('Current password and new password are required', 400);
            }

            // Verify current password
            $user = $this->authService->getUserById($userId);
            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $this->error('Current password is incorrect', 401);
            }

            // Update password
            $this->authService->updatePassword($userId, $newPassword);
            $this->success(null, 'Password changed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Change password error', ['error' => $e->getMessage()]);
            $this->error('Failed to change password. Please try again.', 500);
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

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
        try {
            $data = $this->getJsonBody();
            
            $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';
            $rememberMe = (bool) ($data['remember_me'] ?? false);

            if (empty($email) || empty($password)) {
                $this->error('Email and password are required', 400);
            }

            // Security: rate-limit login attempts per IP AND per account (F-06).
            // The file-backed store persists even though the session is closed
            // for API requests; the account identifier throttles credential
            // stuffing against one mailbox from rotating IPs.
            \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('login', 5, 900, $email);

            $result = $this->authService->login($email, $password, $rememberMe);

            // Attach the effective permission set for the frontend permission
            // context (sidebar visibility / button rendering). UX only — the
            // backend enforces authorization independently on every request.
            if (isset($result['user']['id'])) {
                $result['user']['permissions'] = \App\Helpers\AuthorizationService::getInstance()
                    ->getEffectivePermissionStrings((int) $result['user']['id']);
            }

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
                // Security: revoke every refresh token issued for this user so
                // no new access tokens can be minted after logout (F-07).
                try {
                    \App\Helpers\JWT::getInstance()->revokeAllTokens($userId);
                } catch (\Throwable $revokeError) {
                    \logger()->warning('Refresh token revocation failed on logout', ['user_id' => $userId, 'error' => $revokeError->getMessage()]);
                }

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

            // Effective permission set for the frontend permission context
            // (sidebar visibility, button rendering, route guards). UX only —
            // the backend enforces authorization independently per request.
            $user['permissions'] = \App\Helpers\AuthorizationService::getInstance()
                ->getEffectivePermissionStrings($userId);

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
        try {
            $userId = $this->getAuthUserId();
            if ($userId <= 0) {
                $this->error('Authentication required', 401);
            }

            // Security: rate-limit password change attempts per account (F-06)
            \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('change_password', 5, 900, (string) $userId);
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

            // Update password (also revokes refresh tokens + enforces policy)
            $this->authService->updatePassword($userId, $newPassword);

            // Security: invalidate remaining token material and rotate the
            // current session id so other devices/sessions must re-authenticate.
            try {
                \App\Helpers\JWT::getInstance()->revokeAllTokens($userId);
            } catch (\Throwable $revokeError) {
                \logger()->warning('Refresh token revocation failed on password change', ['user_id' => $userId, 'error' => $revokeError->getMessage()]);
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_AUTH,
                \App\Services\AuditService::ACTION_PASSWORD_CHANGE,
                'User changed their password',
                ['status' => \App\Services\AuditService::STATUS_SUCCESS, 'target_type' => 'User', 'target_id' => $userId]
            );

            $this->success(null, 'Password changed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Change password error', ['error' => $e->getMessage()]);
            $this->error('Failed to change password. Please try again.', 500);
        }
    }
}


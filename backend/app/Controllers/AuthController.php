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
        try {
            $data = $this->getJsonBody();
            
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $rememberMe = $data['remember_me'] ?? false;

            if (empty($email) || empty($password)) {
                $this->error('Email and password are required', 400);
            }

            $result = $this->authService->login($email, $password, $rememberMe);
            $this->success($result, 'Login successful');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 401);
        } catch (\Exception $e) {
            \logger()->error('Login error', ['error' => $e->getMessage()]);
            $this->error('Login failed. Please try again.', 500);
        }
    }

    /**
     * POST /api/auth/logout - Logout user.
     */
    public function logoutAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            $this->authService->logout($userId);
            $this->success(null, 'Logout successful');
        } catch (\Exception $e) {
            \logger()->error('Logout error', ['error' => $e->getMessage()]);
            $this->error('Logout failed. Please try again.', 500);
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
            $user = $this->authService->getUserByEmail('');
            
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
        try {
            $userId = $this->getAuthUserId();
            $data = $this->getJsonBody();
            
            $currentPassword = $data['current_password'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword)) {
                $this->error('Current password and new password are required', 400);
            }

            // Verify current password
            $user = $this->authService->getUserByEmail('');
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
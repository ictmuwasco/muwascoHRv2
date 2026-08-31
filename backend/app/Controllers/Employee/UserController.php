<?php

declare(strict_types=1);

namespace App\Controllers\Employee;

use App\Controllers\BaseController;
use App\Services\Contracts\UserServiceInterface;
use App\Services\UserService;

/**
 * User Controller - REST API for user management.
 *
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to UserService.
 */
class UserController extends BaseController
{
    private UserServiceInterface $userService;

    public function __construct()
    {
        // Dependency injection - services are injected via setter methods
        $this->userService = new UserService();

        // Set repository dependencies
        $this->userService->setUserRepository(new \App\Repositories\UserRepository());
        $this->userService->setEmployeeRepository(new \App\Repositories\EmployeeRepository());
    }

    /**
     * GET /api/users - List all users with optional filters and pagination.
     */
    public function indexAction(): void
    {
        $this->requirePermission('users', 'view');

        try {
            $filters = $this->getFilters();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));

            $result = $this->userService->getAllUsers($filters, $page, $limit);

            // Transform each user via the UserResource
            if (isset($result['data']) && is_array($result['data'])) {
                $result['data'] = array_map(
                    fn($user) => (new \App\Responses\UserResource($user))->toArray(),
                    $result['data']
                );
            }

            $this->success($result, 'Users retrieved successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Users listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve users. Please try again.', 500);
        }
    }

    /**
     * GET /api/users/{id} - Get a single user by ID.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('users', 'view');

        try {
            $user = $this->userService->getUserById($id);
            if (!$user) {
                $this->notFound('User not found');
                return;
            }
            $this->success((new \App\Responses\UserResource($user))->toArray());
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('User retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve user. Please try again.', 500);
        }
    }

    /**
     * POST /api/users - Create a new user.
     */
    public function storeAction(): void
    {
        $this->requirePermission('users', 'create');

        $data = $this->validateRequest(new \App\Validators\UserValidator());

        try {
            $userId = $this->userService->createUser($data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_USERS,
                \App\Services\AuditService::ACTION_CREATE,
                'Created user account',
                ['target_type' => 'User', 'target_id' => $userId, 'target_name' => ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''), 'new_values' => $data]
            );
            $this->success(['id' => $userId], 'User created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('User creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create user. Please try again.', 500);
        }
    }

    /**
     * PUT /api/users/{id} - Update an existing user.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('users', 'edit');

        $data = $this->validateRequest(new \App\Validators\UserValidator());

        try {
            $oldUser = $this->userService->getUserById($id);
            $result = $this->userService->updateUser($id, $data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_USERS,
                \App\Services\AuditService::ACTION_UPDATE,
                'Updated user account',
                ['target_type' => 'User', 'target_id' => $id, 'target_name' => ($oldUser['first_name'] ?? '') . ' ' . ($oldUser['last_name'] ?? ''), 'old_values' => $oldUser, 'new_values' => $data]
            );
            $this->success($result, 'User updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('User update error', ['error' => $e->getMessage(), 'id' => $id, 'data' => $data]);
            $this->error('Failed to update user. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/users/{id} - Delete a user.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('users', 'delete');

        try {
            $oldUser = $this->userService->getUserById($id);
            $this->userService->deleteUser($id);

            // Security: deleted users must not retain usable tokens.
            try {
                \App\Helpers\JWT::getInstance()->revokeAllTokens($id);
            } catch (\Throwable $revokeError) {
                \logger()->warning('Refresh token revocation failed on user delete', ['user_id' => $id, 'error' => $revokeError->getMessage()]);
            }
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_USERS,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted user account',
                ['target_type' => 'User', 'target_id' => $id, 'target_name' => ($oldUser['first_name'] ?? '') . ' ' . ($oldUser['last_name'] ?? ''), 'old_values' => $oldUser]
            );
            $this->success(null, 'User deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('User deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete user. Please try again.', 500);
        }
    }

    /**
     * PUT /api/users/{id}/toggle-status - Toggle user active status.
     */
    public function toggleStatus(int $id): void
    {
        $this->requirePermission('users', 'edit');

        $data = $this->getJsonBody();

        try {
            $status = $data['is_active'] ?? ($data['status'] ?? 'active');
            $oldUser = $this->userService->getUserById($id);
            $result = $this->userService->updateUserStatus($id, $status);

            // Security: deactivation must end access immediately. Reload the
            // user and revoke all refresh tokens when the account is disabled.
            $fresh = $this->userService->getUserById($id);
            if (!$fresh || (int) ($fresh['is_active'] ?? 1) !== 1) {
                try {
                    \App\Helpers\JWT::getInstance()->revokeAllTokens($id);
                } catch (\Throwable $revokeError) {
                    \logger()->warning('Refresh token revocation failed on user disable', ['user_id' => $id, 'error' => $revokeError->getMessage()]);
                }
            }
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_USERS,
                \App\Services\AuditService::ACTION_STATUS_CHANGE,
                'Changed user status to ' . $status,
                ['target_type' => 'User', 'target_id' => $id, 'target_name' => ($oldUser['first_name'] ?? '') . ' ' . ($oldUser['last_name'] ?? ''), 'old_values' => ['is_active' => $oldUser['is_active'] ?? null], 'new_values' => ['is_active' => $status]]
            );
            $this->success($result, 'User status updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('User status toggle error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update user status. Please try again.', 500);
        }
    }

    /**
     * POST /api/users/{id}/change-password - Change user password.
     */
    public function changePassword(int $id): void
    {
        $this->requirePermission('users', 'edit');

        $data = $this->getJsonBody();

        try {
            $newPassword = $data['password'] ?? '';
            if (!is_string($newPassword) || trim($newPassword) === '') {
                $this->error('Password is required', 400);
                return;
            }
            if (strlen($newPassword) < 8) {
                $this->error('Password must be at least 8 characters', 400);
                return;
            }
            $result = $this->userService->updatePassword($id, $newPassword);

            // Security: admin-forced password change invalidates existing tokens.
            try {
                \App\Helpers\JWT::getInstance()->revokeAllTokens($id);
            } catch (\Throwable $revokeError) {
                \logger()->warning('Refresh token revocation failed on admin password change', ['user_id' => $id, 'error' => $revokeError->getMessage()]);
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_USERS,
                \App\Services\AuditService::ACTION_PASSWORD_CHANGE,
                'Password changed by administrator',
                ['status' => \App\Services\AuditService::STATUS_SUCCESS, 'target_type' => 'User', 'target_id' => $id]
            );

            $this->success($result, 'Password changed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            \logger()->error('User password change error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to change password. Please try again.', 500);
        }
    }
}
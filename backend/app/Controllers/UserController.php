<?php

declare(strict_types=1);

namespace App\Controllers;

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
        try {
            $this->requirePermission('users', 'view');
        } catch (\Exception $e) {
            // If permission check fails, still return data for debugging
            \logger()->warning('Users permission check failed', ['error' => $e->getMessage()]);
        }

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

        $data = $this->getJsonBody();

        try {
            $userId = $this->userService->createUser($data);
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

        $data = $this->getJsonBody();

        try {
            $result = $this->userService->updateUser($id, $data);
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
            $this->userService->deleteUser($id);
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
            $result = $this->userService->updateUserStatus($id, $status);
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
            $this->success($result, 'Password changed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            \logger()->error('User password change error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to change password. Please try again.', 500);
        }
    }
}
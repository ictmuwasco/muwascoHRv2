<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * User Service Interface
 *
 * Defines the contract for user business logic operations.
 */
interface UserServiceInterface extends ServiceInterface
{
    public function setUserRepository(UserRepositoryInterface $repository): void;
    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void;

    /**
     * Get all users with filters.
     */
    public function getAllUsers(array $filters = [], int $page = 1, int $limit = 30): array;

    /**
     * Get user by ID.
     */
    public function getUserById(int $id): ?array;

    /**
     * Create a new user.
     */
    public function createUser(array $data): int;

    /**
     * Update an existing user.
     */
    public function updateUser(int $id, array $data): bool;

    /**
     * Delete a user.
     */
    public function deleteUser(int $id): bool;

    /**
     * Update user password.
     */
    public function updatePassword(int $userId, string $newPassword): bool;

    /**
     * Update user status.
     */
    public function updateUserStatus(int $userId, string $status): bool;

    /**
     * Update user role.
     */
    public function updateUserRole(int $userId, string $role): bool;

    /**
     * Search users.
     */
    public function searchUsers(string $query, array $filters = [], int $page = 1, int $limit = 30): array;

    /**
     * Validate user data.
     */
    public function validateUserData(array $data, ?int $excludeId = null): array;
}
<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * User Repository Interface
 *
 * Defines the contract for user data access operations.
 */
interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Find user by email.
     */
    public function findByEmail(string $email): ?array;

    /**
     * Find user by ID with employee details.
     */
    public function findWithEmployee(int $id): ?array;

    /**
     * Find user by employee ID.
     */
    public function findByEmployeeId(string $employeeId): ?array;

    /**
     * Get all users with filters.
     */
    public function search(array $filters, int $page = 1, int $limit = 30): array;

    /**
     * Update user password.
     */
    public function updatePassword(int $id, string $passwordHash): bool;

    /**
     * Update user status.
     */
    public function updateStatus(int $id, string $status): bool;

    /**
     * Update user role.
     */
    public function updateRole(int $id, string $role): bool;

    /**
     * Check if email exists.
     */
    public function emailExists(string $email, ?int $excludeId = null): bool;

    /**
     * Get users by role.
     */
    public function getByRole(string $role): array;

    /**
     * Create user account.
     */
    public function createUser(array $data): int;
}
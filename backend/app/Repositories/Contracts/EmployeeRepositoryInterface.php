<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Employee Repository Interface
 *
 * Defines the contract for employee data access operations.
 */
interface EmployeeRepositoryInterface extends RepositoryInterface
{
    /**
     * Find employee by email.
     */
    public function findByEmail(string $email): ?array;

    /**
     * Find employee by employee ID.
     */
    public function findByEmployeeId(string $employeeId): ?array;

    /**
     * Find employee by user ID.
     */
    public function findByUserId(int $userId): ?array;

    /**
     * Search employees with filters.
     */
    public function search(array $filters, int $page = 1, int $limit = 30): array;

    /**
     * Get all departments.
     */
    public function getAllDepartments(): array;

    /**
     * Get sections by department ID.
     */
    public function getSectionsByDepartment(int $departmentId): array;

    /**
     * Get subsections by section ID.
     */
    public function getSubsectionsBySection(int $sectionId): array;

    /**
     * Get all offices.
     */
    public function getAllOffices(): array;

    /**
     * Get employee with full details by ID.
     */
    public function findWithDetails(int $id): ?array;

    /**
     * Check if employee ID exists.
     */
    public function employeeIdExists(string $employeeId, ?int $excludeId = null): bool;

    /**
     * Check if email exists.
     */
    public function emailExists(string $email, ?int $excludeId = null): bool;

    /**
     * Check if national ID exists.
     */
    public function nationalIdExists(string $nationalId, ?int $excludeId = null): bool;

    /**
     * Get organization hierarchy.
     */
    public function getOrganizationHierarchy(): array;

    /**
     * Get employees by role.
     */
    public function getByRole(string $role): array;

    /**
     * Get employees by department.
     */
    public function getByDepartment(int $departmentId): array;

    /**
     * Get employees by section.
     */
    public function getBySection(int $sectionId): array;
}
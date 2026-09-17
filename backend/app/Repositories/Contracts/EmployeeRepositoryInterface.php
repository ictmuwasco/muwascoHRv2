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

    /**
     * Get all contracts for an employee.
     */
    public function getEmployeeContracts(int $employeeId): array;

    /**
     * Get total contract count for an employee.
     */
    public function getEmployeeContractCount(int $employeeId): int;

    /**
     * Get a specific contract by ID.
     */
    public function getContractById(int $contractId): ?array;

    /**
     * Create a new contract record.
     */
    public function createContract(array $data): int;

    /**
     * Get the next contract number for an employee.
     */
    public function getNextContractNumber(int $employeeId): int;

    /**
     * Update employee contract dates.
     */
    public function updateEmployeeContractDates(int $employeeId, string $startDate, string $endDate): bool;

    /**
     * Increment the total contracts count for an employee.
     */
    public function incrementContractCount(int $employeeId): bool;
}
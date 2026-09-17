<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;

/**
 * Employee Service Interface
 *
 * Defines the contract for employee business logic operations.
 */
interface EmployeeServiceInterface extends ServiceInterface
{
    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void;
    public function setDepartmentRepository(DepartmentRepositoryInterface $repository): void;
    public function setSectionRepository(SectionRepositoryInterface $repository): void;
    public function setOfficeRepository(OfficeRepositoryInterface $repository): void;

    /**
     * Get all employees with filters and pagination.
     */
    public function getAllEmployees(array $filters = [], int $page = 1, int $limit = 30): array;

    /**
     * Get employee by ID.
     */
    public function getEmployeeById(int $id): ?array;

    /**
     * Get employee by user ID.
     */
    public function getEmployeeByUserId(int $userId): ?array;

    /**
     * Update employee profile (partial update without full validation).
     */
    public function updateEmployeeProfile(int $id, array $data): bool;

    /**
     * Create a new employee.
     */
    public function createEmployee(array $data): int;

    /**
     * Update an existing employee.
     */
    public function updateEmployee(int $id, array $data): bool;

    /**
     * Delete an employee.
     */
    public function deleteEmployee(int $id): bool;

    /**
     * Search employees.
     */
    public function searchEmployees(string $query, array $filters = [], int $page = 1, int $limit = 30): array;

    /**
     * Get organization hierarchy.
     */
    public function getOrganizationHierarchy(): array;

    /**
     * Get departments.
     */
    public function getDepartments(): array;

    /**
     * Get sections by department.
     */
    public function getSectionsByDepartment(int $departmentId): array;

    /**
     * Get subsections by section.
     */
    public function getSubsectionsBySection(int $sectionId): array;

    /**
     * Get offices.
     */
    public function getOffices(): array;

    /**
     * Get all contracts for an employee.
     */
    public function getEmployeeContracts(int $employeeId): array;

    /**
     * Get total contract count for an employee.
     */
    public function getEmployeeContractCount(int $employeeId): int;

    /**
     * Record the initial contract for an employee.
     */
    public function createEmployeeContract(int $employeeId, array $data): int;

    /**
     * Renew a contract for an employee.
     */
    public function renewEmployeeContract(int $employeeId, int $previousContractId): array;

    /**
     * Convert a contract-based employee to permanent employment
     * (employment_type = 'permanent', active contract dates cleared,
     * contract history preserved).
     */
    public function convertEmployeeToPermanent(int $employeeId): array;

    /**
     * Validate employee data.
     */
    public function validateEmployeeData(array $data, ?int $excludeId = null): array;
}
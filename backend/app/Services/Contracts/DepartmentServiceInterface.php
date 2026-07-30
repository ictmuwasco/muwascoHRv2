<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;

/**
 * Department Service Interface
 *
 * Defines the contract for department business logic operations.
 */
interface DepartmentServiceInterface extends ServiceInterface
{
    public function setDepartmentRepository(DepartmentRepositoryInterface $repository): void;
    public function setSectionRepository(SectionRepositoryInterface $repository): void;

    /**
     * Get all departments.
     */
    public function getAllDepartments(): array;

    /**
     * Get department by ID.
     */
    public function getDepartmentById(int $id): ?array;

    /**
     * Create a new department.
     */
    public function createDepartment(array $data): int;

    /**
     * Update an existing department.
     */
    public function updateDepartment(int $id, array $data): bool;

    /**
     * Delete a department.
     */
    public function deleteDepartment(int $id): bool;

    /**
     * Get department hierarchy.
     */
    public function getDepartmentHierarchy(): array;

    /**
     * Get sections by department.
     */
    public function getSections(int $departmentId): array;

    /**
     * Get subsections by section.
     */
    public function getSubsections(int $sectionId): array;

    /**
     * Validate department data.
     */
    public function validateDepartmentData(array $data, ?int $excludeId = null): array;
}
<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Department Repository Interface
 *
 * Defines the contract for department data access operations.
 */
interface DepartmentRepositoryInterface extends RepositoryInterface
{
    /**
     * Find department by ID with sections.
     */
    public function findWithSections(int $id): ?array;

    /**
     * Get all active departments.
     */
    public function getAllActive(): array;

    /**
     * Get sections by department ID.
     */
    public function getSections(int $departmentId): array;

    /**
     * Get subsections by section ID.
     */
    public function getSubsections(int $sectionId): array;

    /**
     * Check if department name exists.
     */
    public function nameExists(string $name, ?int $excludeId = null): bool;

    /**
     * Get department hierarchy.
     */
    public function getHierarchy(): array;
}
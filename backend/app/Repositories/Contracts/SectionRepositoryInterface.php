<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Section Repository Interface
 *
 * Defines the contract for section data access operations.
 */
interface SectionRepositoryInterface extends RepositoryInterface
{
    /**
     * Find section by ID with subsections.
     */
    public function findWithSubsections(int $id): ?array;

    /**
     * Get sections by department ID.
     */
    public function getByDepartment(int $departmentId): array;

    /**
     * Get subsections by section ID.
     */
    public function getSubsections(int $sectionId): array;

    /**
     * Check if section name exists in department.
     */
    public function nameExists(string $name, int $departmentId, ?int $excludeId = null): bool;
}
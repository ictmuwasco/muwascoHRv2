<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Office Repository Interface
 *
 * Defines the contract for office data access operations.
 */
interface OfficeRepositoryInterface extends RepositoryInterface
{
    /**
     * Get all active offices.
     */
    public function getAllActive(): array;

    /**
     * Find office by ID.
     */
    public function findById(int $id): ?array;

    /**
     * Check if office name exists.
     */
    public function nameExists(string $name, ?int $excludeId = null): bool;
}
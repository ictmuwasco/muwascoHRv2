<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Base Repository Interface
 *
 * Defines the contract that all repository implementations must follow.
 * This ensures consistency across the data access layer and enables
 * dependency injection and easy testing.
 */
interface RepositoryInterface
{
    /**
     * Find a record by its primary key.
     */
    public function findById(int $id): ?array;

    /**
     * Find all records.
     */
    public function findAll(): array;

    /**
     * Create a new record.
     */
    public function create(array $data): int;

    /**
     * Update an existing record.
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete a record by its primary key.
     */
    public function delete(int $id): bool;

    /**
     * Check if a record exists.
     */
    public function exists(int $id): bool;

    /**
     * Count total records.
     */
    public function count(): int;
}
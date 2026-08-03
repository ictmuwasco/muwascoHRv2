<?php

declare(strict_types=1);

namespace App\Services\Contracts;

/**
 * Base Service Interface
 *
 * Defines the contract that all service implementations must follow.
 * Services contain business logic and orchestrate repository operations.
 */
interface ServiceInterface
{
    /**
     * Set a dependency (for DI).
     */
    public function setDependency(string $name, mixed $dependency): void;

    /**
     * Get a dependency.
     */
    public function getDependency(string $name): mixed;
}
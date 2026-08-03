<?php

declare(strict_types=1);

namespace App\Validators\Contracts;

/**
 * Validator Interface
 *
 * Defines the contract for all validators in the application.
 */
interface ValidatorInterface
{
    /**
     * Validate the given data.
     *
     * @param array $data
     * @return array<string, string> Array of error messages (field => message)
     */
    public function validate(array $data): array;

    /**
     * Check if the validation passes.
     *
     * @param array $data
     * @return bool
     */
    public function passes(array $data): bool;

    /**
     * Check if the validation fails.
     *
     * @param array $data
     * @return bool
     */
    public function fails(array $data): bool;

    /**
     * Get all validation errors.
     *
     * @return array<string, string>
     */
    public function errors(): array;

    /**
     * Get the first error message for a given field.
     *
     * @param string $field
     * @return string|null
     */
    public function firstError(string $field): ?string;
}
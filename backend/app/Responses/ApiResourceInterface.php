<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * ApiResource Interface
 *
 * Defines the contract for all API resources.
 */
interface ApiResourceInterface
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(): array;

    /**
     * Transform the resource into JSON.
     */
    public function toJson(): string;
}
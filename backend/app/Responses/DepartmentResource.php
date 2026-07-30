<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * Department Resource
 *
 * Transforms department data for API responses.
 */
class DepartmentResource implements ApiResourceInterface
{
    private array $department;

    public function __construct(array $department)
    {
        $this->department = $department;
    }

    /**
     * Transform the department resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->department['id'] ?? null,
            'name' => $this->department['name'] ?? null,
            'description' => $this->department['description'] ?? null,
            'status' => $this->department['status'] ?? null,
            'created_at' => $this->department['created_at'] ?? null,
            'updated_at' => $this->department['updated_at'] ?? null,
        ];
    }

    /**
     * Transform the resource into JSON.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
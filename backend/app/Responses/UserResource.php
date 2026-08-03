<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * User Resource
 *
 * Transforms user data for API responses.
 */
class UserResource implements ApiResourceInterface
{
    private array $user;

    public function __construct(array $user)
    {
        $this->user = $user;
    }

    /**
     * Transform the user resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->user['id'] ?? null,
            'employee_id' => $this->user['employee_id'] ?? null,
            'first_name' => $this->user['first_name'] ?? null,
            'last_name' => $this->user['last_name'] ?? null,
            'surname' => $this->user['surname'] ?? null,
            'email' => $this->user['email'] ?? null,
            'role' => $this->user['role'] ?? null,
            'designation' => $this->user['designation'] ?? null,
            'phone' => $this->user['phone'] ?? null,
            'address' => $this->user['address'] ?? null,
            'gender' => $this->user['gender'] ?? null,
            'is_active' => $this->user['is_active'] ?? null,
            'last_login' => $this->user['last_login'] ?? null,
            'created_at' => $this->user['created_at'] ?? null,
            'updated_at' => $this->user['updated_at'] ?? null,
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
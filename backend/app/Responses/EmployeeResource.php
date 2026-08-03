<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * Employee Resource
 *
 * Transforms employee data for API responses.
 */
class EmployeeResource implements ApiResourceInterface
{
    private array $employee;

    public function __construct(array $employee)
    {
        $this->employee = $employee;
    }

    /**
     * Transform the employee resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->employee['id'] ?? null,
            'employee_id' => $this->employee['employee_id'] ?? null,
            'first_name' => $this->employee['first_name'] ?? null,
            'last_name' => $this->employee['last_name'] ?? null,
            'surname' => $this->employee['surname'] ?? null,
            'email' => $this->employee['email'] ?? null,
            'phone' => $this->employee['phone'] ?? null,
            'national_id' => $this->employee['national_id'] ?? null,
            'gender' => $this->employee['gender'] ?? null,
            'marital_status' => $this->employee['marital_status'] ?? null,
            'employee_type' => $this->employee['employee_type'] ?? null,
            'employee_status' => $this->employee['employee_status'] ?? null,
            'employment_date' => $this->employee['employment_date'] ?? null,
            'department_id' => $this->employee['department_id'] ?? null,
            'department' => $this->employee['department_name'] ?? null,
            'section_id' => $this->employee['section_id'] ?? null,
            'section' => $this->employee['section_name'] ?? null,
            'office_id' => $this->employee['office_id'] ?? null,
            'office' => $this->employee['office_name'] ?? null,
            'designation' => $this->employee['designation'] ?? null,
            'address' => $this->employee['address'] ?? null,
            'created_at' => $this->employee['created_at'] ?? null,
            'updated_at' => $this->employee['updated_at'] ?? null,
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
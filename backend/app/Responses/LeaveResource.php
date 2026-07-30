<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * Leave Resource
 *
 * Transforms leave application data for API responses.
 */
class LeaveResource implements ApiResourceInterface
{
    private array $leave;

    public function __construct(array $leave)
    {
        $this->leave = $leave;
    }

    /**
     * Transform the leave resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->leave['id'] ?? null,
            'employee_id' => $this->leave['employee_id'] ?? null,
            'employee_name' => $this->leave['employee_name'] ?? null,
            'leave_type_id' => $this->leave['leave_type_id'] ?? null,
            'leave_type' => $this->leave['leave_type_name'] ?? null,
            'start_date' => $this->leave['start_date'] ?? null,
            'end_date' => $this->leave['end_date'] ?? null,
            'days_requested' => $this->leave['days_requested'] ?? null,
            'reason' => $this->leave['reason'] ?? null,
            'status' => $this->leave['status'] ?? null,
            'applied_at' => $this->leave['applied_at'] ?? null,
            'approved_at' => $this->leave['approved_at'] ?? null,
            'approved_by' => $this->leave['approved_by'] ?? null,
            'created_at' => $this->leave['created_at'] ?? null,
            'updated_at' => $this->leave['updated_at'] ?? null,
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
<?php

declare(strict_types=1);

namespace App\Responses;

/**
 * Attendance Resource
 *
 * Transforms attendance data for API responses.
 */
class AttendanceResource implements ApiResourceInterface
{
    private array $attendance;

    public function __construct(array $attendance)
    {
        $this->attendance = $attendance;
    }

    /**
     * Transform the attendance resource into an array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->attendance['id'] ?? null,
            'employee_id' => $this->attendance['employee_id'] ?? null,
            'employee_name' => $this->attendance['employee_name'] ?? null,
            'date' => $this->attendance['date'] ?? null,
            'clock_in_time' => $this->attendance['clock_in_time'] ?? null,
            'clock_out_time' => $this->attendance['clock_out_time'] ?? null,
            'status' => $this->attendance['status'] ?? null,
            'notes' => $this->attendance['notes'] ?? null,
            'created_at' => $this->attendance['created_at'] ?? null,
            'updated_at' => $this->attendance['updated_at'] ?? null,
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
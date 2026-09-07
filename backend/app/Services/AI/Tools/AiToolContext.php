<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Helpers\Auth;
use App\Repositories\EmployeeRepository;

/**
 * AiToolContext — server-side per-turn context for controlled tool execution.
 *
 * Carries the authenticated user id (resolved by the controller/middleware,
 * NEVER by the model), the user's employee record (via the existing
 * EmployeeRepository::findByUserId — reused, not duplicated), and a small
 * permission cache backed by the hybrid authorization system
 * (Auth::hasPermission). PHP 8.0 compatible (no readonly properties).
 */
final class AiToolContext
{
    /** @var int */
    private $userId;

    /** @var array|null employees row (with department/section names) or null */
    private $employee;

    /** @var array<string, bool> */
    private $permCache = [];

    private function __construct(int $userId, ?array $employee)
    {
        $this->userId   = $userId;
        $this->employee = $employee;
    }

    public static function forUser(int $userId): self
    {
        $employee = null;
        try {
            $employee = (new EmployeeRepository())->findByUserId($userId);
        } catch (\Throwable $e) {
            \logger()->warning('AI tool context: employee lookup failed', [
                'user_id' => $userId,
            ]);
        }
        return new self($userId, is_array($employee) ? $employee : null);
    }

    public function userId(): int
    {
        return $this->userId;
    }

    /** PK of the caller's employee record, or null when the user has none. */
    public function employeeId(): ?int
    {
        return isset($this->employee['id']) ? (int) $this->employee['id'] : null;
    }

    /** department_id of the caller's employee record, or null. */
    public function departmentId(): ?int
    {
        return isset($this->employee['department_id']) ? (int) $this->employee['department_id'] : null;
    }

    /** section_id of the caller's employee record, or null. */
    public function sectionId(): ?int
    {
        return isset($this->employee['section_id']) ? (int) $this->employee['section_id'] : null;
    }

    /** True when the user has an employee record attached. */
    public function hasEmployee(): bool
    {
        return $this->employeeId() !== null;
    }

    /** The raw employee row (with joined department/section names) or null. */
    public function employee(): ?array
    {
        return $this->employee;
    }

    /**
     * Effective permission check ('module:action') backed by the hybrid
     * authorization system. Results are cached per context (per turn).
     */
    public function can(string $module, string $action): bool
    {
        $key = $module . ':' . $action;
        if (!array_key_exists($key, $this->permCache)) {
            try {
                $this->permCache[$key] = Auth::getInstance()->hasPermission($module, $action);
            } catch (\Throwable $e) {
                $this->permCache[$key] = false;
            }
        }
        return $this->permCache[$key];
    }

    /** Shared database helper. */
    public function db(): \App\Helpers\Database
    {
        return \db();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyEmployeeProfile — the caller's own employment profile, built from the
 * employee record already resolved in the server-side context (no extra
 * query, no model-supplied ids).
 *
 * SENSITIVE-FIELD FILTERING (Phase 5/11): national ID, phone, email, date of
 * birth, home address, next-of-kin and salary scale are deliberately NOT
 * returned — the tool result is placed into the provider conversation and
 * must carry only work-related fields the conversation needs.
 */
final class GetMyEmployeeProfileTool implements AiToolInterface
{
    public function name(): string
    {
        return 'getMyEmployeeProfile';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own work profile: name, employee number, '
            . 'designation, department, section, employment type and status, and hire date. '
            . 'Use this when the employee asks who they are in the system or which '
            . 'department/section they belong to. Contains no contact or identity documents.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function requiredPermission(): string
    {
        return '';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        if (!$ctx->hasEmployee()) {
            return [
                'error' => 'No employee record is linked to your account.',
            ];
        }

        $row = $ctx->employee();

        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        return [
            'name'             => $name !== '' ? $name : null,
            'employee_number'  => isset($row['employee_id']) ? (string) $row['employee_id'] : null,
            'designation'      => isset($row['designation']) ? (string) $row['designation'] : null,
            'department'       => isset($row['department_name']) ? (string) $row['department_name'] : null,
            'section'          => isset($row['section_name']) ? (string) $row['section_name'] : null,
            'employee_type'    => isset($row['employee_type']) ? (string) $row['employee_type'] : null,
            'employment_type'  => isset($row['employment_type']) ? (string) $row['employment_type'] : null,
            'employee_status'  => isset($row['employee_status']) ? (string) $row['employee_status'] : null,
            'hire_date'        => isset($row['hire_date']) ? (string) $row['hire_date'] : null,
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        return 'profile: ' . ($payload['name'] ?? '?')
            . ' (' . ($payload['designation'] ?? 'no designation') . ')';
    }
}

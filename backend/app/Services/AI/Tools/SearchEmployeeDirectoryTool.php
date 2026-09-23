<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * searchEmployeeDirectory — find colleagues by employee number, name or surname.
 *
 * ROLE RESTRICTION: gated on employees:view (heads, HR, MD, super admin and
 * officers — the same roles that can open the Employees page). Roles without it
 * (employee, board chair, delegated sessions that resolve to none) never see
 * the tool.
 *
 * DATA SCOPE: AiUnitScope narrows every query to the caller's own unit unless
 * they hold organisation-wide oversight (dashboard:hr_insights / PME / Audit).
 * An officer therefore only ever finds colleagues in their own department or
 * section — matching what the Employees page already exposes to them.
 *
 * SENSITIVE-FIELD FILTERING: name, employee number, designation, unit,
 * employment type and status only. No email, phone, national ID, salary,
 * date of birth, address or next-of-kin — the payload enters the provider
 * conversation and must remain work-directory level data.
 */
final class SearchEmployeeDirectoryTool implements AiToolInterface
{
    private const MAX_LIMIT = 25;
    private const DEFAULT_LIMIT = 10;

    public function name(): string
    {
        return 'searchEmployeeDirectory';
    }

    public function description(): string
    {
        return 'Search the employee directory the caller is allowed to see, by employee number, '
            . 'first name, last name or surname (at least 2 characters). Returns name, employee '
            . 'number, designation, department, section, employment type and status. Use this for '
            . 'questions such as "who is the procurement officer?", "find employee 119" or '
            . '"list the staff in my section". Contact details, identity numbers and salary are '
            . 'not available through this tool.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Employee number, first name, last name or surname to search for '
                                     . '(minimum 2 characters).',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'description' => 'Maximum number of matches to return (default 10).',
                ],
            ],
            'required'   => ['query'],
        ];
    }

    /** Directory search: same gate as the Employees page. */
    public function requiredPermission(): string
    {
        return 'employees:view';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if (mb_strlen($query) < 2) {
            return [
                'error' => 'Provide at least 2 characters of the employee number, name or surname.',
            ];
        }

        $limit = isset($args['limit']) && is_numeric($args['limit'])
            ? (int) $args['limit']
            : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $like = '%' . $this->escapeLike($query) . '%';
        [$scope, $scopeParams, $scopeTypes] = AiUnitScope::where($ctx, 'e');

        $rows = $ctx->db()->fetchAll(
            'SELECT e.employee_id AS employee_number,
                    CONCAT_WS(\' \', e.first_name, e.last_name) AS name,
                    e.designation, e.employee_type, e.employment_type, e.employee_status,
                    d.name AS department, s.name AS section
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN sections s ON s.id = e.section_id
             WHERE e.employee_status = \'active\'
               AND (e.employee_id LIKE ?
                    OR e.first_name LIKE ?
                    OR e.last_name LIKE ?
                    OR e.surname LIKE ?
                    OR CONCAT_WS(\' \', e.first_name, e.last_name) LIKE ?)' . $scope . '
             ORDER BY e.first_name ASC, e.last_name ASC
             LIMIT ' . $limit,
            'sssss' . $scopeTypes,
            array_merge([$like, $like, $like, $like, $like], $scopeParams)
        );

        $employees = [];
        foreach ($rows as $r) {
            $employees[] = [
                'employee_number' => (string) $r['employee_number'],
                'name'            => trim((string) $r['name']),
                'designation'     => $r['designation'] !== null ? (string) $r['designation'] : null,
                'department'      => $r['department'] !== null ? (string) $r['department'] : null,
                'section'         => $r['section'] !== null ? (string) $r['section'] : null,
                'employment_type' => $r['employment_type'] !== null ? (string) $r['employment_type'] : null,
                'employee_status' => $r['employee_status'] !== null ? (string) $r['employee_status'] : null,
            ];
        }

        $count = count($employees);
        $note = 'Work-directory fields only; contact, identity and salary data are not available.';
        if ($count === 0) {
            $note = 'No active employee matched "' . $query . '" within ' . AiUnitScope::label($ctx) . '.';
        } elseif ($count >= $limit) {
            $note = 'Only the first ' . $limit . ' matches are shown; refine the search for more.';
        }

        return [
            'query'     => $query,
            'scope'     => AiUnitScope::label($ctx),
            'count'     => $count,
            'employees' => $employees,
            'note'      => $note,
        ];
    }

    /** Escape LIKE wildcards so a user search cannot widen the result set. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }

        return (int) ($payload['count'] ?? 0) . ' employee match(es) for "'
            . (string) ($payload['query'] ?? '') . '" in ' . (string) ($payload['scope'] ?? 'unit');
    }
}

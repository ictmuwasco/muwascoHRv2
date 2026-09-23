<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getEmployeeLeaveBalance — leave balances for another employee, for the
 * managers who look them up.
 *
 * ROLE RESTRICTION: gated on leave:manage (department / section / sub-section
 * heads, HR manager, Managing Director, super admin). An ordinary employee
 * cannot call it — they keep getMyLeaveBalance for their OWN record.
 *
 * DATA SCOPE: the target employee is resolved strictly inside the caller's unit
 * (AiUnitScope); a head searching an employee outside their department/section
 * simply gets "no match". Organisation-wide callers may resolve anyone.
 *
 * SENSITIVE-FIELD FILTERING: per-leave-type allocated / brought forward / used
 * / remaining days for the active financial year only. Application reasons,
 * medical certificates, attachments and any personal contact data are never
 * read — so the assistant can answer "how many leave days does X have left?"
 * without becoming a backdoor into leave files.
 */
final class GetEmployeeLeaveBalanceTool implements AiToolInterface
{
    /** Candidate rows returned when a search is ambiguous. */
    private const MAX_CANDIDATES = 5;

    public function name(): string
    {
        return 'getEmployeeLeaveBalance';
    }

    public function description(): string
    {
        return 'Retrieve the leave balances (allocated, brought forward, used and remaining days per '
            . 'leave type) of ONE employee the caller is allowed to view, for the current active '
            . 'financial year. Identify the employee by employee number (preferred) or by name. '
            . 'Use this when a manager or HR user asks how much leave a specific employee has left. '
            . 'It never returns application reasons or attachments.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'employee' => [
                    'type'        => 'string',
                    'description' => 'Employee number (e.g. 119) or first/last name of the employee '
                                     . 'whose balance is requested.',
                ],
            ],
            'required'   => ['employee'],
        ];
    }

    /** Leave administration: heads, HR, MD and super admin. */
    public function requiredPermission(): string
    {
        return 'leave:manage';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $query = trim((string) ($args['employee'] ?? ''));
        if ($query === '') {
            return ['error' => 'Provide the employee number or name whose leave balance is needed.'];
        }

        [$scope, $scopeParams, $scopeTypes] = AiUnitScope::where($ctx, 'e');
        $like = '%' . $this->escapeLike($query) . '%';

        $candidates = $ctx->db()->fetchAll(
            'SELECT e.id, e.employee_id AS employee_number,
                    CONCAT_WS(\' \', e.first_name, e.last_name) AS name,
                    d.name AS department, s.name AS section
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN sections s ON s.id = e.section_id
             WHERE e.employee_status = \'active\'
               AND (e.employee_id = ?
                    OR e.employee_id LIKE ?
                    OR CONCAT_WS(\' \', e.first_name, e.last_name) LIKE ?)' . $scope . '
             ORDER BY e.employee_id ASC
             LIMIT ' . self::MAX_CANDIDATES,
            'sss' . $scopeTypes,
            array_merge([$query, $like, $like], $scopeParams)
        );

        if ($candidates === []) {
            return [
                'found' => false,
                'scope' => AiUnitScope::label($ctx),
                'query' => $query,
                'note'  => 'No active employee matching "' . $query . '" is within '
                           . AiUnitScope::label($ctx) . '.',
            ];
        }

        // Prefer an exact employee-number hit; otherwise require a single match
        // so the assistant asks the user to disambiguate instead of guessing.
        $target = null;
        foreach ($candidates as $c) {
            if ((string) $c['employee_number'] === $query) {
                $target = $c;
                break;
            }
        }
        if ($target === null && count($candidates) === 1) {
            $target = $candidates[0];
        }

        if ($target === null) {
            $matches = [];
            foreach ($candidates as $c) {
                $matches[] = trim((string) $c['name']) . ' (' . (string) $c['employee_number'] . ')';
            }

            return [
                'found'            => false,
                'multiple_matches' => true,
                'scope'            => AiUnitScope::label($ctx),
                'query'            => $query,
                'matches'          => $matches,
                'note'             => 'Several employees match. Ask the user which one they mean '
                                      . '(an employee number is unambiguous).',
            ];
        }

        $fy = $ctx->db()->fetchOne(
            'SELECT id, year_name FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1'
        );
        if ($fy === null) {
            return [
                'found'          => false,
                'employee'       => $this->employeeRef($target),
                'financial_year' => null,
                'note'           => 'No active financial year is configured, so no leave balances exist yet.',
            ];
        }

        $rows = $ctx->db()->fetchAll(
            'SELECT lt.name AS leave_type, b.allocated_days, b.brought_forward_days,
                    b.used_days, b.remaining_days
             FROM employee_leave_balances b
             JOIN leave_types lt ON lt.id = b.leave_type_id
             WHERE b.employee_id = ? AND b.financial_year_id = ?
             ORDER BY lt.name ASC',
            'ii',
            [(int) $target['id'], (int) $fy['id']]
        );

        $balances = [];
        $totalRemaining = 0.0;
        foreach ($rows as $r) {
            $remaining = round((float) $r['remaining_days'], 1);
            $totalRemaining += $remaining;
            $balances[] = [
                'leave_type'      => (string) $r['leave_type'],
                'allocated_days'  => round((float) $r['allocated_days'], 1),
                'brought_forward' => round((float) $r['brought_forward_days'], 1),
                'used_days'       => round((float) $r['used_days'], 1),
                'remaining_days'  => $remaining,
            ];
        }

        return [
            'found'           => true,
            'employee'        => $this->employeeRef($target),
            'scope'           => AiUnitScope::label($ctx),
            'financial_year'  => (string) $fy['year_name'],
            'balances'        => $balances,
            'total_remaining' => round($totalRemaining, 1),
            'note'            => 'Balances only: application reasons, certificates and attachments '
                                 . 'are not available through this tool.',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string|null>
     */
    private function employeeRef(array $row): array
    {
        return [
            'employee_number' => (string) $row['employee_number'],
            'name'            => trim((string) $row['name']),
            'department'      => $row['department'] !== null ? (string) $row['department'] : null,
            'section'         => $row['section'] !== null ? (string) $row['section'] : null,
        ];
    }

    /** Escape LIKE wildcards so a search cannot widen the result set. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        if (empty($payload['found'])) {
            if (!empty($payload['multiple_matches'])) {
                return 'ambiguous employee search (' . count($payload['matches'] ?? []) . ' matches)';
            }

            return 'no matching employee within ' . (string) ($payload['scope'] ?? 'unit');
        }

        return 'balances for ' . ($payload['employee']['name'] ?? '?')
            . ' (' . ($payload['employee']['employee_number'] ?? '?') . '), FY '
            . ($payload['financial_year'] ?? '?') . ': total remaining '
            . ($payload['total_remaining'] ?? 0) . ' day(s)';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyLeaveBalance — the caller's OWN leave balances for the active
 * financial year, straight from employee_leave_balances (migration 007).
 * Owner-scoped by the server-side context; model arguments are ignored for
 * authorization. Numbers come from the ledger — the model only narrates.
 */
final class GetMyLeaveBalanceTool implements AiToolInterface
{
    public function name(): string
    {
        return 'getMyLeaveBalance';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own leave balances (allocated, brought forward, '
            . 'used and remaining days per leave type) for the current active financial year. '
            . 'Use this whenever the employee asks about their remaining or used leave days.';
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
        return 'leave:view';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $employeeId = $ctx->employeeId();
        if ($employeeId === null) {
            return [
                'error' => 'No employee record is linked to your account, so no leave balance can be shown.',
            ];
        }

        $fy = $ctx->db()->fetchOne(
            'SELECT id, year_name FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1'
        );
        if ($fy === null) {
            return [
                'financial_year' => null,
                'balances'       => [],
                'note'           => 'No active financial year is configured in the HR system.',
            ];
        }

        $rows = $ctx->db()->fetchAll(
            'SELECT lt.name AS leave_type, b.allocated_days, b.brought_forward_days,
                    b.used_days, b.remaining_days
             FROM employee_leave_balances b
             JOIN leave_types lt ON lt.id = b.leave_type_id
             WHERE b.employee_id = ? AND b.financial_year_id = ?
             ORDER BY lt.name',
            'ii',
            [$employeeId, (int) $fy['id']]
        );

        $balances = [];
        $totalRemaining = 0.0;
        foreach ($rows as $row) {
            $remaining = round((float) $row['remaining_days'], 1);
            $totalRemaining += $remaining;
            $balances[] = [
                'leave_type'      => (string) $row['leave_type'],
                'allocated_days'  => round((float) $row['allocated_days'], 1),
                'brought_forward' => round((float) $row['brought_forward_days'], 1),
                'used_days'       => round((float) $row['used_days'], 1),
                'remaining_days'  => $remaining,
            ];
        }

        return [
            'financial_year'  => (string) $fy['year_name'],
            'balances'        => $balances,
            'total_remaining' => round($totalRemaining, 1),
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $count = count($payload['balances'] ?? []);
        $total = $payload['total_remaining'] ?? 0;
        return $count . ' leave type(s) for FY ' . ($payload['financial_year'] ?? '?')
            . '; total remaining ' . $total . ' day(s)';
    }
}

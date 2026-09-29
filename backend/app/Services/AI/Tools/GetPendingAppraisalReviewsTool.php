<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getPendingAppraisalReviews — appraisals waiting for action in caller's unit.
 * Gated on performance:supervise (same gate as AppraisalController reads).
 * Unit-scoped via AiUnitScope with COALESCE fallback for legacy rows whose
 * employee_department_id snapshot is NULL. Returns state only — never
 * free-text comments (employee/supervisor/dept-head discussion content).
 */
final class GetPendingAppraisalReviewsTool implements AiToolInterface
{
    private const MAX_ROWS = 25;

    // Verified against the live table: draft, submitted, awaiting_employee,
    // completed, pending_dept_approval (+ awaiting_submission / under_review
    // kept for forward compatibility).
    private const PENDING_STATUSES = [
        'draft',
        'awaiting_employee',
        'awaiting_submission',
        'submitted',
        'pending_dept_approval',
        'under_review',
    ];

    public function name(): string
    {
        return 'getPendingAppraisalReviews';
    }

    public function description(): string
    {
        return 'List the appraisals still awaiting action in the caller\'s unit (oldest '
            . 'first): employee name and number, department, appraisal cycle, workflow '
            . 'status and average score when recorded. Use this for questions like '
            . '"which appraisals are pending for my team?". Completed, rejected and '
            . 'cancelled appraisals are excluded. Comments are not available.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    public function requiredPermission(): string
    {
        return 'performance:supervise';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        // AiUnitScope pins e.department_id / e.section_id; the appraisal table
        // carries only a (nullable) department snapshot, so scope STRICTLY on the
        // live employee row — the same rows OrgScope would expose for this caller.
        [$scope, $scopeParams, $scopeTypes] = AiUnitScope::where($ctx, 'e');

        $placeholders = implode(',', array_fill(0, count(self::PENDING_STATUSES), '?'));

        // Group by every selected non-aggregated column: MySQL 8 ONLY_FULL_GROUP_BY
        // (production) rejects GROUP BY a.id alone, while MariaDB (local dev)
        // historically tolerated it — the full list works on both.
        $rows = $ctx->db()->fetchAll(
            'SELECT a.id AS appraisal_id,'
            . ' e.employee_id AS employee_number,'
            . ' CONCAT_WS(\' \', e.first_name, e.last_name) AS employee_name,'
            . ' COALESCE(d.name, \'Unassigned\') AS department,'
            . ' ac.name AS cycle,'
            . ' a.status,'
            . ' AVG(s.score) AS average_score'
            . ' FROM employee_appraisals a'
            . ' JOIN employees e ON e.id = a.employee_id'
            . ' LEFT JOIN departments d ON d.id = COALESCE(a.employee_department_id, e.department_id)'
            . ' LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id'
            . ' LEFT JOIN appraisal_scores s ON s.employee_appraisal_id = a.id'
            . ' WHERE a.status IN (' . $placeholders . ')' . $scope
            . ' GROUP BY a.id, e.employee_id, e.first_name, e.last_name,'
            . ' d.name, a.employee_department_id, e.department_id, ac.name, a.status'
            . ' ORDER BY a.created_at ASC, a.id ASC'
            . ' LIMIT ' . self::MAX_ROWS,
            str_repeat('s', count(self::PENDING_STATUSES)) . $scopeTypes,
            array_merge(self::PENDING_STATUSES, $scopeParams)
        );

        $total = (int) $ctx->db()->fetchValue(
            'SELECT COUNT(*) FROM employee_appraisals a'
            . ' JOIN employees e ON e.id = a.employee_id'
            . ' WHERE a.status IN (' . $placeholders . ')' . $scope,
            str_repeat('s', count(self::PENDING_STATUSES)) . $scopeTypes,
            array_merge(self::PENDING_STATUSES, $scopeParams)
        );

        $pending = [];
        foreach ($rows as $row) {
            $avg = $row['average_score'] !== null ? round((float) $row['average_score'], 2) : null;
            $pending[] = [
                'employee' => trim((string) $row['employee_name']),
                'employee_number' => (string) $row['employee_number'],
                'department' => (string) $row['department'],
                'cycle' => $row['cycle'] !== null ? (string) $row['cycle'] : null,
                'status' => (string) $row['status'],
                // Null (not 0) when no indicator has been scored yet.
                'average_score' => $avg,
            ];
        }

        return [
            'scope' => AiUnitScope::label($ctx),
            'total_pending' => $total,
            'shown' => count($pending),
            'pending' => $pending,
            'note' => 'Scores are averages of recorded indicator scores only; '
                . 'pending appraisals often have none yet. Comments are not available.',
        ];
    }

    public function summarize(array $payload): string
    {
        return (int) ($payload['total_pending'] ?? 0)
            . ' pending appraisal(s) in ' . (string) ($payload['scope'] ?? 'unit');
    }
}

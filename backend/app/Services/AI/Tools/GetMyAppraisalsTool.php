<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyAppraisals — the caller's OWN appraisals across cycles, owner-scoped by
 * the server-side context (their employee_id, never a model-supplied id).
 *
 * Available to every role via performance:feedback — the one performance grant
 * every seeded role holds (bod chairman, dept heads, employee, HR, managers,
 * MD, officers, section heads, super admin) — so officers/employees without
 * performance:view can still ask "how did I perform?".
 *
 * SENSITIVE-FIELD FILTERING: status + aggregated scores ONLY. The appraisal
 * row's employee_comment / supervisors_comment / dept_head_* free text is
 * never selected: it routinely contains sensitive discussion content and the
 * model must not repeat it.
 */
final class GetMyAppraisalsTool implements AiToolInterface
{
    private const MAX_ROWS = 10;

    public function name(): string
    {
        return 'getMyAppraisals';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own appraisals (cycle name, '
            . 'workflow status and average indicator score when recorded), newest '
            . 'first. Use this for questions like "how did I perform?", "what is '
            . 'the status of my appraisal?" or "show my appraisal scores". '
            . 'Written comments are not available through this tool.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'required' => []];
    }

    /** Every seeded role holds performance:feedback (self-service gate). */
    public function requiredPermission(): string
    {
        return 'performance:feedback';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $employeeId = $ctx->employeeId();
        if ($employeeId === null) {
            return [
                'error' => 'No employee record is linked to your account, so no appraisals can be shown.',
            ];
        }

        $rows = $ctx->db()->fetchAll(
            'SELECT ac.name AS cycle, a.status, AVG(s.score) AS average_score,'
            . ' COUNT(s.id) AS indicator_count, a.created_at'
            . ' FROM employee_appraisals a'
            . ' LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id'
            . ' LEFT JOIN appraisal_scores s ON s.employee_appraisal_id = a.id'
            . ' WHERE a.employee_id = ?'
            . ' GROUP BY a.id, ac.name, a.status, a.created_at'
            . ' ORDER BY a.created_at DESC, a.id DESC'
            . ' LIMIT ' . self::MAX_ROWS,
            'i',
            [$employeeId]
        );

        $appraisals = [];
        foreach ($rows as $row) {
            $appraisals[] = [
                'cycle' => $row['cycle'] !== null ? (string) $row['cycle'] : null,
                'status' => (string) $row['status'],
                'average_score' => $row['average_score'] !== null
                    ? round((float) $row['average_score'], 2)
                    : null,
                'indicators_scored' => (int) $row['indicator_count'],
            ];
        }

        return [
            'count' => count($appraisals),
            'appraisals' => $appraisals,
            'note' => 'Scores are averages of recorded indicator scores; appraisals '
                . 'still in progress often have none yet. Written comments are not '
                . 'available through this tool.',
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }

        return (int) ($payload['count'] ?? 0) . ' own appraisal(s)';
    }
}

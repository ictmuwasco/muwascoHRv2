<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyAppraisalStatus — the caller's OWN appraisal records across cycles.
 *
 * SELF-SERVICE: owner-scoped by the server-side context (employee_id from the
 * session, never from model arguments), so a caller can only ever see their
 * own workflow state — never a colleague's.
 *
 * ROLE RESTRICTION: gated on performance:feedback, the one performance action
 * seeded for EVERY role (employee, officer, heads, HR, MD, super admin). An
 * account without an employee record still cannot call it (the executor's
 * empty-permission branch denies when hasEmployee() is false).
 *
 * SENSITIVE-FIELD FILTERING: cycle name, appraisal status and the average
 * score only. Free-text comments (employee_comment, supervisors_comment,
 * dept_head decisions/notes) are deliberately NEVER returned — they routinely
 * carry sensitive performance discussion and the status question does not
 * need them.
 */
final class GetMyAppraisalStatusTool implements AiToolInterface
{
    private const MAX_ROWS = 10;

    public function name(): string
    {
        return 'getMyAppraisalStatus';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own appraisal status across cycles '
            . '(cycle name, appraisal status such as draft, submitted or completed, '
            . 'and average score when recorded). Use this for questions like "what is '
            . 'my appraisal status?", "has my appraisal been completed?" or '
            . '"what is my appraisal score?". Returns only the caller\'s own record; '
            . 'reviewer comments are not available through this tool.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    /** Every role holds performance:feedback (own appraisal participation). */
    public function requiredPermission(): string
    {
        return 'performance:feedback';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $employeeId = $ctx->employeeId();
        if ($employeeId === null) {
            return [
                'error' => 'No employee record is linked to your account, so no appraisal status can be shown.',
            ];
        }

        $rows = $ctx->db()->fetchAll(
            'SELECT ac.name AS cycle_name, ac.status AS cycle_status, '
            . 'a.status AS appraisal_status, a.submitted_at, '
            . 'ROUND(COALESCE((SELECT AVG(s.score) FROM appraisal_scores s '
            . 'WHERE s.employee_appraisal_id = a.id), 0), 2) AS average_score, '
            . '(SELECT COUNT(*) FROM appraisal_scores s '
            . 'WHERE s.employee_appraisal_id = a.id) AS scored_count '
            . 'FROM employee_appraisals a '
            . 'JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id '
            . 'WHERE a.employee_id = ? '
            . 'ORDER BY ac.start_date DESC, ac.id DESC '
            . 'LIMIT ' . self::MAX_ROWS,
            'i',
            [$employeeId]
        );

        $appraisals = [];
        foreach ($rows as $row) {
            $appraisals[] = [
                'cycle'           => (string) $row['cycle_name'],
                'cycle_status'    => (string) $row['cycle_status'],
                'status'          => (string) $row['appraisal_status'],
                'status_label'    => $this->statusLabel((string) $row['appraisal_status']),
                'average_score'   => (int) $row['scored_count'] > 0
                    ? round((float) $row['average_score'], 2)
                    : null,
                'submitted_on'    => $row['submitted_at'] !== null
                    ? substr((string) $row['submitted_at'], 0, 10)
                    : null,
            ];
        }

        return [
            'count'      => count($appraisals),
            'appraisals' => $appraisals,
            'note'       => $appraisals === []
                ? 'No appraisal record exists for you yet.'
                : 'Reviewer comments are not available through this tool; '
                    . 'open the Appraisal page for the full detail.',
        ];
    }

    /** Human label for the employee_appraisals workflow status. */
    private function statusLabel(string $status): string
    {
        $labels = [
            'draft'                 => 'Draft (not yet submitted)',
            'awaiting_employee'     => 'Awaiting your review',
            'awaiting_submission'   => 'Awaiting submission',
            'submitted'             => 'Submitted (awaiting review)',
            'pending_dept_approval' => 'Awaiting department head decision',
            'under_review'          => 'Under review',
            'completed'             => 'Completed',
            'rejected'              => 'Returned for correction',
            'cancelled'             => 'Cancelled',
        ];

        return $labels[$status] ?? $status;
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $count = (int) ($payload['count'] ?? 0);
        if ($count === 0) {
            return 'no appraisal record for the caller';
        }
        $latest = $payload['appraisals'][0] ?? [];
        return $count . ' appraisal(s); latest: '
            . ($latest['cycle'] ?? '?') . ' = ' . ($latest['status'] ?? '?');
    }
}

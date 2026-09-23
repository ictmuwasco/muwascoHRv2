<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getPendingLeaveApprovals — the leave applications waiting for a decision in
 * the caller's unit.
 *
 * ROLE RESTRICTION: gated on leave:approve (department / section / sub-section
 * heads, HR manager, Managing Director, super admin). Officers and employees
 * never see this tool, so a non-approver cannot use the assistant to browse
 * colleagues' leave.
 *
 * DATA SCOPE: applicants are narrowed by AiUnitScope — heads see their own
 * department/section, organisation-wide roles see everything (oldest first,
 * capped).
 *
 * SENSITIVE-FIELD FILTERING: the application `reason` is deliberately NEVER
 * returned. It routinely carries health and family information, and the
 * approval decision does not require the assistant to repeat it.
 */
final class GetPendingLeaveApprovalsTool implements AiToolInterface
{
    /** Row cap for the queue payload. */
    private const MAX_ROWS = 25;

    /** Workflow statuses that still await a decision. */
    private const PENDING_STATUSES = [
        'pending',
        'pending_subsection_head',
        'pending_section_head',
        'pending_manager',
        'pending_dept_head',
        'pending_hr_manager',
        'pending_hr',
        'pending_managing_director',
        'pending_bod_chair',
    ];

    public function name(): string
    {
        return 'getPendingLeaveApprovals';
    }

    public function description(): string
    {
        return 'List the leave applications still awaiting approval in the caller\'s unit (oldest '
            . 'first): employee name and number, department, section, leave type, start and end '
            . 'dates, days requested, workflow stage and when it was applied. Use this for '
            . 'questions like "what leave is waiting for me to approve?". The application reason '
            . 'is not available through this tool.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    /** Approvers only. */
    public function requiredPermission(): string
    {
        return 'leave:approve';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PENDING_STATUSES), '?'));
        $statusTypes  = str_repeat('s', count(self::PENDING_STATUSES));

        [$scope, $scopeParams, $scopeTypes] = AiUnitScope::where($ctx, 'e');

        $where = 'WHERE la.status IN (' . $placeholders . ')' . $scope;
        $types = $statusTypes . $scopeTypes;
        $params = array_merge(self::PENDING_STATUSES, $scopeParams);

        $total = (int) $ctx->db()->fetchValue(
            'SELECT COUNT(*)
             FROM leave_applications la
             JOIN employees e ON e.id = la.employee_id ' . $where,
            $types,
            $params
        );

        $rows = $ctx->db()->fetchAll(
            'SELECT la.id, la.status, la.start_date, la.end_date, la.days_requested, la.applied_at,
                    CONCAT_WS(\' \', e.first_name, e.last_name) AS employee_name,
                    e.employee_id AS employee_number,
                    d.name AS department,
                    s.name AS section,
                    lt.name AS leave_type
             FROM leave_applications la
             JOIN employees e ON e.id = la.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN sections s ON s.id = e.section_id
             JOIN leave_types lt ON lt.id = la.leave_type_id ' . $where . '
             ORDER BY la.applied_at ASC, la.id ASC
             LIMIT ' . self::MAX_ROWS,
            $types,
            $params
        );

        $pending = [];
        foreach ($rows as $r) {
            $pending[] = [
                'application_id'  => (int) $r['id'],
                'employee'        => trim((string) $r['employee_name']),
                'employee_number' => (string) $r['employee_number'],
                'department'      => $r['department'] !== null ? (string) $r['department'] : null,
                'section'         => $r['section'] !== null ? (string) $r['section'] : null,
                'leave_type'      => (string) $r['leave_type'],
                'start_date'      => (string) $r['start_date'],
                'end_date'        => (string) $r['end_date'],
                'days_requested'  => (int) $r['days_requested'],
                'stage'           => $this->stageLabel((string) $r['status']),
                'applied_on'      => $r['applied_at'] !== null
                    ? substr((string) $r['applied_at'], 0, 10)
                    : null,
            ];
        }

        return [
            'scope'          => AiUnitScope::label($ctx),
            'total_pending'  => $total,
            'shown'          => count($pending),
            'pending'        => $pending,
            'note'           => $pending === []
                ? 'Nothing is waiting for approval in ' . AiUnitScope::label($ctx) . '.'
                : 'The application reason is not available through this tool; '
                    . 'open the Leave Management page to review a request in full.',
        ];
    }

    /** Human label for the workflow stage of a pending application. */
    private function stageLabel(string $status): string
    {
        $labels = [
            'pending'                    => 'Pending (initial review)',
            'pending_subsection_head'    => 'Awaiting subsection head',
            'pending_section_head'       => 'Awaiting section head',
            'pending_manager'            => 'Awaiting manager',
            'pending_dept_head'          => 'Awaiting department head',
            'pending_hr_manager'         => 'Awaiting HR manager',
            'pending_hr'                 => 'Awaiting HR',
            'pending_managing_director'  => 'Awaiting managing director',
            'pending_bod_chair'          => 'Awaiting board chair',
        ];

        return $labels[$status] ?? $status;
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }

        return (int) ($payload['total_pending'] ?? 0) . ' pending leave application(s) in '
            . (string) ($payload['scope'] ?? 'unit');
    }
}

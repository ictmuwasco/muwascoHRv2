<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyLeaveApplications — the caller's OWN recent leave applications,
 * owner-scoped by the server-side context. Includes the rejection reason of
 * the caller's own applications (their data), but never other employees'.
 */
final class GetMyLeaveApplicationsTool implements AiToolInterface
{
    private const MAX_LIMIT = 10;

    public function name(): string
    {
        return 'getMyLeaveApplications';
    }

    public function description(): string
    {
        return 'List the signed-in employee\'s own recent leave applications (type, dates, status, '
            . 'and rejection reason if any), newest first. Optionally filter by status '
            . '(pending, approved, rejected or cancelled). Use this for questions about '
            . 'the employee\'s own leave requests and their outcomes.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['pending', 'approved', 'rejected', 'cancelled', 'all'],
                    'description' => 'Filter by application status. Default: all.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => 10,
                    'description' => 'How many applications to return (newest first). Default 5, max 10.',
                ],
            ],
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
                'error' => 'No employee record is linked to your account, so no leave applications can be shown.',
            ];
        }

        $status = isset($args['status']) && is_string($args['status'])
            ? strtolower($args['status'])
            : 'all';
        if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) {
            $status = 'all';
        }
        $limit = isset($args['limit']) ? (int) $args['limit'] : 5;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $sql = 'SELECT lt.name AS leave_type, l.start_date, l.end_date, l.status,
                       l.applied_at, l.rejection_reason
                FROM leave_applications l
                JOIN leave_types lt ON lt.id = l.leave_type_id
                WHERE l.employee_id = ?';
        $types = 'i';
        $params = [$employeeId];

        if ($status !== 'all') {
            $sql .= ' AND l.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $sql .= ' ORDER BY l.applied_at DESC LIMIT ' . $limit;

        $rows = $ctx->db()->fetchAll($sql, $types, $params);

        $applications = [];
        foreach ($rows as $row) {
            $applications[] = [
                'leave_type'       => (string) $row['leave_type'],
                'start_date'       => (string) $row['start_date'],
                'end_date'         => (string) $row['end_date'],
                'status'           => (string) $row['status'],
                'applied_at'       => (string) $row['applied_at'],
                'rejection_reason' => $row['rejection_reason'] !== null
                    ? (string) $row['rejection_reason']
                    : null,
            ];
        }

        return [
            'filter_status' => $status,
            'count'         => count($applications),
            'applications'  => $applications,
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        return (int) ($payload['count'] ?? 0) . ' application(s)'
            . ' (status filter: ' . ($payload['filter_status'] ?? 'all') . ')';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getSystemErrorSummary — super-admin-only system-health briefing.
 * Reads error_groups + application_errors (same tables as System Monitor).
 * Gate: system_errors:view_sensitive (seeded ONLY to super_admin) + hard
 * super_admin role check inside execute() as defense-in-depth.
 */
final class GetSystemErrorSummaryTool implements AiToolInterface
{
    private const MAX_GROUPS = 10;
    private const MAX_ENDPOINTS = 8;

    public function name(): string
    {
        return 'getSystemErrorSummary';
    }

    public function description(): string
    {
        return 'Summarize recent system errors for a SUPER ADMIN: open error groups, '
            . 'today\'s volume, top failing endpoints and fix recommendations. '
            . 'ONLY callable by super admins. Use for "what errors happened today?", '
            . '"summarize system errors", "what should we fix first?". '
            . 'Never invent errors; report only what the tool returns.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [
                'days' => [
                    'type' => 'integer',
                    'description' => 'Look-back window in days (1-30, default 7).',
                    'minimum' => 1,
                    'maximum' => 30,
                ],
                'severity' => [
                    'type' => 'string',
                    'description' => 'Optional filter: CRITICAL, HIGH, MEDIUM, LOW.',
                    'enum' => ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'],
                ],
            ],
            'required' => [],
        ];
    }

    public function requiredPermission(): string
    {
        return 'system_errors:view_sensitive';
    }
    public function execute(AiToolContext $ctx, array $args): array
    {
        if (!$this->isSuperAdmin($ctx)) {
            return ['error' => 'System error data is restricted to super admins.'];
        }
        $days = (int) ($args['days'] ?? 7);
        $days = max(1, min(30, $days));
        $sev = strtoupper(trim((string) ($args['severity'] ?? '')));
        if (!in_array($sev, ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'], true)) { $sev = ''; }
        try {
            $groups = $this->openGroups($ctx, $sev);
            $today = $this->todayVolume($ctx, $sev);
            $endpoints = $this->topEndpoints($ctx, $days, $sev);
        } catch (\Throwable $e) {
            \logger()->error('AI system-error summary failed', ['user_id' => $ctx->userId()]);
            return ['error' => 'Error data could not be retrieved right now.'];
        }
        $items = [];
        foreach (array_slice($groups, 0, self::MAX_GROUPS) as $g) {
            $items[] = [
                'title' => (string) ($g['title'] ?? 'Untitled error'),
                'module' => (string) ($g['module'] ?? 'System'),
                'severity' => (string) ($g['severity'] ?? 'MEDIUM'),
                'status' => (string) ($g['status'] ?? 'NEW'),
                'occurrences' => (int) ($g['occurrence_count'] ?? 0),
                'affected_users' => (int) ($g['affected_user_count'] ?? 0),
                'last_seen' => isset($g['last_seen_at']) ? substr((string) $g['last_seen_at'], 0, 16) : null,
                'recommendation' => $this->recommendation(
                    (string) ($g['module'] ?? ''), (string) ($g['sample_endpoint'] ?? ''), (string) ($g['title'] ?? '')),
            ];
        }
        return [
            'scope' => 'system-wide (super admin)',
            'window_days' => $days,
            'open_groups' => count($groups),
            'shown' => count($items),
            'today_volume' => $today,
            'groups' => $items,
            'top_endpoints' => array_slice($endpoints, 0, self::MAX_ENDPOINTS),
            'instruction' => 'Summarize groups by severity then occurrences with recommendation each. '
                . 'End with the single highest-priority fix. Never reveal stack traces or user ids.',
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) { return 'unavailable: ' . $payload['error']; }
        return (int) ($payload['open_groups'] ?? 0) . ' open error group(s); '
            . (int) ($payload['today_volume']['total'] ?? 0) . ' error(s) today';
    }

    private function isSuperAdmin(AiToolContext $ctx): bool
    {
        try {
            $row = $ctx->db()->fetchOne('SELECT role FROM users WHERE id = ?', 'i', [$ctx->userId()]);
        } catch (\Throwable $e) { return false; }
        $role = strtolower(trim((string) ($row['role'] ?? '')));
        return $role === 'super_admin' || $role === 'admin';
    }
    private function openGroups(AiToolContext $ctx, string $sev): array
    {
        $sql = 'SELECT title, module, severity, status, occurrence_count,'
            . ' affected_user_count, last_seen_at, sample_endpoint'
            . " FROM error_groups WHERE status NOT IN ('RESOLVED','IGNORED')";
        $types = ''; $params = [];
        if ($sev !== '') { $sql .= ' AND severity = ?'; $types .= 's'; $params[] = $sev; }
        $sql .= ' ORDER BY FIELD(severity,\'CRITICAL\',\'HIGH\',\'MEDIUM\',\'LOW\'),'
            . ' occurrence_count DESC LIMIT ' . self::MAX_GROUPS;
        $rows = $ctx->db()->fetchAll($sql, $types, $params);
        return is_array($rows) ? $rows : [];
    }

    private function todayVolume(AiToolContext $ctx, string $sev): array
    {
        $sql = "SELECT COUNT(*) AS total, COALESCE(SUM(severity='CRITICAL'),0) AS critical,"
            . " COALESCE(SUM(severity='HIGH'),0) AS high,"
            . " COALESCE(SUM(source='client'),0) AS client"
            . ' FROM application_errors WHERE created_at >= CURDATE()';
        $types = ''; $params = [];
        if ($sev !== '') { $sql .= ' AND severity = ?'; $types .= 's'; $params[] = $sev; }
        $row = $ctx->db()->fetchOne($sql, $types, $params);
        $row = is_array($row) ? $row : [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'critical' => (int) ($row['critical'] ?? 0),
            'high' => (int) ($row['high'] ?? 0),
            'client' => (int) ($row['client'] ?? 0),
        ];
    }

    private function topEndpoints(AiToolContext $ctx, int $days, string $sev): array
    {
        $sql = 'SELECT endpoint, http_method, COUNT(*) AS errors,'
            . ' COUNT(DISTINCT user_id) AS users FROM application_errors'
            . ' WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)'
            . " AND endpoint IS NOT NULL AND endpoint <> ''";
        $types = 'i'; $params = [$days];
        if ($sev !== '') { $sql .= ' AND severity = ?'; $types .= 's'; $params[] = $sev; }
        $sql .= ' GROUP BY endpoint, http_method ORDER BY errors DESC LIMIT ' . self::MAX_ENDPOINTS;
        $rows = $ctx->db()->fetchAll($sql, $types, $params);
        if (!is_array($rows)) { return []; }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['endpoint' => (string) ($r['endpoint'] ?? ''),
                'method' => $r['http_method'] !== null ? (string) $r['http_method'] : null,
                'errors' => (int) ($r['errors'] ?? 0), 'users' => (int) ($r['users'] ?? 0)];
        }
        return $out;
    }

    private function recommendation(string $module, string $endpoint, string $title): string
    {
        $hay = strtolower($module . ' ' . $endpoint . ' ' . $title);
        if (str_contains($hay, 'polic')) {
            return 'Check the published policy document and section tree; re-ingest the manual if sections fail.';
        }
        if (str_contains($hay, 'leave')) {
            return 'Review leave approval chain snapshots and applicant hierarchy; fix missing approver assignments.';
        }
        if (str_contains($hay, 'attendance') || str_contains($hay, 'gps')) {
            return 'Verify device capture and office GPS radius; check clock API latency.';
        }
        if (str_contains($hay, 'login') || str_contains($hay, 'auth') || str_contains($hay, 'session')) {
            return 'Check session refresh handling and rate limits; confirm the account is active.';
        }
        if (str_contains($hay, 'ai/') || str_contains($hay, 'assistant')) {
            return 'Check AI provider availability, timeouts and quota; inspect provider logs.';
        }
        if (str_contains($hay, 'client') || str_contains($hay, 'react') || str_contains($hay, 'network')) {
            return 'Reproduce in browser console; check build version and API base URL.';
        }
        if (str_contains($hay, 'database') || str_contains($hay, 'sql') || str_contains($hay, 'timeout')) {
            return 'Inspect slow-query logs and DB latency; add missing indexes.';
        }
        return 'Open the group in System Monitor, inspect the latest occurrence and assign a developer.';
    }
}


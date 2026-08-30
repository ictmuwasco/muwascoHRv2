<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Services\AuditService;
use App\Services\ErrorTracking\ErrorTrackerService;
use App\Services\ErrorTracking\RequestIdService;
use App\Services\ErrorTracking\SeverityClassifier;

/**
 * MonitoringController
 *
 * REST API backing the System Monitoring dashboard (errors, groups,
 * performance, health) and the browser-side client-error collector.
 *
 * RBAC (module `system_errors`, seeded in migration 031):
 *   view           - read dashboards/lists/details
 *   manage         - change status/severity
 *   assign         - (re)assign a developer
 *   resolve        - resolution notes / fixed version / resolving status
 *   view_sensitive - stack traces + sanitized payloads/headers
 */
class MonitoringController extends BaseController
{
    private const VALID_STATUSES  = ['NEW', 'ACKNOWLEDGED', 'INVESTIGATING', 'FIXED', 'VERIFIED', 'RESOLVED', 'IGNORED'];

    /** RESTRICTED columns hidden without system_errors:view_sensitive. */
    private const SENSITIVE_FIELDS = ['stack_trace', 'request_payload', 'request_query', 'request_headers'];

    // ------------------------------------------------------------------
    // Statistics (dashboard overview)
    // ------------------------------------------------------------------

    /**
     * GET /api/system/errors/stats
     */
    public function stats(): void
    {
        $this->requirePermission('system_errors', 'view');

        try {
            $today = db()->fetchOne(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(severity = 'CRITICAL'), 0) AS critical,
                        COALESCE(SUM(severity = 'HIGH'), 0)     AS high,
                        COALESCE(SUM(source = 'client'), 0)     AS client,
                        COALESCE(SUM(status_code >= 500), 0)    AS failed_requests,
                        COUNT(DISTINCT user_id)                 AS affected_users
                 FROM application_errors WHERE created_at >= CURDATE()"
            ) ?? [];

            $openGroups = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM error_groups WHERE status NOT IN ('RESOLVED','IGNORED')"
            );

            $criticalOpen = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM error_groups WHERE severity = 'CRITICAL' AND status NOT IN ('RESOLVED','IGNORED')"
            );

            $perfToday = db()->fetchOne(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(threshold_level = 'critical'), 0) AS critical,
                        COALESCE(AVG(duration_ms), 0)                  AS avg_ms,
                        COALESCE(MAX(duration_ms), 0)                  AS max_ms
                 FROM performance_events WHERE created_at >= CURDATE()"
            ) ?? [];

            $series = [];
            $stmt = db()->query(
                "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS bucket, COUNT(*) AS total
                 FROM application_errors
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY bucket ORDER BY bucket ASC",
                '',
                []
            );
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $series[] = ['bucket' => $row['bucket'], 'total' => (int) $row['total']];
            }
            $stmt->close();

            $bySeverity = $this->rowsToArray(db()->query(
                "SELECT severity, COUNT(*) AS total
                 FROM application_errors
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY severity",
                '',
                []
            ));

            $byModule = $this->rowsToArray(db()->query(
                "SELECT g.module, COUNT(*) AS total
                 FROM application_errors ae
                 INNER JOIN error_groups g ON g.id = ae.error_group_id
                 WHERE ae.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY g.module ORDER BY total DESC LIMIT 8",
                '',
                []
            ));

            $topEndpoints = $this->rowsToArray(db()->query(
                "SELECT ae.endpoint,
                        MAX(ae.http_method)        AS http_method,
                        COUNT(*)                   AS errors,
                        COUNT(DISTINCT ae.user_id) AS users,
                        MAX(ae.created_at)         AS last_seen
                 FROM application_errors ae
                 WHERE ae.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND ae.endpoint IS NOT NULL
                 GROUP BY ae.endpoint ORDER BY errors DESC LIMIT 10",
                '',
                []
            ));

            $this->success([
                'today' => [
                    'total'           => (int) ($today['total'] ?? 0),
                    'critical'        => (int) ($today['critical'] ?? 0),
                    'high'            => (int) ($today['high'] ?? 0),
                    'client'          => (int) ($today['client'] ?? 0),
                    'failed_requests' => (int) ($today['failed_requests'] ?? 0),
                    'affected_users'  => (int) ($today['affected_users'] ?? 0),
                    'slow_requests'   => (int) ($perfToday['total'] ?? 0),
                    'slow_critical'   => (int) ($perfToday['critical'] ?? 0),
                    'avg_duration_ms' => (int) round((float) ($perfToday['avg_ms'] ?? 0)),
                    'max_duration_ms' => (int) ($perfToday['max_ms'] ?? 0),
                ],
                'open_groups'   => $openGroups,
                'critical_open' => $criticalOpen,
                'hourly_series' => $series,
                'by_severity'   => $bySeverity,
                'by_module'     => $byModule,
                'top_endpoints' => $topEndpoints,
                'spikes'        => $this->detectSpikes(),
                'application'   => $this->applicationMeta(),
            ], 'Error statistics retrieved successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] stats failed: ' . $e->getMessage());
            $this->error('Failed to compute error statistics', 500);
        }
    }

    /**
     * Groups whose current hourly rate greatly exceeds their lifetime average -
     * deliberately simple, config-driven anomaly detection (§24).
     */
    private function detectSpikes(): array
    {
        $minHourly = max(2, (int) \config('observability.notifications.spike_min_hourly', 5));
        $factor    = 4; // current hour must be >= 4x the lifetime hourly average

        return $this->rowsToArray(db()->query(
            "SELECT g.id, g.fingerprint, g.title, g.severity,
                    (SELECT COUNT(*) FROM application_errors ae
                      WHERE ae.error_group_id = g.id
                        AND ae.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS last_hour,
                    GREATEST(1, ROUND(g.occurrence_count /
                        GREATEST(1, TIMESTAMPDIFF(HOUR, g.first_seen_at, NOW())))) AS avg_per_hour
             FROM error_groups g
             WHERE g.status NOT IN ('RESOLVED','IGNORED')
             HAVING last_hour >= ? AND last_hour >= avg_per_hour * ?
             ORDER BY last_hour DESC LIMIT 5",
            'ii',
            [$minHourly, $factor]
        ));
    }

    /** Drain a mysqli_stmt SELECT into plain arrays. */
    private function rowsToArray(\mysqli_stmt $stmt): array
    {
        $res  = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** Deployment identity surfaced on dashboards and detail pages. */
    private function applicationMeta(): array
    {
        return [
            'environment'   => (string) \config('app.env', 'production'),
            'version'       => (string) \config('observability.version', ''),
            'git_commit'    => config('observability.git_commit'),
            'deployment_id' => config('observability.deployment_id'),
        ];
    }

    // ------------------------------------------------------------------
    // Error groups
    // ------------------------------------------------------------------

    /**
     * GET /api/system/errors/groups
     *
     * Filters: severity, status, module, environment, assigned_to,
     *          search (fingerprint/title LIKE), date_from/date_to (last_seen).
     */
    public function groups(): void
    {
        $this->requirePermission('system_errors', 'view');

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $sort    = in_array($_GET['sort'] ?? '', ['last_seen_at', 'first_seen_at', 'occurrence_count', 'affected_user_count'], true)
            ? (string) $_GET['sort'] : 'last_seen_at';
        $order   = strtoupper((string) ($_GET['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $types, $params] = $this->groupWhere();

        try {
            $total = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM error_groups g {$where}",
                $types,
                $params
            );

            $sql    = "SELECT g.* FROM error_groups g {$where} ORDER BY g.{$sort} {$order} LIMIT ? OFFSET ?";
            $types  .= 'ii';
            $params = array_merge($params, [$perPage, ($page - 1) * $perPage]);

            $stmt = db()->query($sql, $types, $params);
            $rows = $this->rowsToArray($stmt);

            $this->success([
                'data'      => $rows,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => (int) ceil($total / $perPage),
            ], 'Error groups retrieved successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] groups failed: ' . $e->getMessage());
            $this->error('Failed to list error groups', 500);
        }
    }

    /** Dynamic WHERE for the groups list - all inputs whitelisted/bound. */
    private function groupWhere(): array
    {
        $where  = ['WHERE 1=1'];
        $types  = '';
        $params = [];

        $append = function (string $sql, string $type, mixed $value) use (&$where, &$types, &$params): void {
            $where[]  = $sql;
            $types   .= $type;
            $params[] = $value;
        };

        if (($v = trim((string) ($_GET['severity'] ?? ''))) !== '' && SeverityClassifier::isValidSeverity($v)) {
            $append('AND g.severity = ?', 's', strtoupper($v));
        }

        if (($v = strtoupper(trim((string) ($_GET['status'] ?? '')))) !== '' && in_array($v, self::VALID_STATUSES, true)) {
            $append('AND g.status = ?', 's', $v);
        }

        if (($v = trim((string) ($_GET['module'] ?? ''))) !== '') {
            $append('AND g.module = ?', 's', $v);
        }

        if (($v = trim((string) ($_GET['environment'] ?? ''))) !== '') {
            $append('AND g.environment = ?', 's', $v);
        }

        if (($v = (int) ($_GET['assigned_to'] ?? 0)) > 0) {
            $append('AND g.assigned_to = ?', 'i', $v);
        } elseif (isset($_GET['assigned_to']) && $_GET['assigned_to'] === 'unassigned') {
            $where[] = 'AND g.assigned_to IS NULL';
        }

        if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
            $like     = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $before   = count($params);
            $where[]  = 'AND (g.fingerprint LIKE ? OR g.title LIKE ? OR g.fingerprint_hash = ?)';
            $types   .= 'sss';
            array_splice($params, $before, 0, [$like, $like, $search]);
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $param => $op) {
            $value = trim((string) ($_GET[$param] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                $append("AND g.last_seen_at {$op} ?", 's', $value . ($op === '>=' ? ' 00:00:00' : ' 23:59:59'));
            }
        }

        return [implode(' ', $where), $types, $params];
    }

    // ------------------------------------------------------------------
    // Occurrence detail + lifecycle management
    // ------------------------------------------------------------------

    /**
     * GET /api/system/errors/{uuid}
     *
     * Returns the occurrence, its group, sibling occurrences, related audit
     * events (same request_id) and performance event. RESTRICTED fields are
     * masked unless the caller holds system_errors:view_sensitive.
     */
    public function show(string $uuid): void
    {
        $this->requirePermission('system_errors', 'view');

        try {
            // Accept EITHER an occurrence uuid OR a numeric error-group id
            // (dashboard tables link by group; the latest occurrence anchors).
            if (ctype_digit($uuid)) {
                $error = db()->fetchOne(
                    "SELECT * FROM application_errors WHERE error_group_id = ? ORDER BY id DESC LIMIT 1",
                    'i',
                    [(int) $uuid]
                );
            } else {
                $stmt = db()->query(
                    "SELECT * FROM application_errors WHERE error_uuid = ? LIMIT 1",
                    's',
                    [$uuid]
                );
                $res   = $stmt->get_result();
                $error = $res->fetch_assoc();
                $stmt->close();
            }

            if ($error === null) {
                $this->notFound('Error not found');
            }

            $canSeeSensitive = $this->hasPermission('system_errors', 'view_sensitive');
            if (!$canSeeSensitive) {
                foreach (self::SENSITIVE_FIELDS as $field) {
                    if (!empty($error[$field])) {
                        $error[$field] = '[RESTRICTED]';
                    }
                }
            }

            // Parent group summary.
            $group = db()->fetchOne(
                "SELECT * FROM error_groups WHERE id = ?",
                'i',
                [(int) $error['error_group_id']]
            );

            // Latest sibling occurrences of the same group.
            $stmt = db()->query(
                "SELECT error_uuid, request_id, frontend_error_id, source, environment,
                        application_version, severity, status_code, user_id, ip_address,
                        endpoint, http_method, created_at
                 FROM application_errors
                 WHERE error_group_id = ? AND error_uuid <> ?
                 ORDER BY id DESC LIMIT 20",
                'is',
                [(int) $error['error_group_id'], $uuid]
            );
            $siblings = $this->rowsToArray($stmt);

            // Correlated audit trail (Phase 4 bridge): same request id.
            $auditEvents = [];
            if (!empty($error['request_id'])) {
                $stmt = db()->query(
                    "SELECT id, module, action, description, user_name_snapshot,
                            user_role_snapshot, status, created_at
                     FROM audit_logs WHERE request_id = ? ORDER BY id ASC LIMIT 25",
                    's',
                    [$error['request_id']]
                );
                $auditEvents = $this->rowsToArray($stmt);
            }

            // Correlated performance event for the same request.
            $performance = null;
            if (!empty($error['request_id'])) {
                $performance = db()->fetchOne(
                    "SELECT id, duration_ms, threshold_level, status_code, memory_kb, created_at
                     FROM performance_events WHERE request_id = ? ORDER BY id DESC LIMIT 1",
                    's',
                    [$error['request_id']]
                );
            }

            $this->success([
                'error'         => $error,
                'group'         => $group,
                'occurrences'   => $siblings,
                'audit_events'  => $auditEvents,
                'performance'   => $performance,
                'can_sensitive' => $canSeeSensitive,
                'application'   => $this->applicationMeta(),
            ], 'Error detail retrieved successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] show failed: ' . $e->getMessage());
            $this->error('Failed to load error detail', 500);
        }
    }

    /**
     * POST /api/system/errors/groups/{id}/manage
     *
     * Body: { status?, severity?, assigned_to?, resolution_notes?, fixed_version? }
     * Each field transition is permission-checked individually and mirrored
     * into the EXISTING audit trail - monitoring actions stay auditable.
     */
    public function manage(int $id): void
    {
        $body = $this->getJsonBody();
        if (empty($body)) {
            $this->error('No changes supplied', 422);
        }

        try {
            $group = db()->fetchOne("SELECT * FROM error_groups WHERE id = ?", 'i', [$id]);
            if ($group === null) {
                $this->notFound('Error group not found');
            }

            $updates = [];
            $changes = [];
            $actorId = $this->getUserId();

            // Status ------------------------------------------------------
            if (array_key_exists('status', $body)) {
                $status = strtoupper(trim((string) $body['status']));
                if (!in_array($status, self::VALID_STATUSES, true)) {
                    $this->error('Invalid status value', 422);
                }
                $needsResolve = in_array($status, ['RESOLVED', 'VERIFIED', 'FIXED'], true);
                $this->requirePermission('system_errors', $needsResolve ? 'resolve' : 'manage');

                $updates['status'] = $status;
                $changes['status'] = [$group['status'], $status];

                if ($needsResolve) {
                    $updates['resolved_at'] = date('Y-m-d H:i:s');
                    $updates['resolved_by'] = $actorId > 0 ? $actorId : null;
                } else {
                    // Re-opening clears prior resolution markers (§9 reopen).
                    $updates['resolved_at'] = null;
                    $updates['resolved_by'] = null;
                }
            }

            // Severity ----------------------------------------------------
            if (array_key_exists('severity', $body)) {
                $severity = strtoupper(trim((string) $body['severity']));
                if (!SeverityClassifier::isValidSeverity($severity)) {
                    $this->error('Invalid severity value', 422);
                }
                $this->requirePermission('system_errors', 'manage');
                $updates['severity'] = $severity;
                $changes['severity'] = [$group['severity'], $severity];
            }

            // Assignment --------------------------------------------------
            if (array_key_exists('assigned_to', $body)) {
                $this->requirePermission('system_errors', 'assign');
                $assignedTo             = $body['assigned_to'] === null ? null : (int) $body['assigned_to'];
                $updates['assigned_to'] = $assignedTo;
                $changes['assigned_to'] = [$group['assigned_to'], $assignedTo];
            }

            // Resolution notes / fixed version ----------------------------
            foreach (['resolution_notes', 'fixed_version'] as $field) {
                if (array_key_exists($field, $body)) {
                    $this->requirePermission('system_errors', 'resolve');
                    $value           = $body[$field] === null ? null : mb_substr(trim(strip_tags((string) $body[$field])), 0, 2000);
                    $updates[$field] = $value;
                    $changes[$field] = ['[previous]', $value !== null ? mb_substr((string) $value, 0, 80) : null];
                }
            }

            if (empty($updates)) {
                $this->error('No recognized changes supplied', 422);
            }

            $updates['updated_at'] = date('Y-m-d H:i:s');
            db()->update('error_groups', $updates, 'id = ?', 'i', [$id]);

            // Audit through the EXISTING audit system - never a parallel one.
            AuditService::getInstance()->log(
                'system_errors',
                'update',
                sprintf('Updated error group #%d (%s)', $id, (string) $group['title']),
                [
                    'target_type' => 'ErrorGroup',
                    'target_id'   => $id,
                    'old_values'  => array_map(static fn ($c) => $c[0], $changes),
                    'new_values'  => array_map(static fn ($c) => $c[1], $changes),
                    'metadata'    => ['request_id' => RequestIdService::current()],
                ]
            );

            $fresh = db()->fetchOne("SELECT * FROM error_groups WHERE id = ?", 'i', [$id]);
            $this->success(['group' => $fresh], 'Error group updated successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] manage failed: ' . $e->getMessage());
            $this->error('Failed to update error group', 500);
        }
    }

    // ------------------------------------------------------------------
    // Client error collector (browser -> backend)
    // ------------------------------------------------------------------

    /**
     * POST /api/system/client-errors
     *
     * Public endpoint (any user may report; anonymous allowed) receiving the
     * sanitized React ErrorBoundary / interceptor payloads. Everything is
     * re-sanitized server-side before storage. Never rejects the SPA because
     * collection hiccupped.
     */
    public function clientError(): void
    {
        $report = $this->getJsonBody();
        if (empty($report) || empty($report['message'])) {
            $this->error('A message is required', 422);
        }

        // Naive abuse guard: cap payload size before processing.
        $encoded = json_encode($report, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 60000) {
            $this->error('Payload too large', 413);
        }

        try {
            $frontendErrorId = ErrorTrackerService::getInstance()->captureClientReport($report);
            $this->success(
                ['frontend_error_id' => $frontendErrorId ?? ''],
                'Client error received',
                201
            );
        } catch (\Throwable $e) {
            error_log('[Monitoring] client error store failed: ' . $e->getMessage());
            $this->success(['frontend_error_id' => ''], 'Client error received', 201);
        }
    }

    // ------------------------------------------------------------------
    // Performance events
    // ------------------------------------------------------------------

    /**
     * GET /api/system/performance
     *
     * Filters: threshold_level (warning|slow|critical), endpoint, date range.
     */
    public function performance(): void
    {
        $this->requirePermission('system_errors', 'view');

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

        $where  = ['WHERE 1=1'];
        $types  = '';
        $params = [];

        $level = strtolower(trim((string) ($_GET['threshold_level'] ?? '')));
        if (in_array($level, ['warning', 'slow', 'critical'], true)) {
            $where[]  = 'threshold_level = ?';
            $types   .= 's';
            $params[] = $level;
        }

        $endpoint = trim((string) ($_GET['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $where[]  = 'endpoint LIKE ?';
            $types   .= 's';
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $endpoint) . '%';
        }

        foreach (['date_from' => '>=', 'date_to' => '<='] as $param => $op) {
            $value = trim((string) ($_GET[$param] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                $where[]  = "created_at {$op} ?";
                $types   .= 's';
                $params[] = $value . ($op === '>=' ? ' 00:00:00' : ' 23:59:59');
            }
        }

        $clause = implode(' AND ', $where);

        try {
            $total = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM performance_events {$clause}",
                $types,
                $params
            );

            $summary = db()->fetchOne(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(threshold_level = 'slow'), 0)     AS slow,
                        COALESCE(SUM(threshold_level = 'critical'), 0) AS critical,
                        COALESCE(ROUND(AVG(duration_ms)), 0)           AS avg_ms,
                        COALESCE(MAX(duration_ms), 0)                  AS max_ms
                 FROM performance_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            ) ?? [];

            $sql    = "SELECT * FROM performance_events {$clause} ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $types  .= 'ii';
            $params = array_merge($params, [$perPage, ($page - 1) * $perPage]);
            $stmt   = db()->query($sql, $types, $params);
            $rows   = $this->rowsToArray($stmt);

            $this->success([
                'data'       => $rows,
                'summary'    => [
                    'total'    => (int) ($summary['total'] ?? 0),
                    'slow'     => (int) ($summary['slow'] ?? 0),
                    'critical' => (int) ($summary['critical'] ?? 0),
                    'avg_ms'   => (int) ($summary['avg_ms'] ?? 0),
                    'max_ms'   => (int) ($summary['max_ms'] ?? 0),
                ],
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
                'pages'      => (int) ceil($total / $perPage),
                // Always concrete - never let a missing config key become null.
                'thresholds' => array_merge(
                    ['enabled' => true, 'warning_ms' => 2000, 'slow_ms' => 4000, 'critical_ms' => 8000],
                    is_array($t = \config('observability.performance')) ? $t : []
                ),
            ], 'Performance events retrieved successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] performance failed: ' . $e->getMessage());
            $this->error('Failed to list performance events', 500);
        }
    }

    // ------------------------------------------------------------------
    // System health
    // ------------------------------------------------------------------

    /**
     * GET /api/system/health
     *
     * Cheap liveness snapshot: DB latency, current error pressure vs previous
     * hour, open criticals, slow requests, deployment identity.
     */
    public function health(): void
    {
        $this->requirePermission('system_errors', 'view');

        try {
            $started = microtime(true);
            db()->fetchValue('SELECT 1');
            $dbLatencyMs = (int) round((microtime(true) - $started) * 1000);

            $errorsLastHour = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM application_errors WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );

            $errorsPrevHour = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM application_errors
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                   AND created_at <  DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );

            $openCritical = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM error_groups
                 WHERE severity = 'CRITICAL' AND status NOT IN ('RESOLVED','IGNORED')"
            );

            $slowLastHour = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM performance_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );

            $status = 'healthy';
            if ($dbLatencyMs > 500 || $openCritical > 0 || $errorsLastHour > max(20, $errorsPrevHour * 5)) {
                $status = 'degraded';
            }

            $this->success([
                'status'           => $status,
                'db_latency_ms'    => $dbLatencyMs,
                'errors_last_hour' => $errorsLastHour,
                'errors_prev_hour' => $errorsPrevHour,
                'open_critical'    => $openCritical,
                'slow_last_hour'   => $slowLastHour,
                'application'      => $this->applicationMeta(),
                'server_time'      => date('Y-m-d H:i:s'),
            ], 'System health retrieved successfully');
        } catch (\Throwable $e) {
            error_log('[Monitoring] health failed: ' . $e->getMessage());
            $this->success([
                'status'       => 'down',
                'db_reachable' => false,
                'application'  => $this->applicationMeta(),
                'server_time'  => date('Y-m-d H:i:s'),
            ], 'System health check reported failures');
        }
    }
}

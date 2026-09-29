<?php

declare(strict_types=1);

namespace App\Controllers\Security;

use App\Controllers\BaseController;
use App\Services\Security\SecurityAiAnalysisService;
use App\Services\Security\SecurityEventService;
use App\Services\Security\SecurityIncidentService;
use App\Services\Security\SecurityRiskEngine;
use App\Services\Security\VulnerabilityService;

/**
 * SecurityDashboardController — SOC dashboard APIs.
 */
class SecurityDashboardController extends BaseController
{
    public function overviewAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $today = date('Y-m-d');
            $eventStats = SecurityEventService::getInstance()->getEventStats($today, $today);
            $incidentStats = SecurityIncidentService::getInstance()->getIncidentStats();
            $posture = SecurityRiskEngine::getInstance()->calculatePosture();

            $this->success([
                'events_today' => $eventStats['total'],
                'critical_events' => $eventStats['critical'],
                'active_incidents' => $incidentStats['active'],
                'critical_incidents' => $incidentStats['critical'],
                'posture' => $posture,
                'incident_stats' => $incidentStats,
                'event_stats' => $eventStats,
            ]);
        } catch (\Exception $e) {
            \logger()->error('Security overview error', ['error' => $e->getMessage()]);
            $this->error('Failed to load security overview', 500);
        }
    }

    public function appraisalAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $db = \db();
            $events = $db->fetchAll(
                "SELECT id, event_type, severity, user_id, route, resource_type, resource_id,
                        action_taken, description, detected_at
                 FROM security_events
                 WHERE resource_type = 'EmployeeAppraisal' OR route LIKE '%/appraisals%'
                 ORDER BY detected_at DESC LIMIT 25"
            );
            $counts = $db->fetchOne(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(action_taken IN ('DENIED', 'BLOCKED')), 0) AS denied,
                        COALESCE(SUM(action_taken = 'RATE_LIMITED'), 0) AS rate_limited,
                        COALESCE(SUM(event_type IN ('IDOR_ENUMERATION', 'IDOR_ATTEMPT')), 0) AS object_attempts
                 FROM security_events
                 WHERE resource_type = 'EmployeeAppraisal' OR route LIKE '%/appraisals%'"
            );
            $audit = $db->fetchAll(
                "SELECT action, COUNT(*) AS count
                 FROM audit_logs
                 WHERE module = 'Performance' AND action LIKE 'APPRAISAL_%'
                 GROUP BY action ORDER BY count DESC"
            );
            $routes = array_values(array_filter(
                \ApiRouter::getRouteRegistry(),
                static fn (array $route): bool => str_contains($route['path'] ?? '', '/appraisals')
            ));
            $routeCount = count($routes);
            $permissionDefaults = $db->fetchAll(
                "SELECT role, action, is_granted
                 FROM role_permissions
                 WHERE module = 'performance' AND action IN ('supervise','score','approve','feedback')
                 ORDER BY role, action"
            );
            $this->success([
                'counts' => $counts ?: ['total' => 0, 'denied' => 0, 'rate_limited' => 0, 'object_attempts' => 0],
                'recent_events' => $events,
                'audit_actions' => $audit,
                'route_count' => $routeCount,
                'permission_defaults' => $permissionDefaults,
                'policy' => [
                    'employee_ownership_enforced' => true,
                    'organizational_scope_enforced' => true,
                    'officer_supervisory_hard_deny' => true,
                    'state_transitions_audited' => true,
                    'sensitive_comments_in_general_audit' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Appraisal security summary error', ['error' => $e->getMessage()]);
            $this->error('Failed to load appraisal security summary', 500);
        }
    }

    public function eventsAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $filters = [
                'event_type' => $_GET['event_type'] ?? '',
                'severity' => $_GET['severity'] ?? '',
                'user_id' => $_GET['user_id'] ?? '',
                'ip_address' => $_GET['ip_address'] ?? '',
                'resource_type' => $_GET['resource_type'] ?? '',
                'route' => $_GET['route'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? '',
                'search' => $_GET['search'] ?? '',
                'risk_score_min' => $_GET['risk_score_min'] ?? null,
                'sort_by' => $_GET['sort_by'] ?? 'detected_at',
                'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
            ];
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
            $result = SecurityEventService::getInstance()->getEvents(array_filter($filters), $page, $perPage);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error('Failed to load security events', 500);
        }
    }

    public function eventDetailAction(int $id): void
    {
        $this->requirePermission('security', 'view');
        $event = SecurityEventService::getInstance()->getEventById($id);
        if (!$event) $this->notFound('Event not found');
        $this->success($event);
    }

        public function incidentsAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $filters = [
                'severity' => $_GET['severity'] ?? '',
                'status' => $_GET['status'] ?? '',
                'source' => $_GET['source'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? '',
                'risk_score_min' => $_GET['risk_score_min'] ?? null,
                'sort_by' => $_GET['sort_by'] ?? 'last_seen',
                'sort_dir' => $_GET['sort_dir'] ?? 'DESC',
            ];
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
            $result = SecurityIncidentService::getInstance()->getIncidents(array_filter($filters), $page, $perPage);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error('Failed to load incidents', 500);
        }
    }

    public function incidentDetailAction(int $id): void
    {
        $this->requirePermission('security', 'view');
        $incident = SecurityIncidentService::getInstance()->getIncidentById($id);
        if (!$incident) $this->notFound('Incident not found');
        $this->success($incident);
    }

    public function threatsAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $recentEvents = SecurityEventService::getInstance()->getRecentEvents(10);
            $activeIncidents = SecurityIncidentService::getInstance()->getRecentIncidents(10);
            $this->success(['recent_events' => $recentEvents, 'active_incidents' => $activeIncidents]);
        } catch (\Exception $e) { $this->error('Failed to load threats', 500); }
    }

    public function postureAction(): void
    {
        $this->requirePermission('security', 'view');
        $posture = SecurityRiskEngine::getInstance()->calculatePosture();
        $this->success($posture);
    }

    public function userActivityAction(int $userId): void
    {
        $this->requirePermission('security', 'investigate');
        $events = SecurityEventService::getInstance()->getEvents(['user_id' => $userId], 1, 50);
        $this->success($events);
    }

        public function endpointsAction(): void
    {
        $this->requirePermission('security', 'view');
        $this->success($this->getEndpointInventory());
    }

    public function aiThreatsAction(): void
    {
        $this->requirePermission('security', 'investigate');
        try {
            $aiService = SecurityAiAnalysisService::getInstance();
            $threats = $aiService->analyzeThreats();
            $this->success($threats);
        } catch (\Exception $e) {
            $this->error('Failed to load AI threat analysis', 500);
        }
    }

        public function aiAnalyzeAction(): void
    {
        $this->requirePermission('security', 'investigate');
        try {
            $body = $this->getJsonBody();
            $incidentId = isset($body['incident_id']) ? (int) $body['incident_id'] : null;
            $eventIds = isset($body['event_ids']) ? array_map('intval', (array) $body['event_ids']) : [];

            $aiService = SecurityAiAnalysisService::getInstance();
            $analysis = $aiService->analyzeIncident($incidentId, $eventIds);
            $this->success($analysis);
        } catch (\Exception $e) {
            $this->error('Failed to analyze incident', 500);
        }
    }

    public function vulnerabilitiesAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $filters = [
                'severity'  => $_GET['severity'] ?? '',
                'status'    => $_GET['status'] ?? '',
                'category'  => $_GET['category'] ?? '',
                'cwe_id'    => $_GET['cwe_id'] ?? '',
                'search'    => $_GET['search'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to'   => $_GET['date_to'] ?? '',
                'sort_by'   => $_GET['sort_by'] ?? 'last_seen_at',
                'sort_dir'  => $_GET['sort_dir'] ?? 'DESC',
            ];
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
            $result = VulnerabilityService::getInstance()->getAll(array_filter($filters), $page, $perPage);
            $this->success($result);
        } catch (\Exception $e) {
            \logger()->error('Vulnerability listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to load vulnerabilities', 500);
        }
    }

    /**
     * GET /security/report — Comprehensive SOC & Executive Security Reporting.
     * Supports range presets (today, 7d, 30d, 90d, custom) and format (json, csv).
     */
    public function reportAction(): void
    {
        $this->requirePermission('security', 'view');
        try {
            $range = $_GET['range'] ?? '30d';
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;
            $format = strtolower((string) ($_GET['format'] ?? 'json'));

            if (!empty($dateFrom) && !empty($dateTo)) {
                $from = substr($dateFrom, 0, 10);
                $to = substr($dateTo, 0, 10);
            } else {
                switch ($range) {
                    case 'today':
                        $from = date('Y-m-d');
                        $to = date('Y-m-d');
                        break;
                    case '7d':
                        $from = date('Y-m-d', strtotime('-6 days'));
                        $to = date('Y-m-d');
                        break;
                    case '90d':
                        $from = date('Y-m-d', strtotime('-89 days'));
                        $to = date('Y-m-d');
                        break;
                    case '30d':
                    default:
                        $from = date('Y-m-d', strtotime('-29 days'));
                        $to = date('Y-m-d');
                        break;
                }
            }

            $startDt = $from . ' 00:00:00';
            $endDt = $to . ' 23:59:59';
            $db = \db();

            $eventsTotal = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_events WHERE detected_at BETWEEN ? AND ?",
                'ss', [$startDt, $endDt]
            ) ?? 0);

            $sevRows = $db->fetchAll(
                "SELECT severity, COUNT(*) as count FROM security_events WHERE detected_at BETWEEN ? AND ? GROUP BY severity",
                'ss', [$startDt, $endDt]
            );
            $bySeverity = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'CRITICAL' => 0];
            foreach ($sevRows as $sr) {
                $sev = strtoupper((string) ($sr['severity'] ?? ''));
                if (isset($bySeverity[$sev])) {
                    $bySeverity[$sev] = (int) $sr['count'];
                }
            }

            $actionRows = $db->fetchAll(
                "SELECT action_taken, COUNT(*) as count FROM security_events WHERE detected_at BETWEEN ? AND ? GROUP BY action_taken",
                'ss', [$startDt, $endDt]
            );

            $defendedCount = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_events WHERE detected_at BETWEEN ? AND ? AND action_taken IN ('BLOCKED', 'DENIED', 'RATE_LIMITED')",
                'ss', [$startDt, $endDt]
            ) ?? 0);

            $topEvents = $db->fetchAll(
                "SELECT event_type, severity, COUNT(*) as count, MAX(risk_score) as max_risk FROM security_events WHERE detected_at BETWEEN ? AND ? GROUP BY event_type, severity ORDER BY count DESC LIMIT 10",
                'ss', [$startDt, $endDt]
            );

            $topRoutes = $db->fetchAll(
                "SELECT route, http_method, COUNT(*) as count, MAX(severity) as highest_severity FROM security_events WHERE detected_at BETWEEN ? AND ? AND route IS NOT NULL AND route != '' GROUP BY route, http_method ORDER BY count DESC LIMIT 10",
                'ss', [$startDt, $endDt]
            );

            $topIps = $db->fetchAll(
                "SELECT ip_address, COUNT(*) as count, MAX(severity) as max_severity, COUNT(DISTINCT event_type) as unique_attacks FROM security_events WHERE detected_at BETWEEN ? AND ? AND ip_address IS NOT NULL AND ip_address != '' GROUP BY ip_address ORDER BY count DESC LIMIT 10",
                'ss', [$startDt, $endDt]
            );

            $timeline = $db->fetchAll(
                "SELECT DATE(detected_at) as date, severity, COUNT(*) as count FROM security_events WHERE detected_at BETWEEN ? AND ? GROUP BY DATE(detected_at), severity ORDER BY date ASC",
                'ss', [$startDt, $endDt]
            );

            $incidentsTotal = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_incidents WHERE first_seen BETWEEN ? AND ?",
                'ss', [$startDt, $endDt]
            ) ?? 0);
            $incidentsActive = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_incidents WHERE first_seen BETWEEN ? AND ? AND status IN ('NEW','INVESTIGATING','CONTAINED')",
                'ss', [$startDt, $endDt]
            ) ?? 0);
            $incidentsResolved = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_incidents WHERE first_seen BETWEEN ? AND ? AND status = 'RESOLVED'",
                'ss', [$startDt, $endDt]
            ) ?? 0);
            $incidentsFalsePositive = (int) ($db->fetchValue(
                "SELECT COUNT(*) FROM security_incidents WHERE first_seen BETWEEN ? AND ? AND status = 'FALSE_POSITIVE'",
                'ss', [$startDt, $endDt]
            ) ?? 0);
            $avgRisk = (float) ($db->fetchValue(
                "SELECT AVG(risk_score) FROM security_incidents WHERE first_seen BETWEEN ? AND ?",
                'ss', [$startDt, $endDt]
            ) ?? 0);
            $avgMttrMinutes = (float) ($db->fetchValue(
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, first_seen, resolved_at)) FROM security_incidents WHERE first_seen BETWEEN ? AND ? AND resolved_at IS NOT NULL",
                'ss', [$startDt, $endDt]
            ) ?? 0);

            $posture = SecurityRiskEngine::getInstance()->calculatePosture();

            $healthScore = 100;
            $healthScore -= min(40, $bySeverity['CRITICAL'] * 15);
            $healthScore -= min(30, $bySeverity['HIGH'] * 5);
            $healthScore -= min(20, $incidentsActive * 10);
            $healthScore = max(10, min(100, $healthScore));

            $resolutionRate = $incidentsTotal > 0
                ? round((($incidentsResolved + $incidentsFalsePositive) / $incidentsTotal) * 100, 1)
                : 100.0;

            $reportData = [
                'period' => [
                    'range' => $range,
                    'date_from' => $from,
                    'date_to' => $to,
                    'generated_at' => date('Y-m-d H:i:s'),
                ],
                'summary' => [
                    'health_score' => $healthScore,
                    'posture' => $posture['posture'] ?? 'GOOD',
                    'posture_reasons' => $posture['reasons'] ?? [],
                    'total_events' => $eventsTotal,
                    'critical_events' => $bySeverity['CRITICAL'],
                    'high_events' => $bySeverity['HIGH'],
                    'medium_events' => $bySeverity['MEDIUM'],
                    'low_events' => $bySeverity['LOW'],
                    'defended_count' => $defendedCount,
                    'defense_rate' => $eventsTotal > 0 ? round(($defendedCount / $eventsTotal) * 100, 1) : 100.0,
                    'total_incidents' => $incidentsTotal,
                    'active_incidents' => $incidentsActive,
                    'resolved_incidents' => $incidentsResolved,
                    'false_positive_incidents' => $incidentsFalsePositive,
                    'resolution_rate' => $resolutionRate,
                    'avg_incident_risk' => round($avgRisk, 1),
                    'avg_mttr_minutes' => round($avgMttrMinutes, 1),
                ],
                'by_severity' => $bySeverity,
                'by_action' => $actionRows,
                'top_event_types' => $topEvents,
                'top_attacked_routes' => $topRoutes,
                'top_offending_ips' => $topIps,
                'timeline' => $timeline,
            ];

            if ($format === 'csv') {
                $filename = "security_report_{$from}_to_{$to}.csv";
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-store, no-cache');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");

                fputcsv($out, ['MUWASCO HR - SECURITY AUDIT & SOC EXECUTIVE REPORT']);
                fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
                fputcsv($out, ['Reporting Period', "{$from} to {$to}"]);
                fputcsv($out, []);

                fputcsv($out, ['--- EXECUTIVE SUMMARY ---']);
                fputcsv($out, ['Metric', 'Value']);
                fputcsv($out, ['Security Health Score', "{$healthScore}/100"]);
                fputcsv($out, ['Current Posture', $posture['posture'] ?? 'GOOD']);
                fputcsv($out, ['Total Security Events', $eventsTotal]);
                fputcsv($out, ['Critical Events', $bySeverity['CRITICAL']]);
                fputcsv($out, ['High Severity Events', $bySeverity['HIGH']]);
                fputcsv($out, ['Medium Severity Events', $bySeverity['MEDIUM']]);
                fputcsv($out, ['Low Severity Events', $bySeverity['LOW']]);
                fputcsv($out, ['Automated Mitigations (Blocked/Denied)', $defendedCount]);
                fputcsv($out, ['Defense Efficacy Rate', ($reportData['summary']['defense_rate']) . '%']);
                fputcsv($out, ['Total Incidents', $incidentsTotal]);
                fputcsv($out, ['Active Incidents', $incidentsActive]);
                fputcsv($out, ['Resolved Incidents', $incidentsResolved]);
                fputcsv($out, ['Incident Resolution Rate', "{$resolutionRate}%"]);
                fputcsv($out, ['Avg MTTR (Minutes)', round($avgMttrMinutes, 1)]);
                fputcsv($out, []);

                fputcsv($out, ['--- TOP SECURITY EVENT TYPES ---']);
                fputcsv($out, ['Event Type', 'Severity', 'Count', 'Max Risk Score']);
                foreach ($topEvents as $te) {
                    fputcsv($out, [$te['event_type'], $te['severity'], $te['count'], $te['max_risk']]);
                }
                fputcsv($out, []);

                fputcsv($out, ['--- TOP ATTACKED / SUSPICIOUS ROUTES ---']);
                fputcsv($out, ['HTTP Method', 'Route', 'Event Count', 'Highest Severity']);
                foreach ($topRoutes as $tr) {
                    fputcsv($out, [$tr['http_method'] ?? 'ALL', $tr['route'], $tr['count'], $tr['highest_severity']]);
                }
                fputcsv($out, []);

                fputcsv($out, ['--- TOP OFFENDING IP ADDRESSES ---']);
                fputcsv($out, ['IP Address', 'Total Events', 'Max Severity', 'Unique Attack Signatures']);
                foreach ($topIps as $ti) {
                    fputcsv($out, [$ti['ip_address'], $ti['count'], $ti['max_severity'], $ti['unique_attacks']]);
                }

                fclose($out);
                exit;
            }

            $this->success($reportData);
        } catch (\Throwable $e) {
            \logger()->error('Security report generation error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate security report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /security/ai/copilot — AI Security Copilot.
     * The AI may ONLY call pre-approved backend tools; it never queries the
     * database directly and can never authorize data access itself.
     * Body: { question: string, tools?: string[] }
     */
    public function aiCopilotAction(): void
    {
        $this->requirePermission('security', 'investigate');
        try {
            $body = $this->getJsonBody();
            $question = trim((string) ($body['question'] ?? ''));
            $tools = is_array($body['tools'] ?? null) ? array_slice($body['tools'], 0, 10) : [];

            if ($question === '' || mb_strlen($question) > 1000) {
                $this->error('A question of at most 1000 characters is required', 422, 'VALIDATION_ERROR');
            }

            $result = \App\Services\Security\SecurityAiAnalysisService::getInstance()->copilotQuery($question, $tools);
            $this->success($result);
        } catch (\Exception $e) {
            \logger()->error('AI copilot query failed', ['error' => $e->getMessage()]);
            $this->error('AI copilot unavailable', 503, 'AI_UNAVAILABLE');
        }
    }

    private function getVulnerabilityStatus(): array
    {
        return [
            ['name' => 'IDOR/BOLA Protection', 'status' => 'PASS', 'description' => 'Object-level authorization enforced in EmployeePolicy and ObjectAuthorization'],
            ['name' => 'RBAC Enforcement', 'status' => 'PASS', 'description' => 'Permission checks in BaseController requirePermission'],
            ['name' => 'Authentication Protection', 'status' => 'PASS', 'description' => 'AuthenticationMiddleware enforces session/JWT auth'],
            ['name' => 'Rate Limiting', 'status' => 'PASS', 'description' => 'Throttle middleware active on security endpoints'],
            ['name' => 'Security Event Monitoring', 'status' => 'PASS', 'description' => 'SecurityEventService records all denied access attempts'],
            ['name' => 'CSRF Protection', 'status' => 'PARTIAL', 'description' => 'Session-based CSRF via SameSite cookies; JWT auth exempt'],
            ['name' => 'Input Validation', 'status' => 'PASS', 'description' => 'Form requests and manual validation in controllers'],
            ['name' => 'Mass Assignment Protection', 'status' => 'PASS', 'description' => 'Explicit field whitelisting in updates'],
            ['name' => 'Secure Sessions', 'status' => 'PASS', 'description' => 'HttpOnly, SameSite cookies enforced'],
            ['name' => 'Security Headers', 'status' => 'PASS', 'description' => 'CSP, X-Content-Type-Options, etc. in SecurityMiddleware'],
        ];
    }

        /**
     * Endpoint security matrix for the SOC dashboard.
     *
     * Built dynamically from \ApiRouter::getRouteRegistry() so the matrix ALWAYS
     * reflects the full live API surface — including routes registered later —
     * rather than a hand-maintained list that can drift. Every endpoint on the
     * board has monitoring = true (every denial on any route is recorded by
     * AuthorizationMiddleware::recordSecurityEvent, and every rate-limit
     * hit by SecurityMiddleware::protectAgainstBruteForce), so unauthorized
     * access attempts are caught system-wide even when an individual route
     * declares no permission (authenticated-only / self-service endpoints).
     */
    private function getEndpointInventory(): array
    {
        $registry = \ApiRouter::getRouteRegistry();

        // Resources whose object-level authorization is enforced by an explicit
        // controller-level policy check (EmployeePolicy::canView / canEdit,
        // ObjectAuthorization, etc.). Parameterized resource routes for these
        // types get object_auth = true.
        $objectPolicyResources = ['employees', 'leave', 'attendance', 'meetings', 'users', 'appraisals'];

        $inventory = [];
        foreach ($registry as $route) {
            $hasParam  = (bool) preg_match('#\\{[a-zA-Z_]+\\}#', $route['path']);
            $permission = $route['permission'] ?? null;

            // Determine the resource family for object-auth inference.
            $resource = null;
            if ($hasParam) {
                if (preg_match('#^/(employees|leave|attendance|meetings|users|departments|positions|appraisals)/#i', $route['path'], $m)) {
                    $resource = $m[1];
                }
            }

            $objectAuth = false;
            if ($hasParam && $permission !== null) {
                if ($resource !== null && in_array($resource, $objectPolicyResources, true)) {
                    $objectAuth = true;
                }
            }

            $inventory[] = [
                'method'      => $route['method'],
                'route'       => $route['path'],
                'controller'  => $route['controller'],
                'action'      => $route['action'],
                'auth'        => true,                       // all registered API routes require auth
                'permission'  => $permission ?? 'authenticated',
                'object_auth' => $objectAuth,
                'rate_limit'  => !empty($route['throttle']),
                'monitoring'  => true,                       // all routes monitored: denials + 429s
            ];
        }

        return $inventory;
    }
}

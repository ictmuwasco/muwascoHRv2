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
        $objectPolicyResources = ['employees', 'leave', 'attendance', 'meetings', 'users'];

        $inventory = [];
        foreach ($registry as $route) {
            $hasParam  = (bool) preg_match('#\\{[a-zA-Z_]+\\}#', $route['path']);
            $permission = $route['permission'] ?? null;

            // Determine the resource family for object-auth inference.
            $resource = null;
            if ($hasParam) {
                if (preg_match('#^/(employees|leave|attendance|meetings|users|departments|positions)/#i', $route['path'], $m)) {
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Tests\TestCase;
use App\Services\Security\SecurityRiskEngine;

/**
 * Security Operations tests — IDOR/BOLA enforcement, security event
 * infrastructure, rule-based detection and risk scoring (Phase 3–5).
 *
 * Two conventions are used to keep this suite CI-safe (no DB required):
 *   1. Source assertions (same convention as IdorOwnershipEnforcementTest and
 *      SecurityPolicyConfigurationTest): pins the authorization call site and
 *      the event/rule wiring so a future refactor cannot silently drop a guard.
 *   2. Pure-logic tests of the deterministic risk engine (no DB access).
 *
 * Place: backend/tests/Unit/Security/SecurityOperationsTest.php
 */
class SecurityOperationsTest extends TestCase
{
    // ──── 1. IDOR / BOLA — employee object-level authorization ──────────────

    public function test_employee_showAction_enforces_object_level_authorization(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../../app/Controllers/Employee/EmployeeController.php');

        // The permission check alone is NOT sufficient — the specific record
        // must also pass EmployeePolicy::canView (the IDOR/BOLA gate).
        $this->assertStringContainsString(
            'EmployeePolicy::canView',
            $controller,
            'Employee showAction must enforce object-level authorization'
        );
        $this->assertStringContainsString(
            "requirePermission('employees', 'view')",
            $controller,
            'Employee showAction must retain the RBAC permission gate'
        );
    }

    public function test_employee_idor_denial_logs_a_security_event(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../../app/Controllers/Employee/EmployeeController.php');

        $this->assertStringContainsString(
            'SecurityEventService::getInstance()->record',
            $controller,
            'IDOR denial must generate a security event'
        );
        $this->assertStringContainsString(
            'SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS',
            $controller,
            'IDOR denial must use the UNAUTHORIZED_OBJECT_ACCESS event type'
        );
        $this->assertStringContainsString(
            'forbidden(',
            $controller,
            'IDOR denial must return 403 (forbidden)'
        );
    }

    public function test_employee_policy_ownership_is_server_side(): void
    {
        $policy = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/EmployeePolicy.php');

        $this->assertStringContainsString('isHrOrAdmin($userId)', $policy);
        $this->assertStringContainsString('isSelf($userId, $employee)', $policy);

        // Ownership must compare the session-derived user id against the
        // employee.user_id — never a client-supplied value.
        $this->assertStringContainsString(
            '(int) $employee[\'user_id\'] === $userId',
            $policy,
            'Ownership must compare the session user id against employee.user_id'
        );
    }

    // ──── 2. Security event infrastructure ───────────────────────────────────

    public function test_security_event_record_triggers_the_rule_engine(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/SecurityEventService.php');

        // Every recorded event flows through the deterministic rule engine
        // (which may escalate to an incident) — central, not per-controller.
        $this->assertStringContainsString(
            'SecurityRuleEngine::getInstance()',
            $service,
            'SecurityEventService::record must invoke the rule engine'
        );
        $this->assertStringContainsString(
            'processEvent(',
            $service,
            'The rule engine must process every recorded event'
        );
    }

    public function test_security_event_redacts_sensitive_data(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/SecurityEventService.php');

        foreach (['password', 'passwd', 'token', 'authorization', 'access_token',
            'refresh_token', 'api_key', 'secret', 'csrf_token', 'cookie'] as $sensitive) {
            $this->assertStringContainsString(
                "'{$sensitive}'",
                $service,
                "Sensitive key '{$sensitive}' must be listed for redaction"
            );
        }

        $this->assertStringContainsString("'[REDACTED]'", $service);
    }

    public function test_security_event_normalizes_pii_safe_columns(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/SecurityEventService.php');

        // The security_events table stores normalized, PII-safe columns —
        // never a raw dump of the request body or headers.
        foreach (['event_type', 'severity', 'risk_score', 'user_id', 'ip_address',
            'http_method', 'route', 'resource_type', 'resource_id', 'response_status',
            'action_taken'] as $column) {
            $this->assertStringContainsString(
                "'{$column}'",
                $service,
                "Missing security_events column: {$column}"
            );
        }
    }

    // ──── 3. Rule-based detection ───────────────────────────────────────────

    public function test_rule_engine_defines_detection_thresholds(): void
    {
        $engine = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/SecurityRuleEngine.php');

        foreach (['BRUTE_FORCE_THRESHOLD', 'BRUTE_FORCE_WINDOW', 'IDOR_THRESHOLD',
            'IDOR_WINDOW', 'ENUMERATION_THRESHOLD', 'ENUMERATION_WINDOW',
            'PRIVILEGE_ESCALATION_THRESHOLD'] as $constant) {
            $this->assertStringContainsString($constant, $engine, "Missing threshold: {$constant}");
        }
    }

    public function test_rule_engine_escalates_to_incidents(): void
    {
        $engine = (string) file_get_contents(__DIR__ . '/../../../app/Services/Security/SecurityRuleEngine.php');

        $this->assertStringContainsString(
            'incidentService->create(',
            $engine,
            'Rule engine must create security incidents on match'
        );
        $this->assertStringContainsString('IDOR_ENUMERATION', $engine);
        $this->assertStringContainsString('Potential IDOR attack', $engine);
        $this->assertStringContainsString('Potential brute force', $engine);
        $this->assertStringContainsString('privilege escalation', strtolower($engine));
    }

    // ──── 4. Deterministic risk scoring (pure logic — no DB) ─────────────────

    public function test_risk_engine_base_scores_are_ordered(): void
    {
        $engine = SecurityRiskEngine::getInstance();

        $this->assertGreaterThan(
            $engine->calculateEventRisk('FAILED_LOGIN'),
            $engine->calculateEventRisk('SQL_INJECTION_INDICATOR')
        );
        $this->assertGreaterThan(
            $engine->calculateEventRisk('UNAUTHORIZED_OBJECT_ACCESS'),
            $engine->calculateEventRisk('IDOR_ENUMERATION')
        );
    }

    public function test_risk_engine_severity_mapping(): void
    {
        $engine = SecurityRiskEngine::getInstance();

        $this->assertEquals('LOW', $engine->scoreToSeverity(10));
        $this->assertEquals('MEDIUM', $engine->scoreToSeverity(30));
        $this->assertEquals('HIGH', $engine->scoreToSeverity(60));
        $this->assertEquals('CRITICAL', $engine->scoreToSeverity(80));
    }

    public function test_risk_engine_frequency_increases_risk(): void
    {
        $engine = SecurityRiskEngine::getInstance();

        $single   = $engine->calculateFrequencyRisk('UNAUTHORIZED_OBJECT_ACCESS', 1);
        $repeated = $engine->calculateFrequencyRisk('UNAUTHORIZED_OBJECT_ACCESS', 20);

        $this->assertGreaterThan($single, $repeated, 'Repeated events must raise the risk score');
        $this->assertLessThanOrEqual(100, $repeated, 'Risk must be capped at 100');
    }

    public function test_risk_engine_incident_gate(): void
    {
        $engine = SecurityRiskEngine::getInstance();

        $this->assertFalse($engine->shouldCreateIncident('FAILED_LOGIN', 1, 0));

        $this->assertTrue(
            $engine->shouldCreateIncident('UNAUTHORIZED_OBJECT_ACCESS', 3, 10),
            'Ten distinct unauthorized resources must trigger an enumeration incident'
        );

        $this->assertTrue($engine->shouldCreateIncident('BRUTE_FORCE', 20, 0));
    }

    // ──── 5. Security dashboard API surface ─────────────────────────────────

    public function test_security_dashboard_apis_are_registered_with_permissions(): void
    {
        $api = (string) file_get_contents(BASE_PATH . '/api.php');

        $this->assertStringContainsString("'GET', '/security/overview'", $api);
        $this->assertStringContainsString("'GET', '/security/events'", $api);
        $this->assertStringContainsString("'GET', '/security/incidents'", $api);
        $this->assertStringContainsString("'GET', '/security/threats'", $api);
        $this->assertStringContainsString("'GET', '/security/vulnerabilities'", $api);
        $this->assertStringContainsString("'GET', '/security/ai/threats'", $api);
        $this->assertStringContainsString("'POST', '/security/ai/analyze'", $api);
        $this->assertStringContainsString("'POST', '/security/ai/copilot'", $api);

        $this->assertStringContainsString("'security:view'", $api);
        $this->assertStringContainsString("'security:investigate'", $api);
        $this->assertStringContainsString("'security:manage'", $api);
    }
}
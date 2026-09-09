<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * SecurityRuleEngine — deterministic rule-based threat detection.
 */
final class SecurityRuleEngine
{
    private const BRUTE_FORCE_THRESHOLD = 5;
    private const BRUTE_FORCE_WINDOW    = 900;
    private const IDOR_THRESHOLD        = 3;
    private const IDOR_WINDOW           = 300;
    private const ENUMERATION_THRESHOLD = 10;
    private const ENUMERATION_WINDOW    = 300;
    private const PRIVILEGE_ESCALATION_THRESHOLD = 2;
    private const PRIVILEGE_ESCALATION_WINDOW    = 600;

    private static ?SecurityRuleEngine $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function evaluateFailedLogin(int $userId, string $ip): bool
    {
        if ($userId <= 0) return false;
        $count = SecurityEventService::getInstance()->countEventsByUser(
            $userId, SecurityEventService::FAILED_LOGIN, self::BRUTE_FORCE_WINDOW
        );
        return $count >= self::BRUTE_FORCE_THRESHOLD;
    }

    public function evaluateUnauthorizedAccess(int $userId, string $resourceType, int $resourceId): bool
    {
        if ($userId <= 0) return false;
        $count = SecurityEventService::getInstance()->countEventsByUser(
            $userId, SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS, self::IDOR_WINDOW
        );
        $distinctResources = SecurityEventService::getInstance()->countDistinctResourcesAccessed(
            $userId, $resourceType, self::ENUMERATION_WINDOW
        );
        if ($distinctResources >= self::ENUMERATION_THRESHOLD) {
            SecurityEventService::getInstance()->record(
                SecurityEventService::IDOR_ENUMERATION,
                SecurityEventService::SEVERITY_CRITICAL,
                SecurityRiskEngine::getInstance()->calculateFrequencyRisk(SecurityEventService::IDOR_ENUMERATION, $distinctResources),
                [
                    'user_id' => $userId, 'resource_type' => $resourceType,
                    'distinct_count' => $distinctResources,
                    'action_taken' => SecurityEventService::ACTION_ALERTED,
                    'description' => "IDOR enumeration: {$distinctResources} distinct {$resourceType} resources",
                ]
            );
            return true;
        }
        return $count >= self::IDOR_THRESHOLD;
    }

    public function evaluateAdminAccess(int $userId, string $endpoint): bool
    {
        if ($userId <= 0) return false;
        $count = SecurityEventService::getInstance()->countEventsByUser(
            $userId, SecurityEventService::UNAUTHORIZED_ADMIN_ACCESS, self::PRIVILEGE_ESCALATION_WINDOW
        );
        return $count >= self::PRIVILEGE_ESCALATION_THRESHOLD;
    }


    /**
     * Process a security event through all rules.
     * Returns incident ID if one was created, null otherwise.
     */
    public function processEvent(int $eventId, string $eventType, int $userId, array $context): ?int
    {
        if ($userId <= 0) return null;

        $riskEngine = SecurityRiskEngine::getInstance();
        $eventService = SecurityEventService::getInstance();
        $incidentService = SecurityIncidentService::getInstance();

        $incidentToCreate = false;
        $severity = SecurityEventService::SEVERITY_HIGH;
        $riskScore = 0;
        $summary = '';

        switch ($eventType) {
            case SecurityEventService::FAILED_LOGIN:
                $count = $eventService->countEventsByUser($userId, $eventType, self::BRUTE_FORCE_WINDOW);
                if ($count >= self::BRUTE_FORCE_THRESHOLD) {
                    $incidentToCreate = true;
                    $riskScore = $riskEngine->calculateFrequencyRisk($eventType, $count);
                    $severity = $riskEngine->scoreToSeverity($riskScore);
                    $summary = "Potential brute force: {$count} failed logins in " . (self::BRUTE_FORCE_WINDOW / 60) . " min";
                }
                break;

            case SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS:
            case SecurityEventService::IDOR_ATTEMPT:
                $count = $eventService->countEventsByUser($userId, SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS, self::IDOR_WINDOW);
                $distinct = $eventService->countDistinctResourcesAccessed($userId, $context['resource_type'] ?? 'resource', self::ENUMERATION_WINDOW);
                if ($riskEngine->shouldCreateIncident($eventType, $count, $distinct)) {
                    $incidentToCreate = true;
                    $riskScore = $riskEngine->calculateFrequencyRisk($eventType, $count + $distinct);
                    $severity = $riskEngine->scoreToSeverity($riskScore);
                    $summary = "Potential IDOR attack: {$count} unauthorized accesses to {$distinct} distinct resources";
                }
                break;

            case SecurityEventService::PRIVILEGE_ESCALATION:
            case SecurityEventService::UNAUTHORIZED_ADMIN_ACCESS:
                $count = $eventService->countEventsByUser($userId, SecurityEventService::UNAUTHORIZED_ADMIN_ACCESS, self::PRIVILEGE_ESCALATION_WINDOW);
                if ($count >= self::PRIVILEGE_ESCALATION_THRESHOLD) {
                    $incidentToCreate = true;
                    $riskScore = 85;
                    $severity = SecurityEventService::SEVERITY_CRITICAL;
                    $summary = "Potential privilege escalation: {$count} unauthorized admin access attempts";
                }
                break;

            case SecurityEventService::IDOR_ENUMERATION:
                $incidentToCreate = true;
                $riskScore = 90;
                $severity = SecurityEventService::SEVERITY_CRITICAL;
                $summary = "IDOR enumeration attack detected";
                break;
        }

        if ($incidentToCreate) {
            return $incidentService->create($severity, $riskScore, SecurityIncidentService::SOURCE_RULE_ENGINE, $summary, [$eventId]);
        }

        return null;
    }

    private function __clone(): void {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }
}

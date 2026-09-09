<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * SecurityRiskEngine — deterministic risk scoring and security posture.
 */
final class SecurityRiskEngine
{
    private const BASE_SCORES = [
        SecurityEventService::FAILED_LOGIN                  => 10,
        SecurityEventService::BRUTE_FORCE                   => 70,
        SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS    => 65,
        SecurityEventService::IDOR_ATTEMPT                  => 70,
        SecurityEventService::IDOR_ENUMERATION              => 90,
        SecurityEventService::PRIVILEGE_ESCALATION          => 85,
        SecurityEventService::UNAUTHORIZED_ADMIN_ACCESS     => 80,
        SecurityEventService::SUSPICIOUS_API_ACTIVITY       => 50,
        SecurityEventService::RATE_LIMIT_VIOLATION          => 40,
        SecurityEventService::SQL_INJECTION_INDICATOR       => 95,
        SecurityEventService::XSS_INDICATOR                 => 75,
        SecurityEventService::PATH_TRAVERSAL_INDICATOR      => 70,
        SecurityEventService::MALICIOUS_FILE_UPLOAD         => 80,
        SecurityEventService::SESSION_ANOMALY               => 55,
        SecurityEventService::TOKEN_ABUSE                   => 75,
        SecurityEventService::ACCOUNT_TAKEOVER_INDICATOR    => 85,
        SecurityEventService::MASS_DATA_ACCESS              => 60,
        SecurityEventService::SECURITY_CONFIGURATION_CHANGE => 45,
    ];

    private const SEVERITY_LOW_THRESHOLD      = 25;
    private const SEVERITY_MEDIUM_THRESHOLD   = 50;
    private const SEVERITY_HIGH_THRESHOLD     = 75;

    private const FREQUENCY_MULTIPLIERS = [1 => 1.0, 3 => 1.25, 5 => 1.5, 10 => 2.0, 20 => 3.0];

    private static ?SecurityRiskEngine $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    public function calculateEventRisk(string $eventType): int
    {
        return self::BASE_SCORES[$eventType] ?? 25;
    }

    public function calculateFrequencyRisk(string $eventType, int $eventCount): int
    {
        $base = $this->calculateEventRisk($eventType);
        $multiplier = 1.0;
        foreach (self::FREQUENCY_MULTIPLIERS as $threshold => $mult) {
            if ($eventCount >= $threshold) { $multiplier = $mult; }
        }
        return (int) min(100, round($base * $multiplier));
    }

    public function scoreToSeverity(int $riskScore): string
    {
        if ($riskScore >= self::SEVERITY_HIGH_THRESHOLD) return SecurityEventService::SEVERITY_CRITICAL;
        if ($riskScore >= self::SEVERITY_MEDIUM_THRESHOLD) return SecurityEventService::SEVERITY_HIGH;
        if ($riskScore >= self::SEVERITY_LOW_THRESHOLD) return SecurityEventService::SEVERITY_MEDIUM;
        return SecurityEventService::SEVERITY_LOW;
    }

    public function calculatePosture(): array
    {
        try {
            $since = date('Y-m-d H:i:s', time() - 86400);
            $criticalCount = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE severity = 'CRITICAL' AND detected_at >= ?", 's', [$since]) ?? 0);
            $highCount = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE severity = 'HIGH' AND detected_at >= ?", 's', [$since]) ?? 0);
            $activeIncidents = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_incidents WHERE status IN ('NEW','INVESTIGATING','CONTAINED') AND severity IN ('HIGH','CRITICAL')") ?? 0);

            $posture = 'GOOD'; $reasons = [];
            if ($criticalCount > 0) { $posture = 'CRITICAL'; $reasons[] = "{$criticalCount} critical event(s) in last 24h"; }
            elseif ($highCount >= 5 || $activeIncidents >= 3) { $posture = 'HIGH_RISK'; $reasons[] = "{$highCount} high-severity events, {$activeIncidents} active incidents"; }
            elseif ($highCount >= 1 || $activeIncidents >= 1) { $posture = 'WARNING'; $reasons[] = "{$highCount} high-severity event(s), {$activeIncidents} active incident(s)"; }
            else { $reasons[] = 'No high/critical events in last 24h'; }

            return ['posture' => $posture, 'reasons' => $reasons, 'critical_events_24h' => $criticalCount, 'high_events_24h' => $highCount, 'active_incidents' => $activeIncidents];
        } catch (\Throwable $e) { return ['posture' => 'GOOD', 'reasons' => ['Unable to calculate'], 'critical_events_24h' => 0, 'high_events_24h' => 0, 'active_incidents' => 0]; }
    }

    public function shouldCreateIncident(string $eventType, int $eventCount, int $distinctResources = 0): bool
    {
        $risk = $this->calculateFrequencyRisk($eventType, $eventCount);
        if ($risk >= 80) return true;
        if ($distinctResources >= 10 && in_array($eventType, [SecurityEventService::IDOR_ATTEMPT, SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS], true)) return true;
        if ($risk >= 65 && $eventCount >= 5) return true;
        return false;
    }

    private function __clone(): void {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }
}


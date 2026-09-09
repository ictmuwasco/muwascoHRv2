<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\AuditService;

/**
 * SecurityEventService — centralized, PII-safe security event recording.
 */
final class SecurityEventService
{
    public const FAILED_LOGIN                   = 'FAILED_LOGIN';
    public const BRUTE_FORCE                    = 'BRUTE_FORCE';
    public const UNAUTHORIZED_OBJECT_ACCESS     = 'UNAUTHORIZED_OBJECT_ACCESS';
    public const IDOR_ATTEMPT                   = 'IDOR_ATTEMPT';
    public const IDOR_ENUMERATION               = 'IDOR_ENUMERATION';
    public const PRIVILEGE_ESCALATION           = 'PRIVILEGE_ESCALATION';
    public const UNAUTHORIZED_ADMIN_ACCESS      = 'UNAUTHORIZED_ADMIN_ACCESS';
    public const SUSPICIOUS_API_ACTIVITY        = 'SUSPICIOUS_API_ACTIVITY';
    public const RATE_LIMIT_VIOLATION           = 'RATE_LIMIT_VIOLATION';
    public const SQL_INJECTION_INDICATOR        = 'SQL_INJECTION_INDICATOR';
    public const XSS_INDICATOR                  = 'XSS_INDICATOR';
    public const PATH_TRAVERSAL_INDICATOR       = 'PATH_TRAVERSAL_INDICATOR';
    public const MALICIOUS_FILE_UPLOAD          = 'MALICIOUS_FILE_UPLOAD';
    public const SESSION_ANOMALY                = 'SESSION_ANOMALY';
    public const TOKEN_ABUSE                    = 'TOKEN_ABUSE';
    public const ACCOUNT_TAKEOVER_INDICATOR     = 'ACCOUNT_TAKEOVER_INDICATOR';
    public const MASS_DATA_ACCESS               = 'MASS_DATA_ACCESS';
    public const SECURITY_CONFIGURATION_CHANGE  = 'SECURITY_CONFIGURATION_CHANGE';

    public const SEVERITY_LOW      = 'LOW';
    public const SEVERITY_MEDIUM   = 'MEDIUM';
    public const SEVERITY_HIGH     = 'HIGH';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const ACTION_BLOCKED      = 'BLOCKED';
    public const ACTION_DENIED       = 'DENIED';
    public const ACTION_RATE_LIMITED = 'RATE_LIMITED';
    public const ACTION_LOGGED       = 'LOGGED';
    public const ACTION_ALERTED      = 'ALERTED';

    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'email', 'token', 'authorization',
        'access_token', 'refresh_token', 'cookie', 'api_key', 'apikey',
        'secret', 'session_secret', 'csrf_token', 'auth',
    ];

    private static ?SecurityEventService $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Record a security event. Never throws.
     */
    public function record(
        string $eventType,
        string $severity = self::SEVERITY_LOW,
        int $riskScore = 0,
        array $context = []
    ): ?int {
        try {
            $data = $this->buildEventData($eventType, $severity, $riskScore, $context);
            $id = \db()->insert('security_events', $data);

                        if (in_array($severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL], true)) {
                AuditService::getInstance()->log(
                    'Security', 'SECURITY_EVENT',
                    "Security event: {$eventType} ({$severity}, risk={$riskScore})",
                    ['target_type' => 'SecurityEvent', 'target_id' => $id, 'metadata' => $this->redact($context)]
                );
            }

            // Trigger deterministic rule engine evaluation — this may create
            // a security incident if the configured thresholds are met.
            try {
                $ruleEngine = SecurityRuleEngine::getInstance();
                $userId = (int) ($context['user_id'] ?? 0);
                $ruleContext = [
                    'resource_type' => $context['resource_type'] ?? null,
                    'resource_id'   => $context['resource_id'] ?? null,
                    'ip_address'    => $context['ip_address'] ?? null,
                    'route'         => $context['route'] ?? null,
                ];
                $ruleEngine->processEvent($id, $eventType, $userId, $ruleContext);
            } catch (\Throwable $e) {
                \logger()->warning('Security rule engine evaluation failed', ['event_id' => $id, 'error' => $e->getMessage()]);
            }

            return $id;
        } catch (\Throwable $e) {
            \logger()->error('Failed to record security event', ['event_type' => $eventType, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function getEvents(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        try {
            $page    = max(1, $page);
            $perPage = min(100, max(1, $perPage));
            $offset  = ($page - 1) * $perPage;

            $where = '1=1'; $params = []; $types = '';
            if (!empty($filters['event_type'])) { $where .= ' AND event_type = ?'; $types .= 's'; $params[] = $filters['event_type']; }
            if (!empty($filters['severity'])) { $where .= ' AND severity = ?'; $types .= 's'; $params[] = $filters['severity']; }
            if (!empty($filters['user_id'])) { $where .= ' AND user_id = ?'; $types .= 'i'; $params[] = (int) $filters['user_id']; }
            if (!empty($filters['ip_address'])) { $where .= ' AND ip_address = ?'; $types .= 's'; $params[] = $filters['ip_address']; }
            if (!empty($filters['resource_type'])) { $where .= ' AND resource_type = ?'; $types .= 's'; $params[] = $filters['resource_type']; }
            if (!empty($filters['route'])) { $where .= ' AND route LIKE ?'; $types .= 's'; $params[] = '%' . $filters['route'] . '%'; }
            if (!empty($filters['date_from'])) { $where .= ' AND detected_at >= ?'; $types .= 's'; $params[] = $filters['date_from'] . ' 00:00:00'; }
            if (!empty($filters['date_to'])) { $where .= ' AND detected_at <= ?'; $types .= 's'; $params[] = $filters['date_to'] . ' 23:59:59'; }
            if (!empty($filters['search'])) { $where .= ' AND (event_type LIKE ? OR description LIKE ? OR ip_address LIKE ?)'; $types .= 'sss'; $like = '%' . $filters['search'] . '%'; array_push($params, $like, $like, $like); }
            if (isset($filters['risk_score_min'])) { $where .= ' AND risk_score >= ?'; $types .= 'i'; $params[] = (int) $filters['risk_score_min']; }

            $total = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE {$where}", $types, $params) ?? 0);
            $sortBy = $filters['sort_by'] ?? 'detected_at';
            $sortDir = isset($filters['sort_dir']) && strtoupper($filters['sort_dir']) === 'ASC' ? 'ASC' : 'DESC';
            $allowedSort = ['detected_at', 'severity', 'risk_score', 'event_type', 'user_id'];
            if (!in_array($sortBy, $allowedSort, true)) { $sortBy = 'detected_at'; }

            $data = \db()->fetchAll("SELECT * FROM security_events WHERE {$where} ORDER BY {$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}", $types, $params);

            return ['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => (int) ceil($total / $perPage)];
        } catch (\Throwable $e) {
            \logger()->error('Failed to fetch security events', ['error' => $e->getMessage()]);
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 0];
        }
    }

    public function getEventStats(string $dateFrom = null, string $dateTo = null): array
    {
        try {
            $where = '1=1'; $params = []; $types = '';
            if ($dateFrom) { $where .= ' AND detected_at >= ?'; $types .= 's'; $params[] = $dateFrom . ' 00:00:00'; }
            if ($dateTo) { $where .= ' AND detected_at <= ?'; $types .= 's'; $params[] = $dateTo . ' 23:59:59'; }

            $total = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE {$where}", $types, $params) ?? 0);
            $bySeverity = \db()->fetchAll("SELECT severity, COUNT(*) as count FROM security_events WHERE {$where} GROUP BY severity", $types, $params);
            $byType = \db()->fetchAll("SELECT event_type, COUNT(*) as count FROM security_events WHERE {$where} GROUP BY event_type ORDER BY count DESC LIMIT 10", $types, $params);
            $critical = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_events WHERE {$where} AND severity = 'CRITICAL'", $types, $params) ?? 0);

            return ['total' => $total, 'critical' => $critical, 'by_severity' => $bySeverity, 'by_type' => $byType];
        } catch (\Throwable $e) { return ['total' => 0, 'critical' => 0, 'by_severity' => [], 'by_type' => []]; }
    }

    public function countEventsByUser(int $userId, string $eventType, int $secondsWindow): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - $secondsWindow);
            return (int) (\db()->fetchValue('SELECT COUNT(*) FROM security_events WHERE user_id = ? AND event_type = ? AND detected_at >= ?', 'iis', [$userId, $eventType, $since]) ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    public function countDistinctResourcesAccessed(int $userId, string $resourceType, int $secondsWindow): int
    {
        try {
            $since = date('Y-m-d H:i:s', time() - $secondsWindow);
            return (int) (\db()->fetchValue('SELECT COUNT(DISTINCT resource_id) FROM security_events WHERE user_id = ? AND resource_type = ? AND detected_at >= ?', 'iss', [$userId, $resourceType, $since]) ?? 0);
        } catch (\Throwable $e) { return 0; }
    }

    private function buildEventData(string $eventType, string $severity, int $riskScore, array $context): array
    {
        return [
            'event_type'      => $eventType,
            'severity'        => $severity,
            'risk_score'      => min(100, max(0, $riskScore)),
            'user_id'         => $context['user_id'] ?? null,
            'ip_address'      => $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            'user_agent'      => isset($context['user_agent']) ? substr($context['user_agent'], 0, 250) : (isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 250) : null),
            'session_id'      => $context['session_id'] ?? null,
            'request_id'      => $context['request_id'] ?? null,
            'http_method'     => $context['http_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null),
            'route'           => $context['route'] ?? ($_SERVER['REQUEST_URI'] ?? null),
            'resource_type'   => $context['resource_type'] ?? null,
            'resource_id'     => isset($context['resource_id']) ? (string) $context['resource_id'] : null,
            'response_status' => $context['response_status'] ?? null,
            'action_taken'    => $context['action_taken'] ?? self::ACTION_LOGGED,
            'description'     => $context['description'] ?? null,
            'metadata'        => !empty($context) ? json_encode($this->redact($context)) : null,
        ];
    }

    private function redact(array $context): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (array_key_exists($key, $context)) { $context[$key] = '[REDACTED]'; }
        }
        foreach ($context as $key => $value) {
            if (is_array($value)) { $context[$key] = $this->redact($value); }
        }
        return $context;
    }

        public function getEventById(int $id): ?array
    {
        try { return \db()->fetchOne('SELECT * FROM security_events WHERE id = ?', 'i', [$id]); }
        catch (\Throwable $e) { return null; }
    }

    public function getRecentEvents(int $limit = 20): array
    {
        try { return \db()->fetchAll('SELECT * FROM security_events ORDER BY detected_at DESC LIMIT ?', 'i', [$limit]); }
        catch (\Throwable $e) { return []; }
    }

    public function getRecentEventsBySeverity(string $severity, int $limit = 20): array
    {
        try { return \db()->fetchAll('SELECT * FROM security_events WHERE severity = ? ORDER BY detected_at DESC LIMIT ?', 'si', [$severity, $limit]); }
        catch (\Throwable $e) { return []; }
    }

    private function __clone(): void {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }
}


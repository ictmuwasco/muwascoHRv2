<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\AuditService;

/**
 * SecurityIncidentService — manages correlated security incidents.
 */
final class SecurityIncidentService
{
    public const STATUS_NEW             = 'NEW';
    public const STATUS_INVESTIGATING   = 'INVESTIGATING';
    public const STATUS_CONTAINED       = 'CONTAINED';
    public const STATUS_RESOLVED        = 'RESOLVED';
    public const STATUS_FALSE_POSITIVE  = 'FALSE_POSITIVE';

    public const SOURCE_RULE_ENGINE  = 'rule_engine';
    public const SOURCE_AI_ANALYSIS  = 'ai_analysis';
    public const SOURCE_MANUAL       = 'manual';

    private static ?SecurityIncidentService $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Create a new incident from a security event or correlated events.
     */
    public function create(
        string $severity = 'LOW',
        int $riskScore = 0,
        string $source = self::SOURCE_RULE_ENGINE,
        string $summary = '',
        array $eventIds = [],
        array $aiData = []
    ): ?int {
        try {
            $uuid = $this->uuidV4();
            $id = \db()->insert('security_incidents', [
                'incident_uuid'         => $uuid,
                'severity'              => $severity,
                'risk_score'            => min(100, max(0, $riskScore)),
                'status'                => self::STATUS_NEW,
                'source'                => $source,
                'summary'               => $summary,
                'ai_classification'     => $aiData['classification'] ?? null,
                'ai_confidence'         => $aiData['confidence'] ?? null,
                'ai_reasoning'          => $aiData['reasoning'] ?? null,
                'ai_risk_score'         => $aiData['risk_score'] ?? null,
                'ai_recommended_action' => $aiData['recommended_action'] ?? null,
                'related_event_count'   => count($eventIds),
            ]);

            foreach ($eventIds as $eventId) { $this->addEventToIncident($id, (int) $eventId); }

            AuditService::getInstance()->log(
                'Security', 'SECURITY_INCIDENT_CREATED',
                "Security incident created: {$severity} (risk={$riskScore}, source={$source})",
                ['target_type' => 'SecurityIncident', 'target_id' => $id]
            );

            return $id;
        } catch (\Throwable $e) {
            \logger()->error('Failed to create security incident', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function getIncidents(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        try {
            $page = max(1, $page); $perPage = min(100, max(1, $perPage)); $offset = ($page - 1) * $perPage;
            $where = '1=1'; $params = []; $types = '';
            if (!empty($filters['severity'])) { $where .= ' AND severity = ?'; $types .= 's'; $params[] = $filters['severity']; }
            if (!empty($filters['status'])) { $where .= ' AND status = ?'; $types .= 's'; $params[] = $filters['status']; }
            if (!empty($filters['source'])) { $where .= ' AND source = ?'; $types .= 's'; $params[] = $filters['source']; }
            if (isset($filters['risk_score_min'])) { $where .= ' AND risk_score >= ?'; $types .= 'i'; $params[] = (int) $filters['risk_score_min']; }
            if (!empty($filters['date_from'])) { $where .= ' AND first_seen >= ?'; $types .= 's'; $params[] = $filters['date_from'] . ' 00:00:00'; }
            if (!empty($filters['date_to'])) { $where .= ' AND first_seen <= ?'; $types .= 's'; $params[] = $filters['date_to'] . ' 23:59:59'; }

            $total = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_incidents WHERE {$where}", $types, $params) ?? 0);
            $sortBy = in_array($filters['sort_by'] ?? '', ['first_seen','last_seen','severity','risk_score','status'], true) ? $filters['sort_by'] : 'last_seen';
            $sortDir = isset($filters['sort_dir']) && strtoupper($filters['sort_dir']) === 'ASC' ? 'ASC' : 'DESC';
            $data = \db()->fetchAll("SELECT * FROM security_incidents WHERE {$where} ORDER BY {$sortBy} {$sortDir} LIMIT {$perPage} OFFSET {$offset}", $types, $params);
            return ['data' => $data, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => (int) ceil($total / $perPage)];
        } catch (\Throwable $e) { return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 0]; }
    }

    public function getIncidentById(int $id): ?array
    {
        try {
            $incident = \db()->fetchOne('SELECT * FROM security_incidents WHERE id = ?', 'i', [$id]);
            if (!$incident) return null;
            $incident['related_events'] = \db()->fetchAll(
                'SELECT se.* FROM security_events se INNER JOIN security_incident_events sie ON se.id = sie.event_id WHERE sie.incident_id = ? ORDER BY se.detected_at DESC', 'i', [$id]
            );
            return $incident;
        } catch (\Throwable $e) { return null; }
    }

    public function getIncidentStats(): array
    {
        try {
            $total = (int) (\db()->fetchValue('SELECT COUNT(*) FROM security_incidents') ?? 0);
            $active = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_incidents WHERE status IN ('NEW','INVESTIGATING','CONTAINED')") ?? 0);
            $critical = (int) (\db()->fetchValue("SELECT COUNT(*) FROM security_incidents WHERE severity = 'CRITICAL' AND status IN ('NEW','INVESTIGATING','CONTAINED')") ?? 0);
            $byStatus = \db()->fetchAll('SELECT status, COUNT(*) as count FROM security_incidents GROUP BY status');
            $bySeverity = \db()->fetchAll('SELECT severity, COUNT(*) as count FROM security_incidents GROUP BY severity');
            return ['total' => $total, 'active' => $active, 'critical' => $critical, 'by_status' => $byStatus, 'by_severity' => $bySeverity];
        } catch (\Throwable $e) { return ['total' => 0, 'active' => 0, 'critical' => 0, 'by_status' => [], 'by_severity' => []]; }
    }

    public function updateStatus(int $id, string $status, int $resolvedBy = 0, string $notes = ''): bool
    {
        try {
            $data = ['status' => $status];
            if (in_array($status, [self::STATUS_RESOLVED, self::STATUS_FALSE_POSITIVE], true)) {
                $data['resolved_at'] = date('Y-m-d H:i:s');
                $data['resolved_by'] = $resolvedBy;
                $data['resolution_notes'] = $notes;
            }
            \db()->update('security_incidents', $data, 'id = ?', 'i', [$id]);
            AuditService::getInstance()->log('Security', 'SECURITY_INCIDENT_UPDATED', "Incident #{$id} → {$status}", ['target_type' => 'SecurityIncident', 'target_id' => $id]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public function addEventToIncident(int $incidentId, int $eventId): bool
    {
        try {
            \db()->query(
                'INSERT IGNORE INTO security_incident_events (incident_id, event_id) VALUES (?, ?)',
                'ii',
                [$incidentId, $eventId]
            );
            \db()->query(
                'UPDATE security_incidents SET related_event_count = related_event_count + 1, last_seen = NOW() WHERE id = ?',
                'i',
                [$incidentId]
            );
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public function updateAiAnalysis(int $id, array $aiData): bool
    {
        try {
            \db()->update('security_incidents', [
                'ai_classification' => $aiData['classification'] ?? null,
                'ai_confidence' => $aiData['confidence'] ?? null,
                'ai_reasoning' => $aiData['reasoning'] ?? null,
                'ai_risk_score' => $aiData['risk_score'] ?? null,
                'ai_recommended_action' => $aiData['recommended_action'] ?? null,
            ], 'id = ?', 'i', [$id]);
            return true;
        } catch (\Throwable $e) { return false; }
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

        public function getRecentIncidents(int $limit = 10): array
    {
        try { return \db()->fetchAll('SELECT * FROM security_incidents ORDER BY last_seen DESC LIMIT ?', 'i', [$limit]); }
        catch (\Throwable $e) { return []; }
    }

    private function __clone(): void {}
    public function __wakeup() { throw new \RuntimeException('Cannot unserialize singleton'); }
}


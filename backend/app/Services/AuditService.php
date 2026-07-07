<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Audit Service - Structured audit logging for all system activities.
 * 
 * Tracks create, update, delete, login/logout, and other important actions
 * with full metadata including user, IP, device fingerprint, and data changes.
 */
class AuditService
{
    private static ?AuditService $instance = null;

    private function __construct() {}

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log an audit event.
     */
    public function log(
        string $action,
        string $description,
        array $details = []
    ): int {
        $db = \db();
        $userId = $_SESSION['user_id'] ?? 0;
        $username = $_SESSION['user_name'] ?? 'system';
        $userRole = $_SESSION['user_role'] ?? 'guest';

        $ipAddress = $this->getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionId = session_id();
        $url = $this->getCurrentUrl();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

        return $db->insert('audit_logs', [
            'user_id' => $userId,
            'username' => $username,
            'user_role' => $userRole,
            'action_type' => $action,
            'table_name' => $details['table_name'] ?? null,
            'record_id' => $details['record_id'] ?? null,
            'old_values' => isset($details['old_values']) ? json_encode($details['old_values']) : null,
            'new_values' => isset($details['new_values']) ? json_encode($details['new_values']) : null,
            'description' => $description,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'url' => $url,
            'method' => $method,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Log a CREATE action.
     */
    public function logCreate(string $table, int $recordId, array $data, string $description = ''): int
    {
        return $this->log(
            'CREATE',
            $description ?: "Created new record in {$table}",
            [
                'table_name' => $table,
                'record_id' => $recordId,
                'new_values' => $data,
            ]
        );
    }

    /**
     * Log an UPDATE action with before/after data.
     */
    public function logUpdate(string $table, int $recordId, array $oldData, array $newData, string $description = ''): int
    {
        return $this->log(
            'UPDATE',
            $description ?: "Updated record in {$table}",
            [
                'table_name' => $table,
                'record_id' => $recordId,
                'old_values' => $oldData,
                'new_values' => $newData,
            ]
        );
    }

    /**
     * Log a DELETE action.
     */
    public function logDelete(string $table, int $recordId, array $oldData, string $description = ''): int
    {
        return $this->log(
            'DELETE',
            $description ?: "Deleted record from {$table}",
            [
                'table_name' => $table,
                'record_id' => $recordId,
                'old_values' => $oldData,
            ]
        );
    }

    /**
     * Log a login event.
     */
    public function logLogin(string $username, bool $success = true): int
    {
        return $this->log(
            $success ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED',
            $success ? "User logged in successfully" : "Failed login attempt for user: {$username}"
        );
    }

    /**
     * Log a logout event.
     */
    public function logLogout(): int
    {
        return $this->log('LOGOUT', 'User logged out');
    }

    /**
     * Log an approval action.
     */
    public function logApproval(string $table, int $recordId, string $action, string $comment = ''): int
    {
        return $this->log(
            'APPROVAL',
            "{$action} on {$table} #{$recordId}: {$comment}",
            [
                'table_name' => $table,
                'record_id' => $recordId,
                'new_values' => ['approval_action' => $action, 'comment' => $comment],
            ]
        );
    }

    /**
     * Log an export action.
     */
    public function logExport(string $table, array $filters = []): int
    {
        return $this->log(
            'EXPORT',
            "Exported data from {$table}",
            [
                'table_name' => $table,
                'new_values' => $filters,
            ]
        );
    }

    /**
     * Log a page view.
     */
    public function logPageView(): int
    {
        $page = basename($_SERVER['PHP_SELF'] ?? 'unknown');
        return $this->log(
            'PAGE_VIEW',
            "Viewed page: {$page}",
            [
                'url' => $this->getCurrentUrl(),
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            ]
        );
    }

    /**
     * Log a role or permission change.
     */
    public function logPermissionChange(int $userId, string $oldRole, string $newRole, string $changedBy): int
    {
        return $this->log(
            'PERMISSION_CHANGE',
            "Role changed for user #{$userId}: {$oldRole} -> {$newRole}",
            [
                'table_name' => 'users',
                'record_id' => $userId,
                'old_values' => ['role' => $oldRole],
                'new_values' => ['role' => $newRole, 'changed_by' => $changedBy],
            ]
        );
    }

    /**
     * Log suspicious activity.
     */
    public function logSuspiciousActivity(string $activity, array $context = []): int
    {
        return $this->log(
            'SUSPICIOUS_ACTIVITY',
            $activity,
            $context
        );
    }

    /**
     * Get recent audit logs.
     */
    public function getRecentLogs(int $limit = 50, array $filters = []): array
    {
        $db = \db();
        
        $sql = "SELECT * FROM audit_logs WHERE 1=1";
        $types = '';
        $params = [];
        
        if (!empty($filters['action_type'])) {
            $sql .= " AND action_type = ?";
            $types .= 's';
            $params[] = $filters['action_type'];
        }
        
        if (!empty($filters['user_id'])) {
            $sql .= " AND user_id = ?";
            $types .= 'i';
            $params[] = (int) $filters['user_id'];
        }
        
        if (!empty($filters['table_name'])) {
            $sql .= " AND table_name = ?";
            $types .= 's';
            $params[] = $filters['table_name'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }
        
        $sql .= " ORDER BY id DESC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;
        
        return $db->fetchAll($sql, $types, $params);
    }

    /**
     * Get the client IP address.
     */
    private function getClientIP(): string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                foreach ($ips as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get the current URL.
     */
    private function getCurrentUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return "{$protocol}://{$host}{$uri}";
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
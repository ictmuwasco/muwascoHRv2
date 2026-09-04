<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AuditService
 *
 * Centralized audit-trail service. Provides a single log() entry-point that
 * captures the actor (user id/name/role), request context (IP, user agent,
 * best-effort location), target record identity, before/after value snapshots
 * and metadata. Sensitive fields (passwords, tokens, secrets) are NEVER stored.
 *
 * Failures are swallowed and logged to the error log — audit calls never
 * throw and never block the happy path.
 *
 * @method static AuditService getInstance()
 */
class AuditService
{
    // ---- Module constants (Phase 5) ----
    public const MODULE_AUTH            = 'Authentication';
    public const MODULE_EMPLOYEES       = 'Employees';
    public const MODULE_USERS           = 'Users';
    public const MODULE_DEPARTMENTS     = 'Departments';
    public const MODULE_LEAVE           = 'Leave';
    public const MODULE_CONSENT         = 'Consent';
    public const MODULE_NOTIFICATIONS   = 'Notifications';
    public const MODULE_SETTINGS        = 'Settings';
    public const MODULE_AUDIT           = 'Audit';
    public const MODULE_FINANCIAL_YEAR  = 'FinancialYear';
     public const MODULE_HOLIDAYS        = 'Holidays';
     public const MODULE_REPORTS         = 'Reports';
     public const MODULE_MEETINGS        = 'Meetings';
    public const MODULE_ATTENDANCE      = 'Attendance';
    public const MODULE_PERFORMANCE     = 'Performance';
    public const MODULE_DELEGATIONS     = 'Delegations';


    // ---- Action constants (Phase 4) ----
    public const ACTION_LOGIN            = 'LOGIN';
    public const ACTION_LOGIN_FAILED     = 'LOGIN_FAILED';
    public const ACTION_LOGOUT           = 'LOGOUT';
    public const ACTION_CREATE           = 'CREATE';
    public const ACTION_UPDATE           = 'UPDATE';
    public const ACTION_DELETE           = 'DELETE';
    public const ACTION_APPROVE          = 'APPROVE';
    public const ACTION_REJECT           = 'REJECT';
    public const ACTION_INVALIDATE       = 'INVALIDATE';
    public const ACTION_STATUS_CHANGE    = 'STATUS_CHANGE';
    public const ACTION_PASSWORD_CHANGE  = 'PASSWORD_CHANGE';
    public const ACTION_PASSWORD_RESET   = 'PASSWORD_RESET';
    public const ACTION_EXPORT           = 'EXPORT';
    public const ACTION_PERMISSION_CHANGE = 'PERMISSION_CHANGE';
    public const ACTION_CONSENT          = 'CONSENT';
    public const ACTION_INVITE           = 'INVITE';
    public const ACTION_REMOVE_INVITATION = 'REMOVE_INVITATION';
    public const ACTION_CONFIRM          = 'CONFIRM';
    public const ACTION_DECLINE          = 'DECLINE';
    public const ACTION_CHECKIN          = 'CHECKIN';
     public const ACTION_CANCEL_MEETING   = 'CANCEL_MEETING';
    public const ACTION_CLOCK_IN        = 'CLOCK_IN';
    public const ACTION_CLOCK_OUT       = 'CLOCK_OUT';
    public const ACTION_VIEW           = 'VIEW';
    public const ACTION_ACCESS_DENIED  = 'ACCESS_DENIED';
    public const ACTION_PUBLISH         = 'PUBLISH';
    public const ACTION_AMEND           = 'AMEND';
    public const ACTION_REOPEN          = 'REOPEN';
    public const ACTION_DELEGATION_CANCELLED = 'DELEGATION_CANCELLED';
    public const ACTION_DELEGATION_EXPIRED   = 'DELEGATION_EXPIRED';

    public const ACTION_MINUTES_CREATED = 'MEETING_MINUTES_CREATED';
    public const ACTION_MINUTES_UPDATED = 'MEETING_MINUTES_UPDATED';
    public const ACTION_MINUTES_PUBLISHED = 'MEETING_MINUTES_PUBLISHED';
    public const ACTION_MINUTES_VIEWED  = 'MEETING_MINUTES_VIEWED';
    public const ACTION_MINUTES_REOPENED = 'MEETING_MINUTES_REOPENED';

    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED  = 'FAILED';
    public const STATUS_DENIED  = 'DENIED';

    /**
     * Field names that must never be persisted to the audit log.
     * Values matching any of these (case- and separator-insensitive) are
     * replaced with a placeholder in old_values/new_values snapshots.
     */
    private const SENSITIVE_FIELDS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'jwt', 'authorization',
        'secret', 'api_key', 'apikey', 'client_secret', 'cookie', 'session_id',
    ];

    private static ?AuditService $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Central log entry point. Never throws — all errors are swallowed and
     * written to the error log.
     *
     * @param string $module      One of the MODULE_* constants.
     * @param string $action      One of the ACTION_* constants.
     * @param string $description Human-readable description of the event.
     * @param array  $options {
     *   @type int|null    $user_id            Actor user id (defaults to session user id).
     *   @type string|null $user_name_snapshot Actor display name (defaults to session user name).
     *   @type string|null $user_role_snapshot Actor role (defaults to session user role).
     *   @type string|null $target_type        Type of target record (Employee, LeaveRequest, ...).
     *   @type int|null    $target_id          PK of the target record.
     *   @type string|null $target_name        Display name of the target record.
     *   @type array|null  $old_values         Snapshot before the change.
     *   @type array|null  $new_values         Snapshot after the change.
     *   @type array|null  $metadata           Additional non-sensitive metadata.
     *   @type string      $status             STATUS_SUCCESS | STATUS_FAILED.
     *   @type string|null $ip_address         Override IP (defaults to client IP).
     * }
     * @return int|null The inserted audit_logs id, or null on failure.
     */
    public function log(string $module, string $action, string $description, array $options = []): ?int
    {
        try {
            $user = $this->resolveActor($options);
            $oldValues = $this->filterSensitive($options['old_values'] ?? null);
            $newValues = $this->filterSensitive($options['new_values'] ?? null);

            $data = [
                'user_id'            => $user['id'],
                'user_name_snapshot' => $user['name'],
                'user_role_snapshot' => $user['role'],
                'request_id'         => \App\Services\ErrorTracking\RequestIdService::current(),
                'action'             => $action,
                'module'             => $module,
                'description'        => mb_substr($description, 0, 5000),
                'target_type'        => $options['target_type']       ?? null,
                'target_id'          => $options['target_id']         ?? null,
                'target_name'        => $options['target_name']       ?? null,
                'ip_address'         => $options['ip_address']        ?? $this->clientIp(),
                'user_agent'         => $this->clientUserAgent(),
                'location'           => $this->bestEffortLocation(),
                'old_values'         => $oldValues !== null ? json_encode($oldValues) : null,
                'new_values'         => $newValues !== null ? json_encode($newValues) : null,
                'metadata'           => !empty($options['metadata']) ? json_encode($options['metadata']) : null,
                'status'             => $options['status'] ?? self::STATUS_SUCCESS,
                'created_at'         => date('Y-m-d H:i:s'),
            ];

            return db()->insert('audit_logs', $data);
        } catch (\Throwable $e) {
            error_log('[AuditService] Failed to write audit log: ' . $e->getMessage());
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Query methods used by AuditLogController (Phase 8)
    // ------------------------------------------------------------------

    /**
     * Search audit logs with pagination, filtering and sorting.
     *
     * @return array{data: array<int, array<string,mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 20, string $sort = 'created_at', string $order = 'DESC'): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $sort = in_array($sort, ['created_at', 'action', 'module', 'user_name_snapshot', 'user_role_snapshot', 'status', 'ip_address', 'id'], true) ? $sort : 'created_at';
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $types, $params] = $this->buildWhere($filters);

        try {
            $total = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM audit_logs {$where}",
                $types,
                $params
            );

            $offset = ($page - 1) * $perPage;
            $sql = "SELECT * FROM audit_logs {$where} ORDER BY {$sort} {$order} LIMIT ? OFFSET ?";
            $types .= 'ii';
            $params = array_merge($params, [$perPage, $offset]);

            $rows = db()->fetchAll($sql, $types, $params);
            foreach ($rows as &$row) {
                $row['old_values'] = $this->decodeJson($row['old_values']);
                $row['new_values'] = $this->decodeJson($row['new_values']);
                $row['metadata']   = $this->decodeJson($row['metadata']);
            }
            unset($row);

            return [
                'data'     => $rows,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ];
        } catch (\Throwable $e) {
            error_log('[AuditService] search failed: ' . $e->getMessage());
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
    }

    public function getById(int $id): ?array
    {
        try {
            $row = db()->fetchOne('SELECT * FROM audit_logs WHERE id = ?', 'i', [$id]);
            if ($row === null) {
                return null;
            }
            $row['old_values'] = $this->decodeJson($row['old_values']);
            $row['new_values'] = $this->decodeJson($row['new_values']);
            $row['metadata']   = $this->decodeJson($row['metadata']);
            return $row;
        } catch (\Throwable $e) {
            error_log('[AuditService] getById failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Summary statistics for the audit dashboard.
     *
     * @return array<string, mixed>
     */
    public function statistics(): array
    {
        try {
            $total      = (int) db()->fetchValue('SELECT COUNT(*) FROM audit_logs');
            $success    = (int) db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE status = 'SUCCESS'");
            $failed     = (int) db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE status = 'FAILED'");
            $byModule   = db()->fetchAll('SELECT module, COUNT(*) AS count FROM audit_logs GROUP BY module ORDER BY count DESC LIMIT 10');
            $actions30  = (int) db()->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

            return [
                'total_logs'      => $total,
                'success'         => $success,
                'failed'          => $failed,
                'last_30_days'    => $actions30,
                'by_module'       => $byModule,
            ];
        } catch (\Throwable $e) {
            error_log('[AuditService] statistics failed: ' . $e->getMessage());
            return [
                'total_logs'   => 0, 'success' => 0, 'failed' => 0,
                'last_30_days' => 0, 'by_module' => [],
            ];
        }
    }

    /**
     * Distinct values for the filter dropdowns.
     *
     * @return array<string, array<int, string>>
     */
    public function getFilterOptions(): array
    {
        try {
            $actions = array_column(db()->fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
            $modules = array_column(db()->fetchAll('SELECT DISTINCT module FROM audit_logs ORDER BY module'), 'module');
            $roles   = array_column(db()->fetchAll('SELECT DISTINCT user_role_snapshot FROM audit_logs WHERE user_role_snapshot IS NOT NULL ORDER BY user_role_snapshot'), 'user_role_snapshot');
            $status  = array_column(db()->fetchAll('SELECT DISTINCT status FROM audit_logs ORDER BY status'), 'status');
            $users   = array_column(db()->fetchAll('SELECT DISTINCT user_name_snapshot FROM audit_logs WHERE user_name_snapshot IS NOT NULL ORDER BY user_name_snapshot'), 'user_name_snapshot');

            return [
                'actions' => $actions,
                'modules' => $modules,
                'roles'   => $roles,
                'status'  => $status,
                'users'   => $users,
            ];
        } catch (\Throwable $e) {
            error_log('[AuditService] getFilterOptions failed: ' . $e->getMessage());
            return ['actions' => [], 'modules' => [], 'roles' => [], 'status' => [], 'users' => []];
        }
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Resolve the actor identity from session (or explicit overrides).
     *
     * @return array{id: int|null, name: string|null, role: string|null}
     */
    private function resolveActor(array $options): array
    {
        return [
            'id'   => isset($options['user_id']) ? (int) $options['user_id'] : (int) ($_SESSION['user_id'] ?? 0),
            'name' => $options['user_name_snapshot'] ?? (isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : null),
            'role' => $options['user_role_snapshot'] ?? (isset($_SESSION['user_role']) ? (string) $_SESSION['user_role'] : null),
        ];
    }

    /**
     * Strip sensitive fields from before/after snapshots before storage.
     */
    private function filterSensitive(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $filtered = [];
        foreach ($values as $key => $value) {
            $normalized = strtolower((string) $key);
            $normalized = str_replace(['_', '-', ' '], '', $normalized);

            $isSensitive = false;
            foreach (self::SENSITIVE_FIELDS as $sensitive) {
                $s = str_replace(['_', '-', ' '], '', $sensitive);
                if ($normalized === $s || strpos($normalized, $s) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            $filtered[$key] = $isSensitive ? '[REDACTED]' : $value;
        }

        return $filtered;
    }

    private function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['HTTP_X_REAL_IP'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_CLIENT_IP'] ?? null,
        ];

        foreach ($candidates as $ip) {
            if (is_string($ip) && $ip !== '') {
                // X-Forwarded-For may contain a comma-separated list.
                $first = trim(explode(',', $ip)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        return '';
    }

    private function clientUserAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return mb_substr(trim($ua), 0, 2048);
    }

    /**
     * Best-effort IP-derived location. Kept deliberately dependency-free and
     * non-blocking. If a static IP->location mapping exists in config
     * (config/audit.php => 'ip_locations'), it is used; otherwise null.
     */
    private function bestEffortLocation(): ?string
    {
        try {
            $mapping = config('audit.ip_locations', []);
            if (is_array($mapping) && !empty($mapping)) {
                $ip = $this->clientIp();
                if ($ip !== '') {
                    if (isset($mapping[$ip])) {
                        return (string) $mapping[$ip];
                    }
                    // Generic external-range mapping (e.g. LAN vs. Internet)
                    if (strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0 || strpos($ip, '127.') === 0) {
                        return 'Local network';
                    }
                    return 'Internet';
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decodeJson(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Build the WHERE clause + bind params for search filters.
     *
     * @return array{0: string, 1: string, 2: array<int, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $types   = '';
        $params  = [];

        if (!empty($filters['action'])) {
            $clauses[] = 'action = ?';
            $types .= 's';
            $params[] = (string) $filters['action'];
        }
        if (!empty($filters['module'])) {
            $clauses[] = 'module = ?';
            $types .= 's';
            $params[] = (string) $filters['module'];
        }
        if (!empty($filters['user_id'])) {
            $clauses[] = 'user_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['user_name_snapshot'])) {
            $clauses[] = 'user_name_snapshot = ?';
            $types .= 's';
            $params[] = (string) $filters['user_name_snapshot'];
        }
        if (!empty($filters['user_role_snapshot'])) {
            $clauses[] = 'user_role_snapshot = ?';
            $types .= 's';
            $params[] = (string) $filters['user_role_snapshot'];
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'status = ?';
            $types .= 's';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['target_type'])) {
            $clauses[] = 'target_type = ?';
            $types .= 's';
            $params[] = (string) $filters['target_type'];
        }
        if (!empty($filters['target_id'])) {
            $clauses[] = 'target_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['target_id'];
        }
        if (!empty($filters['date_from'])) {
            $clauses[] = 'created_at >= ?';
            $types .= 's';
            $params[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'created_at <= ?';
            $types .= 's';
            $params[] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(description LIKE ? OR target_name LIKE ? OR user_name_snapshot LIKE ? OR ip_address LIKE ?)';
            $like = '%' . (string) $filters['search'] . '%';
            $types .= 'ssss';
            array_push($params, $like, $like, $like, $like);
        }

        $where = empty($clauses) ? '' : ' WHERE ' . implode(' AND ', $clauses);
        return [$where, $types, $params];
    }

    private function __clone(): void {}

    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
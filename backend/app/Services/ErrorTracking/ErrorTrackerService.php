<?php

declare(strict_types=1);

namespace App\Services\ErrorTracking;

/**
 * ErrorTrackerService
 *
 * THE single entry point for error capture. Wired once into the global
 * exception handler / shutdown handler (bootstrap.php) so that NO controller,
 * service or job needs manual try/catch logging.
 *
 * Guarantees:
 *  - Never throws, never blocks the application (§28): every DB operation is
 *    wrapped; on failure the event degrades to error_log() only.
 *  - Recursion-safe: a failure while persisting an error cannot re-enter.
 *  - Groups occurrences by fingerprint and maintains occurrence /
 *    affected-user counters incrementally.
 *  - Emits alerts for CRITICAL groups with cooldown so a 5,000-occurrence
 *    storm does not spam notifications (§25).
 */
final class ErrorTrackerService
{
    private static ?ErrorTrackerService $instance = null;

    /** Re-entrancy guard: true while we are persisting an error. */
    private static bool $capturing = false;

    /** Exception SPL hashes already handled this request (avoid double-capture). */
    private static array $handled = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ------------------------------------------------------------------
    // Public capture API
    // ------------------------------------------------------------------

    /**
     * Capture an unexpected server-side Throwable.
     *
     * @param array $context Optional attribution: http_status, endpoint,
     *                       method, user_id, payload, query, module...
     * @return array{request_id:string, error_uuid:string}|null Reference ids
     *         usable in the API error response; null when tracking disabled.
     */
    public function captureThrowable(\Throwable $e, array $context = []): ?array
    {
        if (!\config('observability.enabled', true)) {
            return null;
        }

        // Never let the tracker recurse into itself.
        if (self::$capturing) {
            self::fallbackLog($e);
            return null;
        }
        self::$capturing = true;

        try {
            $hash = spl_object_hash($e);
            if (isset(self::$handled[$hash])) {
                return null;
            }
            // Store the OBJECT (not just the hash): spl_object_hash values are
            // recycled once an object is GC'd - storing the hash alone would
            // let a brand-new exception collide with a destroyed one's key and
            // be silently dropped.
            self::$handled[$hash] = $e;

            $event = $this->buildServerEvent($e, $context);
            $this->persist($event);

            return [
                'request_id' => $event['request_id'],
                'error_uuid' => $event['error_uuid'],
            ];
        } catch (\Throwable $trackerFailure) {
            // The error logger itself failed - degrade gracefully (§28).
            self::fallbackLog($e);
            error_log('[ErrorTracker] capture failed: ' . $trackerFailure->getMessage());
            return null;
        } finally {
            self::$capturing = false;
        }
    }

    /** Human-readable reference for users: ERR-20260825-XXXXXX */
    public static function newErrorUuid(): string
    {
        try {
            $rand = strtoupper(bin2hex(random_bytes(3)));
        } catch (\Throwable) {
            $rand = strtoupper(substr(md5(uniqid('', true)), 0, 6));
        }
        return 'ERR-' . date('Ymd') . '-' . $rand;
    }


    // ------------------------------------------------------------------
    // Client reports (browser errors forwarded by the React layer)
    // ------------------------------------------------------------------

    /**
     * Persist a sanitized client-side error report coming from the React
     * monitoring layer. Returns the stored reference id (error_uuid).
     */
    public function captureClientReport(array $report): ?string
    {
        if (!\config('observability.enabled', true)) {
            return null;
        }

        try {
            $message = mb_substr((string) ($report['message'] ?? 'Unknown client error'), 0, 1000);
            $urlPath = (string) (parse_url((string) ($report['url'] ?? ''), PHP_URL_PATH) ?: '');
            $module  = self::detectModuleFromUri($urlPath);
            $kind    = strtolower((string) ($report['kind'] ?? 'error'));

            $fingerprint = ErrorFingerprint::make(
                $module,
                'Frontend' . ucfirst($kind),
                $message . '|' . $urlPath . '|' . (string) ($report['component'] ?? '')
            );

            $severityInput = strtoupper((string) ($report['severity'] ?? ''));
            $severity      = SeverityClassifier::isValidSeverity($severityInput)
                ? $severityInput
                : SeverityClassifier::SEVERITY_HIGH;

            return $this->persistClientEvent($report, [
                'message'     => $message,
                'url_path'    => $urlPath,
                'module'      => $module,
                'kind'        => $kind,
                'fingerprint' => $fingerprint,
                'severity'    => $severity,
            ]);
        } catch (\Throwable $e) {
            error_log('[ErrorTracker] client report failed: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Capture a message-level event (cron jobs, background processing,
     * explicit spots). Accepts an optional `exception` in $context.
     */
    public function captureMessage(string $message, array $context = []): ?string
    {
        if (($context['exception'] ?? null) instanceof \Throwable) {
            $result = $this->captureThrowable($context['exception'], $context);
            return $result['error_uuid'] ?? null;
        }

        if (!\config('observability.enabled', true)) {
            return null;
        }

        try {
            $message  = mb_substr($message, 0, 1000);
            $module   = (string) ($context['module'] ?? 'System');
            $parts    = ErrorFingerprint::make($module, 'RuntimeError', $message);
            $uuid     = self::newErrorUuid();
            $now      = date('Y-m-d H:i:s');
            $severity = strtoupper((string) ($context['severity'] ?? SeverityClassifier::SEVERITY_MEDIUM));
            if (!SeverityClassifier::isValidSeverity($severity)) {
                $severity = SeverityClassifier::SEVERITY_MEDIUM;
            }
            $userId = $this->currentUserId([]);

            $groupId = $this->upsertGroup([
                'fingerprint_hash' => $parts['hash'],
                'fingerprint'      => $parts['fingerprint'],
                'title'            => $this->titleFor('System message', $message),
                'module'           => $module,
                'category'         => SeverityClassifier::CATEGORY_SYSTEM_ERROR,
                'severity'         => $severity,
                'exception_class'  => 'RuntimeError',
                'sample_message'   => RequestSanitizer::scrubSecretsFromText($message),
                'sample_endpoint'  => isset($context['endpoint']) ? mb_substr((string) $context['endpoint'], 0, 255) : null,
                'environment'      => (string) \config('app.env', 'production'),
            ]);

            db()->insert('application_errors', [
                'error_uuid'          => $uuid,
                'error_group_id'      => $groupId,
                'fingerprint'         => substr($parts['fingerprint'], 0, 190),
                'fingerprint_hash'    => $parts['hash'],
                'request_id'          => RequestIdService::current(),
                'source'              => 'server',
                'environment'         => (string) \config('app.env', 'production'),
                'application_version' => (string) \config('observability.version', ''),
                'git_commit'          => config('observability.git_commit'),
                'severity'            => $severity,
                'status'              => 'NEW',
                'category'            => SeverityClassifier::CATEGORY_SYSTEM_ERROR,
                'message'             => RequestSanitizer::scrubSecretsFromText($message),
                'user_id'             => $userId > 0 ? $userId : null,
                'created_at'          => $now,
            ]);
            $this->bumpGroupCounters($groupId, max(0, $userId), $now);

            return $uuid;
        } catch (\Throwable $e) {
            error_log('[ErrorTracker] captureMessage failed: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Record a performance measurement. Only events at/above the warning
     * threshold are persisted so normal traffic never touches this table.
     */
    public function recordPerformance(float $durationMs, array $context = []): void
    {
        $perf = \config('observability.performance');
        if (!is_array($perf) || !($perf['enabled'] ?? true)) {
            return;
        }

        if ($durationMs < (int) ($perf['warning_ms'] ?? 2000)) {
            return;
        }

        try {
            $slowMs     = (int) ($perf['slow_ms'] ?? 4000);
            $criticalMs = (int) ($perf['critical_ms'] ?? 8000);

            db()->insert('performance_events', [
                'request_id'          => $context['request_id'] ?? RequestIdService::current(),
                'endpoint'            => mb_substr((string) ($context['endpoint'] ?? ''), 0, 255) ?: null,
                'http_method'         => mb_substr((string) ($context['method'] ?? ''), 0, 10) ?: null,
                'duration_ms'         => (int) round($durationMs),
                'threshold_level'     => $durationMs >= $criticalMs ? 'critical'
                                        : ($durationMs >= $slowMs ? 'slow' : 'warning'),
                'status_code'         => isset($context['status_code']) ? (int) $context['status_code'] : null,
                'user_id'             => $context['user_id'] ?? $this->currentUserId([]),
                'memory_kb'           => isset($context['memory_kb']) ? (int) $context['memory_kb'] : null,
                'environment'         => (string) \config('app.env', 'production'),
                'application_version' => (string) \config('observability.version', ''),
                'created_at'          => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[ErrorTracker] performance record failed: ' . $e->getMessage());
        }
    }

    /** Columns of application_errors an occurrence row may populate. */
    private const OCCURRENCE_COLUMNS = [
        'error_uuid', 'error_group_id', 'fingerprint', 'fingerprint_hash',
        'request_id', 'frontend_error_id', 'source', 'environment',
        'application_version', 'git_commit', 'severity', 'status', 'category',
        'exception_class', 'error_code', 'message', 'file', 'line',
        'stack_trace', 'http_method', 'endpoint', 'route_name', 'status_code',
        'user_id', 'employee_id', 'office_id', 'department_id', 'ip_address',
        'user_agent', 'request_payload', 'request_query', 'request_headers',
        'response_metadata', 'url', 'component', 'browser', 'browser_version',
        'operating_system', 'device_type', 'screen_size', 'created_at',
    ];

    /** Build a normalized, sanitized server-side event from a Throwable. */
    private function buildServerEvent(\Throwable $e, array $context): array
    {
        $httpStatus = isset($context['http_status']) ? max(100, (int) $context['http_status']) : 500;
        $method     = strtoupper((string) ($context['method'] ?? ($_SERVER['REQUEST_METHOD'] ?? '')));
        $rawUri     = (string) ($context['endpoint'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
        $path       = (string) (parse_url($rawUri, PHP_URL_PATH) ?: $rawUri);
        $module     = (string) ($context['module'] ?? self::detectModuleFromUri('/' . ltrim($path, '/')));

        $classification = SeverityClassifier::classify(get_class($e), $httpStatus, $module);
        $classification = SeverityClassifier::classifyMessage($e->getMessage(), $classification);
        $parts          = ErrorFingerprint::make($module, get_class($e), $e->getMessage(), $e->getFile());

        $trace = $e->getTraceAsString();
        if (strlen($trace) > 60000) {
            $trace = substr($trace, 0, 60000) . "\n...[TRUNCATED]";
        }

        return [
            // Grouping / identity ------------------------------------------------
            'error_uuid'          => self::newErrorUuid(),
            'fingerprint'         => substr($parts['fingerprint'], 0, 190),
            'fingerprint_hash'    => $parts['hash'],
            'request_id'          => RequestIdService::current(),
            'source'              => in_array($context['source'] ?? 'server', ['server', 'client', 'job'], true)
                                        ? ($context['source'] ?? 'server') : 'server',
            'module'              => $module, // grouping only - occurrences carry it via their group

            // Deployment identity -------------------------------------------------
            'environment'         => (string) \config('app.env', 'production'),
            'application_version' => mb_substr((string) \config('observability.version', ''), 0, 50) ?: null,
            'git_commit'          => config('observability.git_commit'),

            // Classification ------------------------------------------------------
            'severity'            => $classification['severity'],
            'status'              => 'NEW',
            'category'            => $classification['category'],
            'exception_class'     => mb_substr(get_class($e), 0, 190),
            'error_code'          => mb_substr((string) $e->getCode(), 0, 100) ?: null,
            'message'             => RequestSanitizer::scrubSecretsFromText(mb_substr($e->getMessage(), 0, 2000)),
            'file'                => mb_substr((string) $e->getFile(), 0, 255) ?: null,
            'line'                => $e->getLine() > 0 ? $e->getLine() : null,
            'stack_trace'         => RequestSanitizer::scrubSecretsFromText($trace, false),

            // HTTP context --------------------------------------------------------
            'http_method'         => $method !== '' ? mb_substr($method, 0, 10) : null,
            'endpoint'            => mb_substr($path, 0, 255) ?: null,
            'route_name'          => isset($context['route_name']) ? mb_substr((string) $context['route_name'], 0, 100) : null,
            'status_code'         => $httpStatus,

            // Actor / organization scope (ids only - never PII payloads) ---------
            'user_id'             => ($uid = $this->currentUserId($context)) > 0 ? $uid : null,
            'employee_id'         => isset($context['employee_id']) && (int) $context['employee_id'] > 0 ? (int) $context['employee_id'] : null,
            'office_id'           => isset($context['office_id']) && (int) $context['office_id'] > 0 ? (int) $context['office_id'] : null,
            'department_id'       => isset($context['department_id']) && (int) $context['department_id'] > 0 ? (int) $context['department_id'] : null,

            // Client metadata ------------------------------------------------------
            'ip_address'          => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'user_agent'          => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,

            // Sanitized request captures (RESTRICTED columns) ----------------------
            'request_payload'     => RequestSanitizer::sanitizeToJson($context['payload'] ?? self::currentInput()),
            'request_query'       => RequestSanitizer::sanitizeToJson($_GET ?? []),
            'request_headers'     => RequestSanitizer::sanitizeHeaders($_SERVER ?? []),
            // Structured extras land in the JSON column - job queue/attempt
            // tags and caller-supplied response context travel together.
            'response_metadata'   => (!empty($context['response_metadata']) || !empty($context['tags']))
                                        ? RequestSanitizer::sanitizeToJson([
                                            'response' => $context['response_metadata'] ?? null,
                                            'tags'     => !empty($context['tags'])
                                                ? array_values(array_map('strval', (array) $context['tags']))
                                                : null,
                                          ])
                                        : null,

            'created_at'          => date('Y-m-d H:i:s'),
        ];
    }

    /** Persist one occurrence, roll up counters and evaluate alerting. */
    private function persist(array $e): string
    {
        $groupId = $this->upsertGroup([
            'hash'            => (string) $e['fingerprint_hash'],
            'fingerprint'     => (string) $e['fingerprint'],
            'title'           => $this->titleFor((string) $e['exception_class'], (string) $e['message']),
            'module'          => (string) $e['module'],
            'category'        => (string) $e['category'],
            'severity'        => (string) $e['severity'],
            'environment'     => (string) $e['environment'],
            'exception_class' => (string) $e['exception_class'],
            'message'         => (string) $e['message'],
            'endpoint'        => (string) ($e['endpoint'] ?? ''),
            'method'          => (string) ($e['http_method'] ?? ''),
            'file'            => (string) ($e['file'] ?? ''),
            'line'            => $e['line'] ?? null,
            'now'             => (string) $e['created_at'],
        ]);

        $data                   = array_intersect_key($e, array_flip(self::OCCURRENCE_COLUMNS));
        $data['error_group_id'] = $groupId;
        db()->insert('application_errors', $data);

        $this->bumpGroupCounters($groupId, (int) ($e['user_id'] ?? 0), (string) $e['created_at']);
        $this->maybeNotify($groupId, $e);

        return (string) $e['error_uuid'];
    }

    /**
     * Insert-or-update the deduplicated group row; returns the group id.
     * Recurrence of a RESOLVED / FIXED / IGNORED group re-opens it (§9) while
     * preserving resolution notes as history.
     */
    private function upsertGroup(array $g): int
    {
        $existing = db()->fetchOne(
            "SELECT id, severity, status FROM error_groups WHERE fingerprint_hash = ?",
            's',
            [$g['hash']]
        );

        if ($existing !== null) {
            $updates = ['last_seen_at' => $g['now']];

            // Escalate severity upward only - never silently downgrade.
            if (SeverityClassifier::isAtLeast($g['severity'], (string) $existing['severity'])) {
                $updates['severity'] = $g['severity'];
            }

            if (in_array((string) $existing['status'], ['RESOLVED', 'FIXED', 'VERIFIED', 'IGNORED'], true)) {
                $updates['status']      = 'NEW';
                $updates['resolved_at'] = null;
                $updates['resolved_by'] = null;
            }

            db()->update('error_groups', $updates, 'id = ?', 'i', [(int) $existing['id']]);
            return (int) $existing['id'];
        }

        try {
            return db()->insert('error_groups', [
                'fingerprint'        => $g['fingerprint'],
                'fingerprint_hash'   => $g['hash'],
                'title'              => mb_substr($g['title'], 0, 255),
                'module'             => mb_substr($g['module'], 0, 100),
                'category'           => mb_substr($g['category'], 0, 50),
                'severity'           => mb_substr($g['severity'], 0, 20),
                'status'             => 'NEW',
                'environment'        => mb_substr($g['environment'], 0, 20) ?: 'production',
                'exception_class'    => mb_substr($g['exception_class'], 0, 190) ?: null,
                'sample_message'     => $g['message'] !== '' ? $g['message'] : null,
                'sample_endpoint'    => $g['endpoint'] !== '' ? mb_substr($g['endpoint'], 0, 255) : null,
                'sample_http_method' => $g['method'] !== '' ? mb_substr($g['method'], 0, 10) : null,
                'sample_file'        => $g['file'] !== '' ? mb_substr($g['file'], 0, 255) : null,
                'sample_line'        => $g['line'],
                'first_seen_at'      => $g['now'],
                'last_seen_at'       => $g['now'],
                'created_at'         => $g['now'],
                'updated_at'         => $g['now'],
            ]);
        } catch (\Throwable $race) {
            // Two first-occurrences raced the UNIQUE(fingerprint_hash) key:
            // adopt whichever row won instead of failing the request.
            $retry = db()->fetchOne("SELECT id FROM error_groups WHERE fingerprint_hash = ?", 's', [$g['hash']]);
            if ($retry !== null) {
                return (int) $retry['id'];
            }
            throw $race;
        }
    }

    /**
     * Incremental roll-up: occurrence_count on every hit; affected_user_count
     * recomputed only when INSERT IGNORE reports a genuinely NEW user.
     */
    private function bumpGroupCounters(int $groupId, int $userId, string $now): void
    {
        $stmt = db()->query(
            "UPDATE error_groups SET occurrence_count = occurrence_count + 1, last_seen_at = ? WHERE id = ?",
            'si',
            [$now, $groupId]
        );
        $stmt->close();

        if ($userId <= 0) {
            return;
        }

        $stmt = db()->query(
            "INSERT IGNORE INTO error_group_users (error_group_id, user_id, first_seen_at) VALUES (?, ?, ?)",
            'iis',
            [$groupId, $userId, $now]
        );
        $isNewUser = $stmt->affected_rows > 0;
        $stmt->close();

        if ($isNewUser) {
            $stmt = db()->query(
                "UPDATE error_groups SET affected_user_count =
                    (SELECT COUNT(*) FROM error_group_users WHERE error_group_id = ?)
                 WHERE id = ?",
                'ii',
                [$groupId, $groupId]
            );
            $stmt->close();
        }
    }

    /**
     * Persist a sanitized client-side (browser) event. A separate writer so
     * browser-supplied data can never populate server-only RESTRICTED columns
     * such as request_headers or the SQL-side stack context.
     */
    private function persistClientEvent(array $report, array $meta): ?string
    {
        $now   = date('Y-m-d H:i:s');
        $parts = $meta['fingerprint'];
        $uid   = $this->currentUserId([]);

        $clientVersion = mb_substr((string) ($report['application_version'] ?? ''), 0, 50);
        $serverVersion = mb_substr((string) \config('observability.version', ''), 0, 50);

        $groupId = $this->upsertGroup([
            'hash'            => $parts['hash'],
            'fingerprint'     => substr($parts['fingerprint'], 0, 190),
            'title'           => $this->titleFor('Frontend ' . ucfirst($meta['kind']), $meta['message']),
            'module'          => $meta['module'],
            'category'        => SeverityClassifier::CATEGORY_FRONTEND_ERROR,
            'severity'        => $meta['severity'],
            'environment'     => (string) \config('app.env', 'production'),
            'exception_class' => 'Frontend' . ucfirst($meta['kind']),
            'message'         => $meta['message'],
            'endpoint'        => $meta['url_path'],
            'method'          => '',
            'file'            => '',
            'line'            => null,
            'now'             => $now,
        ]);

        $stack = (string) ($report['stack'] ?? '');
        if ($stack !== '') {
            $stack = mb_substr(RequestSanitizer::scrubSecretsFromText($stack, false), 0, 30000);
        }

        db()->insert('application_errors', [
            'error_uuid'          => self::newErrorUuid(),
            'error_group_id'      => $groupId,
            'fingerprint'         => substr($parts['fingerprint'], 0, 190),
            'fingerprint_hash'    => $parts['hash'],
            'request_id'          => RequestIdService::current(),
            'frontend_error_id'   => mb_substr((string) ($report['frontend_error_id'] ?? ''), 0, 64) ?: null,
            'source'              => 'client',
            'environment'         => (string) \config('app.env', 'production'),
            'application_version' => $clientVersion !== '' ? $clientVersion : ($serverVersion !== '' ? $serverVersion : null),
            'severity'            => $meta['severity'],
            'status'              => 'NEW',
            'category'            => SeverityClassifier::CATEGORY_FRONTEND_ERROR,
            'exception_class'     => 'Frontend' . ucfirst($meta['kind']),
            'message'             => $meta['message'],
            'stack_trace'         => $stack !== '' ? $stack : null,
            'endpoint'            => mb_substr($meta['url_path'], 0, 255) ?: null,
            'user_id'             => $uid > 0 ? $uid : null,
            'ip_address'          => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            'request_payload'     => isset($report['state']) ? RequestSanitizer::sanitizeToJson($report['state']) : null,
            'url'                 => mb_substr((string) ($report['url'] ?? ''), 0, 500) ?: null,
            'component'           => mb_substr((string) ($report['component'] ?? ''), 0, 255) ?: null,
            'browser'             => mb_substr((string) ($report['browser'] ?? ''), 0, 100) ?: null,
            'browser_version'     => mb_substr((string) ($report['browser_version'] ?? ''), 0, 50) ?: null,
            'operating_system'    => mb_substr((string) ($report['operating_system'] ?? ''), 0, 100) ?: null,
            'device_type'         => mb_substr((string) ($report['device_type'] ?? ''), 0, 50) ?: null,
            'screen_size'         => mb_substr((string) ($report['screen_size'] ?? ''), 0, 20) ?: null,
            'response_metadata'   => json_encode(['kind' => $meta['kind']]),
            'created_at'          => $now,
        ]);

        $this->bumpGroupCounters($groupId, $uid, $now);

        return isset($report['frontend_error_id']) ? mb_substr((string) $report['frontend_error_id'], 0, 64) : null;
    }

    /** Capture a failed background/cron job with sanitized payload context. */
    public function captureJobFailure(string $jobName, \Throwable $e, array $meta = []): ?string
    {
        $result = $this->captureThrowable($e, [
            'source'      => 'job',
            'module'      => (string) ($meta['module'] ?? 'System'),
            'http_status' => 500,
            'method'      => 'JOB',
            'endpoint'    => 'job://' . $jobName,
            'payload'     => $meta['payload'] ?? null,
            'employee_id' => $meta['employee_id'] ?? null,
            'user_id'     => $meta['user_id'] ?? null,
            'tags'        => array_values(array_filter(array_merge(
                ['job:' . $jobName],
                isset($meta['queue']) ? ['queue:' . $meta['queue']] : [],
                isset($meta['attempt']) ? ['attempt:' . (int) $meta['attempt']] : []
            ))),
        ]);

        return $result['error_uuid'] ?? null;
    }

    /**
     * CRITICAL-severity alerting with cooldown dedup (§25). Baseline-vs-current
     * hour comparison flags spikes (+X%); delivery is the system error log -
     * the dashboard visualizes group counters and spike windows from there.
     */
    private function maybeNotify(int $groupId, array $e): void
    {
        $cfg = \config('observability.notifications');
        if (!is_array($cfg)) {
            return;
        }

        $severities = array_map('strtoupper', (array) ($cfg['notify_severities'] ?? ['CRITICAL']));
        if (!in_array(strtoupper((string) $e['severity']), $severities, true)) {
            return;
        }

        $row = db()->fetchOne(
            "SELECT title, last_notified_at, occurrence_count FROM error_groups WHERE id = ?",
            'i',
            [$groupId]
        );
        if ($row === null) {
            return;
        }

        // Cooldown: first occurrence alerts; the next N just bump counters.
        $cooldown = max(1, (int) ($cfg['cooldown_minutes'] ?? 60));
        if (!empty($row['last_notified_at'])) {
            $elapsedMinutes = (time() - strtotime((string) $row['last_notified_at'])) / 60;
            if ($elapsedMinutes < $cooldown) {
                return;
            }
        }

        // Cheap indexed counts - executed ONLY when about to notify.
        $lastHour = (int) db()->fetchValue(
            "SELECT COUNT(*) FROM application_errors
             WHERE error_group_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            'i',
            [$groupId]
        );
        $previousDay = (int) db()->fetchValue(
            "SELECT COUNT(*) FROM application_errors
             WHERE error_group_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 25 HOUR)
               AND created_at <  DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            'i',
            [$groupId]
        );

        $baselinePerHour = $previousDay / 24;
        $minHourly       = max(1, (int) ($cfg['spike_min_hourly'] ?? 5));
        $spikePercent    = null;
        if ($lastHour >= $minHourly) {
            $spikePercent = $baselinePerHour >= 1
                ? (int) round((($lastHour - $baselinePerHour) / $baselinePerHour) * 100)
                : 999; // spike out of silence
        }
        $isSpike = $spikePercent !== null && $spikePercent >= (int) ($cfg['spike_increase_percent'] ?? 300);

        db()->update('error_groups', ['last_notified_at' => date('Y-m-d H:i:s')], 'id = ?', 'i', [$groupId]);

        error_log(sprintf(
            '[ErrorTracker][ALERT][%s] %s | fingerprint=%s | total=%d | last_hour=%d | spike=%s | request_id=%s | version=%s',
            strtoupper((string) $e['severity']),
            (string) $row['title'],
            (string) $e['fingerprint'],
            (int) $row['occurrence_count'],
            $lastHour,
            $isSpike && $spikePercent !== null ? sprintf('+%d%%', $spikePercent) : 'n/a',
            RequestIdService::current(),
            (string) \config('observability.version', '')
        ));
    }

    /** Current actor id from explicit context or session (0 = anonymous/CLI). */
    private function currentUserId(array $context): int
    {
        $explicit = (int) ($context['user_id'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }

    /** Raw request input (form fields + JSON body) handed to the sanitizer. */
    private static function currentInput(): array
    {
        $input = is_array($_POST ?? null) ? $_POST : [];
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (is_array($decoded)) {
                $input = array_merge($input, $decoded);
            }
        }
        return $input;
    }

    /** Map a URI path to an HR module label reusing the existing taxonomy. */
    public static function detectModuleFromUri(string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', trim(parse_url($path, PHP_URL_PATH) ?: $path, '/')),
            static fn ($s) => $s !== ''
        ));

        // Raw REQUEST_URI may carry deployment/API prefixes (/hrdemo/api/...)
        // - skip them so the first BUSINESS segment decides the module.
        while (!empty($segments) && in_array(strtolower($segments[0]), ['hrdemo', 'api'], true)) {
            array_shift($segments);
        }

        $segment = strtolower((string) ($segments[0] ?? ''));

        return match (true) {
            in_array($segment, ['auth', 'login'], true)                          => 'Authentication',
            $segment === 'attendance'                                            => 'Attendance',
            $segment === 'leave'                                                 => 'Leave',
            in_array($segment, ['employees', 'profile'], true)                   => 'Employees',
            in_array($segment, ['departments', 'sections', 'subsections'], true) => 'Departments',
            in_array($segment, ['meetings', 'my-meetings'], true)                => 'Meetings',
            $segment === 'payroll'                                               => 'Payroll',
            $segment === 'reports'                                               => 'Reports',
            $segment === 'dashboard'                                             => 'Dashboard',
            $segment === 'consent'                                               => 'Consent',
            $segment === 'holidays'                                              => 'Holidays',
            $segment === 'financial-year'                                        => 'Financial Year',
            in_array($segment, ['notifications', 'push'], true)                  => 'Notifications',
            in_array($segment, ['settings', 'permissions', 'admin'], true)       => 'Administration',
            $segment === 'system'                                                => 'System Monitoring',
            default                                                              => 'System',
        };
    }

    /** Short human title derived from the exception message. */
    private function titleFor(string $fallback, string $message): string
    {
        $words = preg_split('/\s+/', trim(strip_tags($message))) ?: [];
        $title = trim(implode(' ', array_slice($words, 0, 12)));
        return $title !== '' ? mb_substr(ucfirst($title), 0, 120) : $fallback;
    }

    /** Last-resort sink when persistence itself fails (§28). */
    private static function fallbackLog(\Throwable $e): void
    {
        error_log(sprintf(
            '[ErrorTracker][FALLBACK] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    /**
     * Retention sweep (§26) - batched DELETEs driven by observability config.
     * Groups whose last occurrence aged out are purged once resolved/ignored.
     *
     * @return array<string,int> deleted row counts per table slice
     */
    public function cleanup(): array
    {
        $retention = \config('observability.retention');
        $out = [
            'server_occurrences' => 0,
            'client_occurrences' => 0,
            'performance_events' => 0,
            'aged_out_groups'    => 0,
        ];

        $occurrenceDays = max(7, (int) ($retention['occurrence_days'] ?? 90));
        $out['server_occurrences'] = db()->delete(
            'application_errors',
            "source <> 'client' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'i',
            [$occurrenceDays]
        );

        $clientDays = max(7, (int) ($retention['client_days'] ?? 30));
        $out['client_occurrences'] = db()->delete(
            'application_errors',
            "source = 'client' AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            'i',
            [$clientDays]
        );

        $performanceDays = max(7, (int) ($retention['performance_days'] ?? 30));
        $out['performance_events'] = db()->delete(
            'performance_events',
            'created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            'i',
            [$performanceDays]
        );

        $groupMonths = max(1, (int) ($retention['resolved_group_months'] ?? 12));
        $stmt = db()->query(
            "DELETE g FROM error_groups g
             LEFT JOIN application_errors ae ON ae.error_group_id = g.id
             WHERE ae.id IS NULL
               AND g.status IN ('RESOLVED', 'IGNORED')
               AND g.last_seen_at < DATE_SUB(NOW(), INTERVAL ? MONTH)",
            'i',
            [$groupMonths]
        );
        $out['aged_out_groups'] = (int) $stmt->affected_rows;
        $stmt->close();

        return $out;
    }

    /** CLI entry point for backend/cron/error_retention.php (nightly). */
    public static function runRetention(): void
    {
        try {
            $deleted = self::getInstance()->cleanup();
            echo '[error_retention] ' . json_encode($deleted) . PHP_EOL;
        } catch (\Throwable $e) {
            error_log('[error_retention] failed: ' . $e->getMessage());
        }
    }
}

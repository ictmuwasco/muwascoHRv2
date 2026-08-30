<?php

declare(strict_types=1);

/**
 * Observability / Error Tracking configuration.
 *
 * Central tuning point for the error-tracking layer. Nothing here needs to be
 * edited for the feature to work - sensible production defaults are used and
 * every value may be overridden via environment variables.
 */
return [

    // Master switch. When false the tracker becomes a no-op (fail-safe).
    'enabled' => (bool) env('OBSERVABILITY_ENABLED', true),

    // Deployment identity attached to every error / performance event.
    // Set APP_VERSION + GIT_COMMIT in .env during deploys to enable
    // "introduced after deployment" correlations on the dashboard.
    'version'    => env('APP_VERSION', '1.0.0'),
    'git_commit' => env('GIT_COMMIT', null),
    'deployment_id' => env('DEPLOYMENT_ID', null),

    // Correlation id -------------------------------------------------------
    'request_id_header' => 'X-Request-ID',
    // When true, an inbound X-Request-ID header (e.g. produced by the SPA)
    // is adopted instead of generating a fresh id, enabling end-to-end
    // tracing. Values are strictly format-validated before adoption.
    'trust_incoming_request_id' => (bool) env('OBSERVABILITY_TRUST_REQUEST_ID', true),

    // Sensitive data redaction ---------------------------------------------
    'redaction_placeholder' => '[REDACTED]',
    // Field names (case/separator-insensitive substring match) that are never
    // persisted. Extend freely - nested structures are handled recursively.
    'sensitive_fields' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'old_password', 'passcode', 'pin',
        'token', 'access_token', 'refresh_token', 'jwt', 'bearer', 'authorization',
        'secret', 'client_secret', 'api_key', 'apikey', 'apikeysecret',
        'cookie', 'session_id', 'csrf', 'csrf_token', '_token',
        'private_key', 'privatekey', 'signature',
        'card_number', 'cardnumber', 'cvv', 'cvc', 'ssn',
        'vapid', 'p256dh', 'auth_keys', 'authkey',
    ],
    // Headers that are safe enough to store for debugging; everything else is dropped.
    'allowed_headers' => [
        'content-type', 'accept', 'origin', 'referer', 'x-request-id',
        'x-csrftoken', 'accept-language',
    ],
    'max_payload_depth'      => 4,
    'max_payload_items'      => 60,
    'max_string_length'      => 512,
    'max_stored_json_bytes'  => 8192,
    'stack_trace_limit'      => 40,

    // Severity classification ----------------------------------------------
    // Exception class fragments -> severity overrides (evaluated in order).
    'critical_exceptions' => [
        'mysqli_sql_exception', 'PDOException',
        'RedisException', 'RedisClusterException',
    ],
    // Modules considered business critical: system errors there are bumped
    // one severity level (HIGH -> CRITICAL).
    'business_critical_modules' => [
        'Attendance', 'Leave', 'Payroll', 'Employees', 'Authentication',
    ],

    // Expected-error policy -------------------------------------------------
    // 4xx responses are normal application behaviour and are NOT recorded by
    // default (they remain visible through the audit trail). Flip these on to
    // also observe them, categorized EXPECTED/AUTHENTICATION/etc.
    'capture_client_http_errors' => (bool) env('OBSERVABILITY_CAPTURE_4XX', false),

    // Performance thresholds (milliseconds) ---------------------------------
    'performance' => [
        'enabled'     => (bool) env('OBSERVABILITY_PERF_ENABLED', true),
        // Only requests at/above warning_ms are persisted (keeps the table small).
        'warning_ms'  => (int) env('OBSERVABILITY_PERF_WARN_MS', 2000),
        'slow_ms'     => (int) env('OBSERVABILITY_PERF_SLOW_MS', 4000),
        'critical_ms' => (int) env('OBSERVABILITY_PERF_CRITICAL_MS', 8000),
    ],

    // Notifications ----------------------------------------------------------
    // Default channel is the system error log; the dashboard surfaces spikes.
    'notifications' => [
        'notify_severities'      => ['CRITICAL'],   // dashboard-only otherwise
        'cooldown_minutes'       => (int) env('OBSERVABILITY_NOTIFY_COOLDOWN', 60),
        'spike_increase_percent' => 300,            // +300% vs baseline
        'spike_min_hourly'       => 5,              // ignore tiny baselines
    ],

    // Retention (days). Adjust to organizational policy; enforced by
    // backend/cron/error_retention.php which should run nightly.
    'retention' => [
        'occurrence_days'    => (int) env('OBSERVABILITY_RETENTION_OCCURRENCES', 90),
        'performance_days'   => (int) env('OBSERVABILITY_RETENTION_PERFORMANCE', 30),
        'client_days'        => (int) env('OBSERVABILITY_RETENTION_CLIENT', 30),
        'resolved_group_months' => (int) env('OBSERVABILITY_RETENTION_GROUPS_MONTHS', 12),
    ],
];

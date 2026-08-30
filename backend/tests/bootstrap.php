<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment variables
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv(sprintf('%s=%s', $name, $value));
        }
    }
}

// Set test environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mock database connection for testing
class TestDatabase {
    public static function getInstance() {
        static $instance = null;
        if ($instance === null) {
            $instance = new class {
                public function fetchAll($sql, $params = []) {
                    return [];
                }
                public function fetchOne($sql, $params = []) {
                    return null;
                }
                public function insert($sql, $params = []) {
                    return 1;
                }
                public function update($sql, $params = []) {
                    return true;
                }
                public function delete($sql, $params = []) {
                    return true;
                }
            };
        }
        return $instance;
    }
}

// Override db() helper for testing (only if not already defined)
if (!function_exists('db')) {
    function db() {
        return TestDatabase::getInstance();
    }
}

// Mock logger
class TestLogger {
    public function info($message, $context = []) {}
    public function error($message, $context = []) {}
    public function warning($message, $context = []) {}
}

// Override logger() helper for testing (only if not already defined)
if (!function_exists('logger')) {
    function logger() {
        static $logger = null;
        if ($logger === null) {
            $logger = new TestLogger();
        }
        return $logger;
    }
}

// Helper functions for tests (to avoid conflicts with bootstrap.php)
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed {
        static $config = [
            'database.connections.mysql' => [
                'host' => 'localhost',
                'username' => 'root',
                'password' => '',
                'database' => 'muwasco',
                'port' => 3306,
                'charset' => 'utf8mb4'
            ],
            // Observability layer - mirrors backend/config/observability.php
            'observability.enabled'                     => true,
            'observability.version'                     => '1.0.0',
            'observability.git_commit'                  => null,
            'observability.deployment_id'               => null,
            'observability.request_id_header'           => 'X-Request-ID',
            'observability.trust_incoming_request_id'   => true,
            'observability.redaction_placeholder'       => '[REDACTED]',
            'observability.sensitive_fields'            => [
                'password', 'password_confirmation', 'current_password', 'new_password',
                'old_password', 'passcode', 'pin',
                'token', 'access_token', 'refresh_token', 'jwt', 'bearer', 'authorization',
                'secret', 'client_secret', 'api_key', 'apikey', 'apikeysecret',
                'cookie', 'session_id', 'csrf', 'csrf_token', '_token',
                'private_key', 'privatekey', 'signature',
                'card_number', 'cardnumber', 'cvv', 'cvc', 'ssn',
                'vapid', 'p256dh', 'auth_keys', 'authkey',
            ],
            'observability.allowed_headers'             => [
                'content-type', 'accept', 'origin', 'referer', 'x-request-id',
                'x-csrftoken', 'accept-language',
            ],
            'observability.max_payload_depth'           => 4,
            'observability.max_payload_items'           => 60,
            'observability.max_string_length'           => 512,
            'observability.max_stored_json_bytes'       => 8192,
            'observability.stack_trace_limit'           => 40,
            'observability.critical_exceptions'         => [
                'mysqli_sql_exception', 'PDOException',
                'RedisException', 'RedisClusterException',
            ],
            'observability.business_critical_modules'   => [
                'Attendance', 'Leave', 'Payroll', 'Employees', 'Authentication',
            ],
            'observability.capture_client_http_errors'  => false,
            'observability.performance.enabled'         => true,
            'observability.performance.warning_ms'      => 2000,
            'observability.performance.slow_ms'         => 4000,
            'observability.performance.critical_ms'     => 8000,
            'observability.notifications.notify_severities'      => ['CRITICAL'],
            'observability.notifications.cooldown_minutes'       => 60,
            'observability.notifications.spike_increase_percent' => 300,
            'observability.notifications.spike_min_hourly'       => 5,
            'observability.retention.occurrence_days'            => 90,
            'observability.retention.performance_days'           => 30,
            'observability.retention.client_days'                => 30,
            'observability.retention.resolved_group_months'      => 12,
        ];
        
        return $config[$key] ?? $default;
    }
}

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

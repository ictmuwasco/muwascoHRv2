<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Define path constants that production bootstrap.php normally provides.
// Tests exercise production code (e.g. PermissionCatalogTest require's
// BASE_PATH . '/backend/config/permissions.php', SecurityMiddleware uses
// STORAGE_PATH) which assumes these constants exist.
//
// BASE_PATH must be the REPO ROOT (same semantics as backend/bootstrap.php,
// where dirname(__DIR__) from backend/ = repo root). From backend/tests/ the
// repo root is TWO levels up — an off-by-one here silently resolves every
// BASE_PATH.'/backend/...' path to 'backend/backend/...' (CI failure, exit 2).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}
if (!defined('BACKEND_PATH')) {
    define('BACKEND_PATH', BASE_PATH . '/backend');
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BACKEND_PATH . '/storage');
}

// Ensure storage sub-directories exist (CI runs in a fresh checkout where
// these are not created by the repo — RateLimitTest writes to
// STORAGE_PATH/cache/rate-limits).
$storageDirs = [STORAGE_PATH, STORAGE_PATH . '/logs', STORAGE_PATH . '/cache', STORAGE_PATH . '/backups'];
foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

// Load environment variables using vlucas/phpdotenv (same library as production
// bootstrap.php) instead of the fragile hand-rolled parser that didn't handle
// quoted values, inline comments, or values containing '='.
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
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
                /**
                 * Lazily-connected REAL database handle. Engine-backed tests
                 * (RBAC / AuthorizationService) call db()->getConnection()
                 * and MUST hit the configured test database, while the inert
                 * fetchAll/fetchOne/... stubs keep pure-unit tests hermetic.
                 */
                private $realConnection = null;

                public function getConnection() {
                    if ($this->realConnection === null) {
                        try {
                            $this->realConnection = \App\Helpers\Database::getInstance()->getConnection();
                        } catch (\Throwable $e) {
                            $this->realConnection = false;
                        }
                    }
                    if ($this->realConnection === false) {
                        throw new \RuntimeException('Database unavailable: ' . 'connection failed');
                    }
                    return $this->realConnection;
                }

                public function fetchAll(string $sql, string $types = '', array $params = []): array {
                    $conn = $this->getConnection();
                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                    return $rows;
                }
                public function fetchOne(string $sql, string $types = '', array $params = []): ?array {
                    $conn = $this->getConnection();
                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result ? $result->fetch_assoc() : null;
                    $stmt->close();
                    return $row;
                }
                public function insert(string $sql, string $types = '', array $params = []): int {
                    $conn = $this->getConnection();
                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $id = $conn->insert_id;
                    $stmt->close();
                    return $id;
                }
                public function update(string $sql, string $types = '', array $params = []): bool {
                    $conn = $this->getConnection();
                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $stmt->close();
                    return true;
                }
                public function delete(string $sql, string $types = '', array $params = []): bool {
                    $conn = $this->getConnection();
                    $stmt = $conn->prepare($sql);
                    if (!empty($params)) {
                        $stmt->bind_param($types, ...$params);
                    }
                    $stmt->execute();
                    $stmt->close();
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
        static $config = [];

        // First, try to load from config file on disk (matches production behavior)
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (!isset($config[$file])) {
            $configPath = BACKEND_PATH . "/config/{$file}.php";
            if (file_exists($configPath)) {
                $config[$file] = require $configPath;
            }
        }

        // If config file was loaded, traverse segments to find the value
        if (isset($config[$file]) && is_array($config[$file])) {
            $value = $config[$file];
            foreach ($segments as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }
            return $value;
        }

        // Fallback to hardcoded test defaults for keys without config files
        $defaults = [
            'database.connections.mysql' => [
                'host'     => env('DB_HOST', 'localhost'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'database' => env('DB_DATABASE', 'muwasco'),
                'port'     => (int) env('DB_PORT', 3306),
                'charset'  => 'utf8mb4'
            ],
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

        return $defaults[$key] ?? $default;
    }
}

// Timezone
date_default_timezone_set('Africa/Nairobi');

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

<?php

declare(strict_types=1);

// Phase 2 instrumentation: capture the raw bootstrap start so the shutdown
// report can attribute full bootstrap cost. Plain superglobal — the
// PerfTiming class cannot be autoloaded this early.
if (!isset($GLOBALS['_perf_bootstrap_start'])) {
    $GLOBALS['_perf_bootstrap_start'] = microtime(true);
}

ob_start();


define('BASE_PATH', dirname(__DIR__));
define('BACKEND_PATH', BASE_PATH . '/backend');
define('STORAGE_PATH', BACKEND_PATH . '/storage');
define('CONFIG_PATH', BACKEND_PATH . '/config');

// Dynamic base URL for asset paths
// Detect subdirectory from REQUEST_URI since SCRIPT_NAME points to index.php
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseUrl = '';
if (strpos($requestUri, '/hrdemo') === 0) {
    $baseUrl = '/hrdemo';
} elseif (strpos($scriptName, '/hrdemo') !== false) {
    $baseUrl = '/hrdemo';
}
define('BASE_URL', $baseUrl);

$autoloadPath = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("Composer autoloader not found. Run 'composer install' first.\n");
}
// Some vendored libraries emit PHP-version-ism notices while their
// files are included (e.g. thecodingmachine/safe on PHP 8.0). Keep
// that noise out of CLI/test output without hiding app-level errors.
$errorReportingBeforeAutoload = error_reporting();
error_reporting($errorReportingBeforeAutoload & ~E_WARNING & ~E_DEPRECATED);
require_once $autoloadPath;
error_reporting($errorReportingBeforeAutoload);

// Some vendored packages emit compile-time notices while their files are
// included (e.g. thecodingmachine/safe pseudo-type hints on PHP 8.0). When
// display_errors is on, those land in the output buffer opened above and
// would otherwise be flushed BEFORE our JSON bodies - corrupting every API
// response. Discard anything buffered so far; keep the buffer itself open
// for later cleanup paths.
if (ob_get_level() > 0 && ob_get_length() !== false && ob_get_length() > 0) {
    ob_clean();
}


// Load configuration via vlucas/phpdotenv (handles quoted values, comments,
// etc.).
//
// WHERE THE ENV FILE LIVES
//   By default it is <repo>/.env, which is inside the git working tree. That is
//   fine for local development (gitignored) but wrong for production, for two
//   independent reasons:
//
//     1. COMMITTED BY ACCIDENT. The tracked templates .env.example and
//        .env.production.example sit beside it. A real value pasted into one of
//        them lands in git history permanently. That is exactly how production
//        DB, SMTP and AI credentials ended up in a tracked file here.
//     2. SERVED OVER HTTP. If the document root is the repo root (the layout
//        vite.config.js supports), .env is inside the web root. It is denied by
//        the .htaccess extension rules, but that is a second line of defence
//        behind a file that should not be there at all.
//
//   So production sets ENV_FILE (Apache `SetEnv`, Plesk, php-fpm env, or a
//   systemd unit) to a path OUTSIDE both the repo and the document root, e.g.
//   /var/www/private/hrdemo.env. getenv() is read here as well as $_ENV and
//   $_SERVER (see env() below), so all three injection styles work.
//
//   Resolution order - the FIRST readable candidate wins, then loading stops:
//     1. ENV_FILE              - explicit path (Apache `SetEnv`, Plesk, php-fpm
//                                or systemd unit), read from getenv(), $_ENV
//                                and $_SERVER. Used verbatim.
//     2. <repo-parent>/private/app.env   - out-of-webroot file beside the repo
//                                (dirname(BASE_PATH) is NEVER the document root,
//                                so this can never be served over HTTP).
//     3. /var/www/private/hrdemo.env - conventional out-of-webroot default.
//     4. BASE_PATH/.env        - local development fallback (gitignored). On a
//                                production host neither 1-3 exists only when
//                                misconfigured, and .env is rsync-excluded, so
//                                this candidate is a no-op there.
//
//   LOCAL AND PRODUCTION COEXIST: only candidate 4 exists on a dev machine
//   (candidates 2 and 3 are Linux paths outside the checkout, and 1 is unset),
//   and only 1-3 can exist in production. Nothing needs to be edited when
//   moving between the two - the same bootstrap.php serves both.
//
//   The first readable candidate wins. Nothing is required: with no file at all
//   the app still boots and reads whatever the real process environment holds,
//   which is the recommended setup for Plesk-managed hosts.
$envCandidates = array_filter([
    getenv('ENV_FILE') ?: null,
    $_ENV['ENV_FILE'] ?? null,
    $_SERVER['ENV_FILE'] ?? null,
    dirname(BASE_PATH) . '/private/app.env',
    '/var/www/private/hrdemo.env',
    BASE_PATH . '/.env',
]);
foreach ($envCandidates as $envCandidate) {
    if (is_file($envCandidate) && is_readable($envCandidate)) {
        // Pass the filename explicitly rather than letting phpdotenv default to
        // '.env': createImmutable($paths, $names) would otherwise load EVERY
        // readable file in the directory, so pointing it at /var/www/private
        // would pick up unrelated files that happen to sit there.
        //
        // immutable = does not overwrite a variable already present in the real
        // process environment, so an Apache `SetEnv` / php-fpm value wins
        // over the file. VERIFIED: with PROBE_TEST exported to
        // 'realenvvalue' and the file declaring a different value, getenv()
        // and $_SERVER both still return 'realenvvalue' after safeLoad().
        // (A value set with putenv() in the same process is NOT protected -
        // phpdotenv only reads the inherited table - so do not use putenv()
        // as the injection mechanism.)
        //
        // That is what lets the Plesk variables and this file coexist: either
        // one alone is sufficient.
        \Dotenv\Dotenv::createImmutable(
            dirname($envCandidate),
            basename($envCandidate)
        )->safeLoad();
        break;
    }
}

error_reporting(E_ALL);

// Detect API requests early to suppress HTML error output
//
// The detection MUST be subdirectory-aware: this app is deployed under
// /hrdemo/, so a real API request arrives as REQUEST_URI "/hrdemo/api/…"
// and a naive leading-"/api/" check never matches. The same applies to
// the Vite dev proxy. Falling back on the Accept header alone is not
// enough because browser fetch() sends "Accept: */*". An unrecognized
// API request here also means the session write lock is never released
// (see the session_write_close() block below), so concurrent dashboard
// AJAX calls serialize on the session file — the cause of multi-second
// "slow request" spikes that look like DB/controller slowness but are
// pure lock contention.
$isApiRequest = false;
if (isset($_SERVER['REQUEST_URI'])) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $httpAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApiRequest = (strpos($requestUri, '/api/') !== false)
        || (strpos($requestUri, '/api') === 0 && strlen($requestUri) === 4)
        || (strpos($httpAccept, 'application/json') !== false)
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || !empty($_SERVER['HTTP_X_REQUEST_ID']);
}

// Always disable display_errors to prevent HTML in JSON responses
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/error.log');

/**
 * Get an environment variable value.
 * Global function - no namespace
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

// Log errors but never display them
if (env('APP_DEBUG', false)) {
    error_reporting(E_ALL);
} else {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}

// Convert PHP errors to exceptions
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (\Throwable $e) {
    // ---- 0. Malformed JSON is a CLIENT error, not a server fault.
    //
    // App\Helpers\Json::decodeRequest() throws JsonException on a body it
    // cannot parse, and the employee write path throws when next_of_kin /
    // dependants carry invalid JSON. Without this branch those reached the
    // generic handler below and were answered 500, which tells the client the
    // server broke when in fact it sent bad data - and it files a spurious
    // error-monitor event for every one of them.
    //
    // Handled first, before error capture, because a malformed body is not an
    // application fault and should not page anyone.
    if ($e instanceof \JsonException) {
        try {
            \App\Helpers\ApiResponse::error(
                'Malformed JSON in request: ' . $e->getMessage(),
                'INVALID_JSON',
                [],
                400
            );
        } catch (\Throwable) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo '{"success":false,"message":"Malformed JSON in request","error":{"code":"INVALID_JSON"}}';
        }
        return;
    }

    // ---- 1. Centralized capture (fail-safe §28): tracker failures never
    //         prevent the original error from being handled/logged.
    $reference = null;
    try {
        $reference = \App\Services\ErrorTracking\ErrorTrackerService::getInstance()
            ->captureThrowable($e, ['http_status' => 500]);
    } catch (\Throwable $trackerFailure) {
        error_log('[ErrorTracker] handler capture failed: ' . $trackerFailure->getMessage());
    }

    // ---- 2. Technical log (same file as before, now with correlation id) ---
    $requestIdForLog = '-';
    try {
        if (class_exists(\App\Services\ErrorTracking\RequestIdService::class)) {
            $requestIdForLog = \App\Services\ErrorTracking\RequestIdService::current();
        }
    } catch (\Throwable $ignored) {
        // keep '-'
    }

    $logFile = STORAGE_PATH . '/logs/error.log';
    $message = sprintf(
        "[%s] %s: %s in %s:%d\nRequest-ID: %s\nStack trace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $requestIdForLog,
        $e->getTraceAsString()
    );
    file_put_contents($logFile, $message, FILE_APPEND);

    // Clean all output buffers to discard any HTML that was emitted before the error
    while (ob_get_level()) {
        ob_end_clean();
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $httpAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
    // Subdirectory-aware, matching the early detection at the top of this
    // file (see that comment — "/hrdemo/api/…" must also count).
    $isApiRequest = (strpos($requestUri, '/api/') !== false)
        || (strpos($requestUri, '/api') === 0 && strlen($requestUri) === 4)
        || (strpos($httpAccept, 'application/json') !== false)
        || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || !empty($_SERVER['HTTP_X_REQUEST_ID']);

    $requestId = is_array($reference) ? ($reference['request_id'] ?? null) : null;

    if ($isApiRequest) {
        // Standardized envelope (§12): no stack traces / SQL / paths in prod.
        http_response_code(500);
        header('Content-Type: application/json');
        if ($requestId) {
            header('X-Request-ID: ' . $requestId);
        }
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected error occurred.',
            'error'   => [
                'code'       => 'INTERNAL_SERVER_ERROR',
                'request_id' => $requestId,
                'reference'  => is_array($reference) ? ($reference['error_uuid'] ?? null) : null,
                'details'    => env('APP_DEBUG', false) ? $e->getMessage() : null,
            ],
        ]);
    } elseif (env('APP_DEBUG', false)) {
        echo "<pre>";
        echo "Error: " . htmlspecialchars($e->getMessage()) . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "Request: " . htmlspecialchars((string) $requestId) . "\n";
        echo "</pre>";
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        if ($requestId) {
            header('X-Request-ID: ' . $requestId);
        }
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected error occurred.',
            'error'   => [
                'code'       => 'INTERNAL_SERVER_ERROR',
                'request_id' => $requestId,
            ],
        ]);
    }
});

date_default_timezone_set('Africa/Nairobi');

// Guard: CLI/test runners may have emitted output (e.g. third-party
// deprecation notices) before reaching this point - never fatal on it.
if (!headers_sent() && session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

    $samesite = $isSecure ? 'None' : 'Lax';
    session_set_cookie_params([
        'lifetime' => (int) env('SESSION_LIFETIME', 120) * 60,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => $samesite,
    ]);
    @session_start();
}


// Handle CORS preflight OPTIONS requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    // CORS configuration - must be specific origin when using credentials
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [
        'http://localhost:5173',  // Vite dev server
        'http://localhost:3000',  // Alternative dev port
        'http://localhost',       // Production
    ];
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json');
    http_response_code(200);
    exit();
}

// NOTE: The session write lock is NOT released here. Closing it this early
// (before SecurityMiddleware::run() / AuthenticationMiddleware::process())
// would silently discard the gate's session writes — the sliding
// last_activity refresh in enforceSessionTimeout() and the CSRF token seed.
// The release now happens in api.php, immediately AFTER the security gate
// and authentication, for safe-method (GET/HEAD/OPTIONS) requests only.
// See api.php for the full rationale and the write-after-close audit.


/**
 * Get an environment variable value.
 * Global function - no namespace
 */
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

/**
 * Get application configuration.
 */
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        static $config = [];

        $segments = explode('.', $key);
        $file = array_shift($segments);
        $configPath = CONFIG_PATH . "/{$file}.php";

        if (!isset($config[$file])) {
            if (file_exists($configPath)) {
                $config[$file] = require $configPath;
            } else {
                return $default;
            }
        }

        $value = $config[$file];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

/**
 * Dump and die (debug helper).
 */
if (!function_exists('dd')) {
    function dd(mixed ...$vars): void
    {
        foreach ($vars as $var) {
            echo "<pre>";
            var_dump($var);
            echo "</pre>";
        }
        die();
    }
}

/**
 * Get the database connection instance.
 */
if (!function_exists('db')) {
    function db(): \App\Helpers\Database
    {
        static $db = null;
        if ($db === null) {
            $db = \App\Helpers\Database::getInstance();
        }
        return $db;
    }
}

/**
 * Get the logger instance.
 */
if (!function_exists('logger')) {
    function logger(): \App\Helpers\Logger
    {
        static $logger = null;
        if ($logger === null) {
            $logger = new \App\Helpers\Logger();
        }
        return $logger;
    }
}

// ============================================================================
// Dependency Injection Container Initialization
// ============================================================================
// Register all application services and repositories with the DI container.
// This enables automatic dependency resolution throughout the application.
if (!function_exists('container')) {
    /**
     * Get the DI container instance or resolve a binding.
     * 
     * Usage:
     *   $container = container();           // Get container instance
     *   $service = container(AuthService::class); // Resolve a binding
     */
    function container(string $abstract = null): mixed
    {
        $instance = \App\Container\Container::getInstance();
        
        if ($abstract === null) {
            return $instance;
        }
        
        return $instance->get($abstract);
    }
}

// Register service bindings
try {
    $serviceProvider = new \App\Container\ServiceProvider();
    $serviceProvider->register();
} catch (\Throwable $e) {
    error_log('[DI Container] Service registration failed: ' . $e->getMessage());
}

// Load Auth helper for permission functions
require_once BACKEND_PATH . '/app/Helpers/Auth.php';

// ============================================================================
// Exception Handler Registration
// ============================================================================
// Register centralized exception handler for consistent error responses.
\App\Exceptions\ExceptionHandler::register();

// ============================================================================
// Configuration Validation
// ============================================================================
// Validate required configuration values on boot (fail-fast).
try {
    \App\Config\ConfigValidator::validate();
} catch (\Throwable $e) {
    error_log('[Config Validation] ' . $e->getMessage());
    // Don't die here - let the exception handler deal with it when a request comes in
}

// ============================================================================
// STORAGE ENCRYPTION BOOT GATE (PART B) - HARD FAIL
// ============================================================================
// ConfigValidator's own failure handling above only writes to the error log, so
// it cannot enforce a production requirement on its own.
//
// STORAGE_ENCRYPTION_KEY is the exception that must NOT be tolerated at boot.
// Every other missing variable degrades one feature; this one means every
// encrypted file under backend/storage and backend/public/uploads is written
// with a key nobody can reproduce, or - if the failure were allowed to pass -
// is silently written in plaintext while the documentation claims encryption.
// Either outcome is silent data loss, so production refuses to serve traffic.
//
// Non-production is untouched: local and CI have no key by design and simply
// do not exercise the feature.
//
// NOTE this reads APP_ENV directly rather than calling validate() again, so
// the message names the one specific problem instead of re-listing every
// unrelated config warning.
if (strtolower(trim((string) env('APP_ENV', ''))) === 'production') {
    $storageKeyIssues = [];

    $storageKey = trim((string) env('STORAGE_ENCRYPTION_KEY', ''));
    if ($storageKey === '') {
        $storageKeyIssues[] = 'STORAGE_ENCRYPTION_KEY is not set';
    } elseif (strlen($storageKey) < 32) {
        $storageKeyIssues[] = 'STORAGE_ENCRYPTION_KEY is shorter than 32 characters ('
            . strlen($storageKey) . ')';
    }

    // Reject a key that is still the committed example placeholder. The
    // banned values are read from the templates themselves, so editing a
    // template automatically updates this gate.
    foreach (['.env.example', '.env.production.example'] as $template) {
        $path = BASE_PATH . '/' . $template;
        if (!is_readable($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            continue;
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*STORAGE_ENCRYPTION_KEY\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $placeholder = trim(trim((string) preg_replace('/\s+#.*$/', '', $m[1])), "\"'");
            if ($placeholder !== '' && strcasecmp($storageKey, $placeholder) === 0) {
                $storageKeyIssues[] = 'STORAGE_ENCRYPTION_KEY is still the example value "'
                    . $placeholder . '"';
                break 2;
            }
        }
    }

    $jwtSecret = trim((string) env('JWT_SECRET', ''));
    if ($storageKey !== '' && $jwtSecret !== '' && hash_equals($storageKey, $jwtSecret)) {
        $storageKeyIssues[] = 'STORAGE_ENCRYPTION_KEY is identical to JWT_SECRET';
    }

    // A key of the right shape can still be unusable if this build cannot do
    // AES-256-GCM, so check the primitive too.
    if (!\App\Helpers\StorageEncryption::isAvailable()) {
        $storageKeyIssues[] = 'this PHP build has no AES-256-GCM (OpenSSL extension missing '
            . 'or does not advertise the cipher), so encrypted files could never be read back';
    }

    if (!empty($storageKeyIssues)) {
        $message = 'Refusing to start: encrypted file storage is misconfigured. '
            . implode('; ', $storageKeyIssues)
            . '. Generate a key with: php -r "echo bin2hex(random_bytes(32));" '
            . 'and set it in the environment file OUTSIDE the web root. '
            . 'Do NOT disable this check to make the error go away - files written '
            . 'without it are permanently unreadable.';

        error_log('[Storage Encryption] ' . $message);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }

        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Service temporarily unavailable.',
            'error'   => [
                'code'    => 'STORAGE_ENCRYPTION_MISCONFIGURED',
                'details' => env('APP_DEBUG', false) ? $message : null,
            ],
        ]);
        exit;
    }
}

// ============================================================================
// Event Listeners Registration
// ============================================================================
// Register event listeners for decoupled application actions.
// Example: EventDispatcher::listen(UserCreatedEvent::class, [NotificationListener::class, 'handleUserCreated']);

// Process deferred events after response is sent
register_shutdown_function(function () {
    try {
        \App\Events\EventDispatcher::processDeferred();
    } catch (\Throwable $e) {
        error_log('[Event] Deferred processing failed: ' . $e->getMessage());
    }
});

/**
 * Check if user has permission (global helper for backward compatibility).
 */
function hasPermission(string|array $module, string $action = ''): bool
{
    $auth = \App\Helpers\Auth::getInstance();
    
    if (is_array($module)) {
        return $auth->hasAnyPermission($module);
    }
    
    return $auth->hasPermission($module, $action);
}

/**
 * Check if user has any of the given permissions.
 */
function hasAnyPermission(array $permissions): bool
{
    return \App\Helpers\Auth::getInstance()->hasAnyPermission($permissions);
}

/**
 * Check if user has all of the given permissions.
 */
function hasAllPermissions(array $permissions): bool
{
    return \App\Helpers\Auth::getInstance()->hasAllPermissions($permissions);
}

// ===========================================================================
// Observability / Error Tracking bootstrap (Phase 1 foundation).
// Runs AFTER config()/db() helpers exist. Fail-safe by design: any throwable
// raised here is swallowed - monitoring must never break the application.
// ===========================================================================
if (!function_exists('observability_initialize')) {
    function observability_initialize(): void
    {
        // 1) Correlation id for every HTTP/CLI execution (adopts a validated
        //    inbound X-Request-ID so SPA and backend share one trace id).
        try {
            \App\Services\ErrorTracking\RequestIdService::initialize();
        } catch (\Throwable $e) {
            error_log('[Observability] request-id init failed: ' . $e->getMessage());
        }

        // 2) Shutdown hooks: fatals bypass set_exception_handler, and slow
        //    requests are measured here once per execution.
        register_shutdown_function(function () {
            $last = error_get_last();
            if (is_array($last) && in_array((int) $last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                try {
                    \App\Services\ErrorTracking\ErrorTrackerService::getInstance()->captureThrowable(
                        new \ErrorException((string) $last['message'], 0, (int) $last['type'], (string) $last['file'], (int) $last['line']),
                        ['http_status' => 500]
                    );
                } catch (\Throwable $inner) {
                    error_log('[Observability] fatal capture failed: ' . $inner->getMessage());
                }
            }

            try {
                $durationMs = (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000;
                \App\Services\ErrorTracking\ErrorTrackerService::getInstance()->recordPerformance($durationMs, [
                    'endpoint'    => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                    'method'      => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                    'status_code' => http_response_code(),
                    'memory_kb'   => (int) round(memory_get_peak_usage(true) / 1024),
                ]);
            } catch (\Throwable $inner) {
                error_log('[Observability] perf record failed: ' . $inner->getMessage());
            }
        });
    }
}

observability_initialize();

// Phase 2 instrumentation: start a fresh measuring context for this
// execution (HTTP request or CLI job). No-op when disabled.
\App\Helpers\PerfTiming::reset();

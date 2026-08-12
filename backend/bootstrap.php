<?php

declare(strict_types=1);

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
require_once $autoloadPath;

// Load .env via vlucas/phpdotenv (handles quoted values, comments, etc.)
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    \Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}

error_reporting(E_ALL);

// Detect API requests early to suppress HTML error output
$isApiRequest = false;
if (isset($_SERVER['REQUEST_URI'])) {
    $requestUri = $_SERVER['REQUEST_URI'];
    $httpAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApiRequest = (strpos($requestUri, '/api/') === 0)
        || (strpos($httpAccept, 'application/json') !== false);
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
    $logFile = STORAGE_PATH . '/logs/error.log';
    $message = sprintf(
        "[%s] %s: %s in %s:%d\nStack trace:\n%s\n\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    file_put_contents($logFile, $message, FILE_APPEND);

    // Clean all output buffers to discard any HTML that was emitted before the error
    while (ob_get_level()) {
        ob_end_clean();
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $httpAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isApiRequest = (strpos($requestUri, '/api/') === 0)
        || (strpos($httpAccept, 'application/json') !== false);

    if ($isApiRequest) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Internal server error']);
    } elseif (env('APP_DEBUG', false)) {
        echo "<pre>";
        echo "Error: " . htmlspecialchars($e->getMessage()) . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "</pre>";
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
});

date_default_timezone_set('Africa/Nairobi');

if (session_status() === PHP_SESSION_NONE) {
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
    session_start();
}

// Handle CORS preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

// CRITICAL FIX: Release the session write lock for API requests.
// PHP file-based sessions hold an exclusive lock on the session file
// until the script ends or session_write_close() is called.
// The Dashboard fires 4+ concurrent AJAX requests (stats, attendance,
// notifications, analytics). Without this, each request blocks on the
// session lock held by the previous request, causing cascading timeouts.
if ($isApiRequest) {
    session_write_close();
}


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

// Load Auth helper for permission functions
require_once BACKEND_PATH . '/app/Helpers/Auth.php';

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

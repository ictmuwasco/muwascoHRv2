<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Deliberately does NOT require backend/bootstrap.php. That file opens an
 * output buffer, starts the observability subsystem and registers shutdown
 * handlers, none of which belong in a unit test run - the shutdown handler
 * would try to write performance rows to the database after the assertions
 * had already been reported.
 *
 * What the tests do need is the Composer autoloader (for App\ classes) and
 * the dotenv-loaded configuration, because App\Helpers\Database reads its
 * credentials through the config() helper.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found. Run 'composer install' first.\n");
    exit(1);
}
require_once $autoload;

$basePath = dirname(__DIR__, 2);
if (is_file($basePath . '/.env')) {
    \Dotenv\Dotenv::createImmutable($basePath)->safeLoad();
}

// The application bootstrap defines these; the Database helper and the
// config() helper both rely on them existing.
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $basePath);
}
if (!defined('BACKEND_PATH')) {
    define('BACKEND_PATH', $basePath . '/backend');
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', $basePath . '/backend/storage');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', $basePath . '/backend/config');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// config() is defined inside backend/bootstrap.php (line ~276), which this
// file intentionally does not require. It is reproduced here verbatim rather
// than pulling in the whole bootstrap, which would open an output buffer and
// register the observability shutdown handler. Both are harmful in a test
// run: the shutdown handler writes performance rows to the database after
// results have already been reported.
//
// If the application ever changes config() semantics, change it here too -
// this is the one function the schema tests genuinely depend on.
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

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

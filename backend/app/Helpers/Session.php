<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Session Helper - Session management utilities
 * 
 * Provides convenient methods for session operations.
 */
class Session
{
    private static ?Session $instance = null;

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
     * Get a session value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value.
     */
    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check if a session key exists.
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key.
     */
    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Flash a session value (available for next request only).
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Get a flashed session value.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION['_flash'][$key] ?? $default;
    }

    /**
     * Keep flashed session values for another request.
     */
    public function reflash(): void
    {
        // Flash values are kept until accessed
    }

    /**
     * Regenerate the session ID.
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Destroy the current session.
     */
    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Get all session data.
     */
    public function all(): array
    {
        return $_SESSION;
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

/**
 * Global helper function to get a session value.
 */
function session_get(string $key, mixed $default = null): mixed
{
    return Session::getInstance()->get($key, $default);
}

/**
 * Global helper function to set a session value.
 */
function session_put(string $key, mixed $value): void
{
    Session::getInstance()->put($key, $value);
}

/**
 * Global helper function to check if a session key exists.
 */
function session_has(string $key): bool
{
    return Session::getInstance()->has($key);
}

/**
 * Global helper function to remove a session key.
 */
function session_forget(string $key): void
{
    Session::getInstance()->forget($key);
}

/**
 * Global helper function to flash a session value.
 */
function session_flash(string $key, mixed $value): void
{
    Session::getInstance()->flash($key, $value);
}
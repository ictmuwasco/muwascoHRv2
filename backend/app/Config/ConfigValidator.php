<?php

declare(strict_types=1);

namespace App\Config;

use App\Exceptions\RuntimeException;

/**
 * Configuration Validator
 * 
 * Validates required configuration values on application boot.
 * Prevents runtime failures due to missing configuration.
 */
class ConfigValidator
{
    /**
     * Required environment variables - enforced in EVERY environment.
     */
    private static array $requiredEnvVars = [
        'APP_ENV',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'JWT_SECRET',
    ];

    /**
     * Required only when APP_ENV=production.
     *
     * DB_PASSWORD is deliberately NOT in the blanket list above: the local
     * XAMPP `root` account legitimately has an EMPTY password, so demanding
     * it everywhere would break local development on boot. In production an
     * empty/absent DB password is fatal, because backend/config/database.php
     * falls back to '' and would silently connect to whatever no-auth MySQL
     * is reachable - precisely the "insecure default" this class prevents.
     */
    private static array $productionRequiredEnvVars = [
        'DB_PASSWORD',
    ];

    /**
     * Placeholder / insecure defaults that must never reach production.
     * Keyed by variable name; compared case-insensitively.
     */
    private static array $forbiddenValues = [
        'DB_PASSWORD' => ['', 'password', 'root', 'admin', 'secret', '123456', 'changeme'],
        'JWT_SECRET'  => ['', 'secret', 'changeme', 'password'],
    ];

    private static function isProduction(): bool
    {
        return strtolower(trim((string) env('APP_ENV', ''))) === 'production';
    }

    /**
     * Validate all required configuration
     * 
     * @throws \RuntimeException If required config is missing
     */
    public static function validate(): void
    {
        self::validateEnvVars();
        self::validateInsecureDefaults();
        self::validateJwtSecret();
        self::validateDatabaseConfig();
    }

    /**
     * Reject known-insecure placeholder values in production.
     *
     * A missing variable is caught above; this catches a variable that is
     * PRESENT but set to a value that provides no real protection.
     */
    private static function validateInsecureDefaults(): void
    {
        if (!self::isProduction()) {
            return; // local/staging may legitimately use simple or empty values
        }

        // A value that is present but is not a real credential is as bad as a
        // missing one - it silently provides no protection.
        $missing = [];
        $offenders = [];
        foreach (self::$productionRequiredEnvVars as $var) {
            if (env($var) === null || trim((string) env($var, '')) === '') {
                $missing[] = $var;
            }
        }
        foreach (self::$forbiddenValues as $var => $banned) {
            $value = strtolower(trim((string) env($var, '')));
            if (in_array($value, $banned, true)) {
                $offenders[] = $var;
            }
        }

        $errors = [];
        if (!empty($missing)) {
            $errors[] = 'missing required production config: ' . implode(', ', $missing);
        }
        if (!empty($offenders)) {
            $errors[] = 'insecure default value(s): ' . implode(', ', $offenders);
        }
        if (!empty($errors)) {
            throw new \RuntimeException(
                'Production configuration invalid - ' . implode('; ', $errors)
            );
        }
    }

    /**
     * Validate required environment variables are set
     */
    private static function validateEnvVars(): void
    {
        $missing = [];

        foreach (self::$requiredEnvVars as $var) {
            $value = env($var);
            if (empty($value)) {
                $missing[] = $var;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Validate JWT secret is strong enough
     */
    private static function validateJwtSecret(): void
    {
        $secret = env('JWT_SECRET');
        
        if (empty($secret)) {
            return; // Already caught by validateEnvVars
        }

        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be at least 32 characters long for security'
            );
        }

        $weakSecrets = [
            'your-secret-key-here',
            'your-jwt-secret-key',
            'change-me',
            'changeme',
            'secret',
            '1234567890',
        ];

        if (in_array(strtolower($secret), $weakSecrets, true)) {
            throw new \RuntimeException(
                'JWT_SECRET is too weak. Please use a strong, random secret key'
            );
        }
    }

    /**
     * Validate database configuration
     */
    private static function validateDatabaseConfig(): void
    {
        $config = config('database.connections.mysql');
        
        if (empty($config)) {
            throw new \RuntimeException(
                'Database configuration is missing. Please check config/database.php'
            );
        }

        $requiredKeys = ['host', 'database', 'username'];
        $missing = [];

        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing database configuration keys: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Add a required environment variable
     */
    public static function addRequiredEnvVar(string $var): void
    {
        if (!in_array($var, self::$requiredEnvVars, true)) {
            self::$requiredEnvVars[] = $var;
        }
    }

    /**
     * Get list of required environment variables
     */
    public static function getRequiredEnvVars(): array
    {
        return self::$requiredEnvVars;
    }
}

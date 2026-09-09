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
     * Required environment variables
     */
    private static array $requiredEnvVars = [
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'JWT_SECRET',
    ];

    /**
     * Validate all required configuration
     * 
     * @throws \RuntimeException If required config is missing
     */
    public static function validate(): void
    {
        self::validateEnvVars();
        self::validateJwtSecret();
        self::validateDatabaseConfig();
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

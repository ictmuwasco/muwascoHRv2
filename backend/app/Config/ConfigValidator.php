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
        self::validateProductionSecurityPosture();
        self::validateJwtSecret();
        self::validateDatabaseConfig();
        self::validateStorageEncryptionKey();
    }

    /**
     * Validate STORAGE_ENCRYPTION_KEY (PART B: encrypted file storage).
     *
     * WHY THIS IS STRICTER THAN THE OTHER CHECKS
     *   A missing or weak JWT_SECRET degrades one subsystem. A missing
     *   STORAGE_ENCRYPTION_KEY degrades EVERYTHING the moment the feature is
     *   switched on: the application either writes files nobody can ever read
     *   back, or (worse, if the failure were swallowed) it silently falls back
     *   to plaintext while the security documentation claims at-rest
     *   encryption. Losing this key later is irreversible — every encrypted
     *   document under backend/storage and backend/public/uploads becomes
     *   unrecoverable.
     *
     * PRODUCTION ONLY. Local development and CI legitimately have no key; the
     * feature is simply not exercised there.
     *
     * Rejects:
     *   - missing / blank
     *   - shorter than 32 bytes (too little entropy to protect file keys)
     *   - any value that appears verbatim in either committed example file,
     *     which is how a placeholder becomes a real production key by accident
     *   - equality with JWT_SECRET: the two protect different things (session
     *     tokens vs. every stored file) and must not be the same secret, so a
     *     single stolen env file does not yield both.
     */
    private static function validateStorageEncryptionKey(): void
    {
        if (!self::isProduction()) {
            return;
        }

        $errors = [];
        $value = trim((string) env('STORAGE_ENCRYPTION_KEY', ''));

        if ($value === '') {
            $errors[] = 'STORAGE_ENCRYPTION_KEY is required in production '
                . '(generate one with: php -r "echo bin2hex(random_bytes(32));"). '
                . 'Without it every encrypted file is permanently unrecoverable.';
        } elseif (strlen($value) < 32) {
            $errors[] = 'STORAGE_ENCRYPTION_KEY must be at least 32 characters; got '
                . strlen($value) . '.';
        } else {
            // Placeholder values copied straight out of the committed example
            // files. Compared case-insensitively and with surrounding
            // whitespace removed, so "<generate-with-the-command-above>"
            // and "<your-storage-key>" are both caught.
            foreach (self::exampleKeyPlaceholders() as $placeholder) {
                if (strcasecmp($value, $placeholder) === 0) {
                    $errors[] = 'STORAGE_ENCRYPTION_KEY is still an example value '
                        . '("' . $placeholder . '"). Generate a real one.';
                    break;
                }
            }
        }

        $jwt = trim((string) env('JWT_SECRET', ''));
        if ($value !== '' && $jwt !== '' && hash_equals($value, $jwt)) {
            $errors[] = 'STORAGE_ENCRYPTION_KEY must differ from JWT_SECRET; '
                . 'one stolen value must not unlock both session tokens and every stored file.';
        }

        if (!empty($errors)) {
            throw new \RuntimeException(
                'Storage encryption configuration invalid - ' . implode('; ', $errors)
            );
        }
    }

    /**
     * True when the supplied key matches a value present in the committed
     * .env.example / .env.production.example templates.
     *
     * @return bool
     */
    private static function isExampleKey(string $value): bool
    {
        foreach (self::exampleKeyPlaceholders() as $placeholder) {
            if (strcasecmp($value, $placeholder) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Every STORAGE_ENCRYPTION_KEY value that appears in a committed template.
     *
     * Read from disk rather than hardcoded, so a template edit cannot leave a
     * stale banned list behind. Missing templates are treated as "no
     * placeholders known" rather than a failure: the length and JWT-distinct
     * checks still apply.
     *
     * @return string[]
     */
    private static function exampleKeyPlaceholders(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];

        if (!defined('BASE_PATH')) {
            return $cache;
        }

        $templates = [
            BASE_PATH . '/.env.example',
            BASE_PATH . '/.env.production.example',
        ];

        foreach ($templates as $template) {
            if (!is_readable($template)) {
                continue;
            }
            $lines = file($template, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                if (preg_match('/^\s*STORAGE_ENCRYPTION_KEY\s*=\s*(.*)$/', $line, $m) !== 1) {
                    continue;
                }
                $candidate = trim($m[1]);
                // Strip matched surrounding quotes and an inline comment.
                $candidate = trim((string) preg_replace('/\s+#.*$/', '', $candidate));
                $candidate = trim($candidate, "\"'");
                if ($candidate !== '') {
                    $cache[] = $candidate;
                }
            }
        }

        return $cache;
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
     * Enforce the production security posture that the dev template relaxes.
     *
     * .env.example deliberately ships the developer-friendly posture
     * (SESSION_SECURE_COOKIE=false and long token lifetimes) so the project
     * runs on http://localhost with no TLS and no ceremony. Those exact
     * values must never survive into production:
     *
     *  - SESSION_SECURE_COOKIE=false over HTTPS means the session cookie is
     *    also sent in cleartext on any http:// request, so one downgrade
     *    (or an image/link to an http:// URL) leaks it.
     *  - An 8-hour access token / 30-day refresh token is the dev default.
     *    Production is 1 hour / 7 days; a stolen token stays usable far
     *    longer than it needs to be.
     *
     * Both are checked here so a mis-copied .env fails loudly at boot rather
     * than silently shipping a weaker posture than intended.
     */
    private static function validateProductionSecurityPosture(): void
    {
        if (!self::isProduction()) {
            return; // dev/staging legitimately run without TLS
        }

        $errors = [];

        $secureCookie = strtolower(trim((string) env('SESSION_SECURE_COOKIE', '')));
        if ($secureCookie !== 'true') {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production (currently "'
                . ($secureCookie === '' ? 'unset' : $secureCookie) . '")';
        }

        // Maxima, not exact matches: a longer token than the production
        // template is always a weakening, never a hardening.
        $accessMax = 3600;   // 1 hour
        $refreshMax = 604800; // 7 days

        $access = (int) env('JWT_ACCESS_TOKEN_EXPIRY', $accessMax);
        if ($access > $accessMax) {
            $errors[] = "JWT_ACCESS_TOKEN_EXPIRY must be <= {$accessMax}s in production (currently {$access}s)";
        }

        $refresh = (int) env('JWT_REFRESH_TOKEN_EXPIRY', $refreshMax);
        if ($refresh > $refreshMax) {
            $errors[] = "JWT_REFRESH_TOKEN_EXPIRY must be <= {$refreshMax}s in production (currently {$refresh}s)";
        }

        // The application must not run as a MySQL superuser in production.
        // A superuser can read every database on the server, drop tables and
        // create users, so a single application-layer compromise becomes a
        // full server compromise. .env.production.example ships
        // DB_USERNAME=muwascohr for exactly this reason.
        $dbUser = strtolower(trim((string) env('DB_USERNAME', '')));
        if ($dbUser === 'root') {
            $errors[] = 'DB_USERNAME must not be root in production; use a least-privilege account';
        }

        if (!empty($errors)) {
            throw new \RuntimeException(
                'Production security posture invalid - ' . implode('; ', $errors)
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

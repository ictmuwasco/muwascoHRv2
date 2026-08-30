<?php

declare(strict_types=1);

namespace App\Services\ErrorTracking;

/**
 * SeverityClassifier
 *
 * Determines severity + category intelligently from the exception type,
 * HTTP status, HR module and business impact — so callers never have to
 * guess. Rules (first match wins):
 *
 *  1. Database/infrastructure exceptions          -> CRITICAL / DATABASE_ERROR
 *  2. Authentication failures                     -> LOW/HIGH / AUTHENTICATION
 *  3. Authorization failures                      -> LOW     / AUTHORIZATION
 *  4. External service (mail/push/sms) exceptions -> HIGH    / EXTERNAL_SERVICE
 *  5. System error on a business-critical module
 *     (Attendance, Leave, Payroll, Employees)     -> CRITICAL / SYSTEM_ERROR
 *  6. Any other unexpected server error           -> HIGH    / SYSTEM_ERROR
 *  7. Expected 4xx family:
 *       401 AUTHENTICATION · 403 AUTHORIZATION · 404 NOT_FOUND · 422 VALIDATION
 */
final class SeverityClassifier
{
    public const SEVERITY_DEBUG    = 'DEBUG';
    public const SEVERITY_INFO     = 'INFO';
    public const SEVERITY_LOW      = 'LOW';
    public const SEVERITY_MEDIUM   = 'MEDIUM';
    public const SEVERITY_HIGH     = 'HIGH';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    public const CATEGORY_SYSTEM_ERROR     = 'SYSTEM_ERROR';
    public const CATEGORY_DATABASE_ERROR   = 'DATABASE_ERROR';
    public const CATEGORY_AUTHENTICATION   = 'AUTHENTICATION';
    public const CATEGORY_AUTHORIZATION    = 'AUTHORIZATION';
    public const CATEGORY_VALIDATION       = 'VALIDATION';
    public const CATEGORY_NOT_FOUND        = 'NOT_FOUND';
    public const CATEGORY_BUSINESS_RULE    = 'BUSINESS_RULE';
    public const CATEGORY_EXTERNAL_SERVICE = 'EXTERNAL_SERVICE';
    public const CATEGORY_FRONTEND_ERROR   = 'FRONTEND_ERROR';
    public const CATEGORY_PERFORMANCE      = 'PERFORMANCE';
    public const CATEGORY_EXPECTED         = 'EXPECTED';

    /** All valid severities, ordered from most to least severe. */
    public const ORDER = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_HIGH,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_LOW,
        self::SEVERITY_INFO,
        self::SEVERITY_DEBUG,
    ];

    public const VALID_CATEGORIES = [
        self::CATEGORY_SYSTEM_ERROR,
        self::CATEGORY_DATABASE_ERROR,
        self::CATEGORY_AUTHENTICATION,
        self::CATEGORY_AUTHORIZATION,
        self::CATEGORY_VALIDATION,
        self::CATEGORY_NOT_FOUND,
        self::CATEGORY_BUSINESS_RULE,
        self::CATEGORY_EXTERNAL_SERVICE,
        self::CATEGORY_FRONTEND_ERROR,
        self::CATEGORY_PERFORMANCE,
        self::CATEGORY_EXPECTED,
    ];

    private function __construct() {}

    /**
     * @param int|null $httpStatus HTTP status associated with the failure.
     * @param string   $module     HR module name (Attendance, Leave, ...).
     * @return array{severity:string, category:string}
     */
    public static function classify(?string $exceptionClass, ?int $httpStatus, string $module = ''): array
    {
        $class = strtolower((string) $exceptionClass);

        // 1. Infrastructure down -> whole system impacted.
        foreach (self::criticalExceptionFragments() as $fragment) {
            if ($fragment !== '' && str_contains($class, $fragment)) {
                return ['severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_DATABASE_ERROR];
            }
        }

        // 2. Authentication failures (JWT / auth exceptions).
        if ($httpStatus === 401 || str_contains($class, 'jwt')) {
            return [
                'severity' => ($httpStatus !== null && $httpStatus < 500) ? self::SEVERITY_LOW : self::SEVERITY_HIGH,
                'category' => self::CATEGORY_AUTHENTICATION,
            ];
        }

        // 3. Authorization failures.
        if ($httpStatus === 403 || str_contains($class, 'authorization') || str_contains($class, 'forbidden')) {
            return ['severity' => self::SEVERITY_LOW, 'category' => self::CATEGORY_AUTHORIZATION];
        }

        // 4. External service failures (mail / push / sms).
        if (
            preg_match('/(mailer|mail|push|sms|webpush|minishlink|phpmailer)/i', $class)
        ) {
            return ['severity' => self::SEVERITY_HIGH, 'category' => self::CATEGORY_EXTERNAL_SERVICE];
        }

        // 5-6. Unexpected server errors; business-critical modules escalate.
        if ($httpStatus === null || $httpStatus >= 500) {
            $severity = in_array($module, self::businessCriticalModules(), true)
                ? self::SEVERITY_CRITICAL
                : self::SEVERITY_HIGH;

            return ['severity' => $severity, 'category' => self::CATEGORY_SYSTEM_ERROR];
        }

        // 7. Expected 4xx behaviour.
        return match ($httpStatus) {
            400, 422 => ['severity' => self::SEVERITY_LOW,    'category' => self::CATEGORY_VALIDATION],
            401      => ['severity' => self::SEVERITY_LOW,    'category' => self::CATEGORY_AUTHENTICATION],
            403      => ['severity' => self::SEVERITY_LOW,    'category' => self::CATEGORY_AUTHORIZATION],
            404      => ['severity' => self::SEVERITY_INFO,   'category' => self::CATEGORY_NOT_FOUND],
            default  => ['severity' => self::SEVERITY_MEDIUM, 'category' => self::CATEGORY_BUSINESS_RULE],
        };
    }

    /**
     * Upgrade classification when the raw message reveals infrastructure loss
     * (e.g. RuntimeException("Database connection failed")). Returns the
     * stronger of the base classification and the message-based one.
     *
     * @param array{severity:string, category:string} $base
     * @return array{severity:string, category:string}
     */
    public static function classifyMessage(string $message, array $base): array
    {
        $normalized = strtolower($message);
        $patterns = [
            'database connection failed', 'mysql server has gone away',
            'connection refused', 'connection timed out',
            'deadlock found', 'lock wait timeout', 'too many connections',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return ['severity' => self::SEVERITY_CRITICAL, 'category' => self::CATEGORY_DATABASE_ERROR];
            }
        }

        return $base;
    }

    /** True when $a is at least as severe as $b. */
    public static function isAtLeast(string $a, string $b): bool
    {
        $ia = array_search(strtoupper($a), self::ORDER, true);
        $ib = array_search(strtoupper($b), self::ORDER, true);
        if ($ia === false || $ib === false) {
            return false;
        }
        return $ia <= $ib;
    }

    public static function isValidSeverity(string $severity): bool
    {
        return in_array(strtoupper($severity), self::ORDER, true);
    }

    public static function isValidCategory(string $category): bool
    {
        return in_array(strtoupper($category), self::VALID_CATEGORIES, true);
    }

    /** @return array<int,string> */
    private static function criticalExceptionFragments(): array
    {
        $fragments = \config('observability.critical_exceptions');
        return is_array($fragments) ? array_map('strtolower', array_map('strval', $fragments)) : [];
    }

    /** @return array<int,string> */
    private static function businessCriticalModules(): array
    {
        $modules = \config('observability.business_critical_modules');
        return is_array($modules) ? array_map('strval', $modules) : [];
    }

    private function __clone(): void {}
}

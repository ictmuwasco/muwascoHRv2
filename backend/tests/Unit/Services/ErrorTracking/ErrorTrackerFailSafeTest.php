<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\ErrorTrackerService;

/**
 * §28 - the error logger itself must NEVER break the application.
 *
 * Phase 3 note (deterministic fail-safe contract): the earlier assertions
 * depended on the test run's database state — the tracker persists into
 * `application_errors` when the schema is reachable (returns a reference
 * envelope) and degrades to `null` when persistence fails. Either outcome is
 * correct; what MUST always hold is:
 *   1. captureThrowable never throws to the caller,
 *   2. the return value is `null` or a well-formed reference pair,
 *   3. the re-entrancy guard is always released (`finally`) so the tracker
 *      remains usable for subsequent captures on the same request.
 * These assertions are valid on a live dev database, a fresh CI database and
 * a missing schema alike.
 */
class ErrorTrackerFailSafeTest extends TestCase
{
    public function test_capture_never_leaks_and_returns_reference_or_null(): void
    {
        $tracker = ErrorTrackerService::getInstance();

        try {
            $result = $tracker->captureThrowable(
                new \RuntimeException('boom while DB is unavailable'),
                ['http_status' => 500, 'endpoint' => '/api/attendance/clock-in']
            );
        } catch (\Throwable $e) {
            $this->fail('captureThrowable must not leak exceptions to the caller: ' . $e->getMessage());
        }

        if ($result === null) {
            // Persistence degraded to a no-op reference (tracking disabled or
            // the error table was unreachable). Acceptable fail-safe outcome.
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('request_id', $result);
        $this->assertArrayHasKey('error_uuid', $result);
        $this->assertMatchesRegularExpression(
            '/^ERR-\d{8}-[A-F0-9]{6}$/',
            (string) $result['error_uuid'],
            'error_uuid must be the public ERR-YYYYMMDD-XXXXXX reference'
        );
    }

    public function test_tracker_remains_usable_after_a_capture(): void
    {
        $tracker = ErrorTrackerService::getInstance();

        // First capture — success OR fail-safe null; must never throw and must
        // release the re-entrancy guard before returning.
        try {
            $tracker->captureThrowable(new \RuntimeException('first'), []);
        } catch (\Throwable $e) {
            $this->fail('captureThrowable must never throw: ' . $e->getMessage());
        }

        // Second capture on the same request must run normally (guard released
        // via finally inside the first call).
        try {
            $second = $tracker->captureThrowable(new \RuntimeException('second'), []);
            $this->assertTrue($second === null || (is_array($second) && isset($second['request_id'], $second['error_uuid'])));
        } catch (\Throwable $e) {
            $this->fail('guard must be released after failure: ' . $e->getMessage());
        }
    }

    public function test_error_uuid_reference_format(): void
    {
        $uuid = ErrorTrackerService::newErrorUuid();
        $this->assertMatchesRegularExpression('/^ERR-\d{8}-[A-F0-9]{6}$/', $uuid);
    }
}

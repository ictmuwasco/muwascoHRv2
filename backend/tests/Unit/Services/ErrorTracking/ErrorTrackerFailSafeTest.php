<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\ErrorTrackerService;

/**
 * §28 - the error logger itself must NEVER break the application.
 *
 * The test bootstrap's db() mock has no query() method, so counter roll-up
 * blows up mid-persist: captureThrowable must swallow that internally and
 * return null instead of throwing, with the re-entrancy flag released for
 * subsequent captures on the same request.
 */
class ErrorTrackerFailSafeTest extends TestCase
{
    public function test_capture_swallows_internal_persistence_failures(): void
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

        $this->assertNull($result, 'persistence failure should degrade to null reference');
    }

    public function test_tracker_remains_usable_after_an_internal_failure(): void
    {
        $tracker = ErrorTrackerService::getInstance();

        try {
            $tracker->captureThrowable(new \RuntimeException('first'), []);
        } catch (\Throwable) {
            $this->fail('must never throw');
        }

        // Re-entrancy guard released via finally -> second call still runs.
        try {
            $second = $tracker->captureThrowable(new \RuntimeException('second'), []);
            $this->assertNull($second);
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

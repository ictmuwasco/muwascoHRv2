<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\RequestIdService;

/**
 * Correlation id format, trust policy and stable propagation (§4).
 */
class RequestIdServiceTest extends TestCase
{
    /** Reset the cached static id so each test starts fresh. */
    private function reset(): void
    {
        $ref = new \ReflectionProperty(RequestIdService::class, 'requestId');
        \Closure::bind(function () {
            self::$requestId = null;
        }, null, RequestIdService::class)();
    }

    public function test_generated_id_matches_ulid_style_format(): void
    {
        $this->reset();
        $id = RequestIdService::generate();

        $this->assertMatchesRegularExpression('/^req_[0-9A-HJ-NP-TV-Z]{10,32}$/', $id);
        // Two generations never collide.
        $this->assertNotSame($id, RequestIdService::generate());
    }

    public function test_initialize_adopts_trusted_incoming_header(): void
    {
        $this->reset();
        $_SERVER['HTTP_X_REQUEST_ID'] = 'req_ABCDEFGH234567';

        $id = RequestIdService::initialize();
        $this->assertSame('req_ABCDEFGH234567', $id);
        $this->assertSame($id, RequestIdService::current());

        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function test_initialize_rejects_malicious_header_and_generates(): void
    {
        $this->reset();
        $_SERVER['HTTP_X_REQUEST_ID'] = "bad id\r\nX-Evil: 1";

        $id = RequestIdService::initialize();

        $this->assertStringStartsWith('req_', $id);
        $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $id);

        unset($_SERVER['HTTP_X_REQUEST_ID']);
    }

    public function test_current_is_stable_within_a_request_lifecycle(): void
    {
        $this->reset();
        $first = RequestIdService::current();
        // Later layers (audit, errors, response header) must see the SAME id.
        $this->assertSame($first, RequestIdService::current());
    }
}

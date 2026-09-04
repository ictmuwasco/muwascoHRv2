<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use App\Middleware\SecurityMiddleware;

/**
 * Phase 7 regression tests — CSRF Origin/Referer validation for
 * state-changing API requests (finding P7-3).
 *
 * The SPA authenticates with an httpOnly access-token cookie. These tests
 * pin the server-side boundary: a cross-site origin must never pass a
 * POST/PUT/DELETE, while same-origin, CORS-allowlisted origins and
 * non-browser clients (no Origin/Referer header at all) keep working.
 */
class CsrfOriginTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    private ?string $envBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['REQUEST_METHOD', 'HTTP_HOST', 'HTTP_ORIGIN', 'HTTP_REFERER', 'HTTPS', 'SERVER_PORT'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }

        $this->envBackup = isset($_ENV['CSRF_ORIGIN_ENFORCE']) ? (string) $_ENV['CSRF_ORIGIN_ENFORCE'] : null;
        unset($_ENV['CSRF_ORIGIN_ENFORCE'], $_SERVER['CSRF_ORIGIN_ENFORCE']);
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        if ($this->envBackup === null) {
            unset($_ENV['CSRF_ORIGIN_ENFORCE'], $_SERVER['CSRF_ORIGIN_ENFORCE']);
        } else {
            $_ENV['CSRF_ORIGIN_ENFORCE'] = $this->envBackup;
        }

        parent::tearDown();
    }

    private function stateChangingRequest(array $server = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    public function test_safe_methods_pass_even_with_foreign_origin(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_non_browser_request_without_origin_passes(): void
    {
        $this->stateChangingRequest(['HTTP_HOST' => 'localhost']);

        // curl/cron/mobile clients carry no Origin and cannot be CSRFed
        // (a third party cannot attach the victim's cookies to them).
        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_same_origin_request_passes(): void
    {
        $this->stateChangingRequest([
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'http://localhost',
        ]);

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_https_variant_of_same_host_passes(): void
    {
        $this->stateChangingRequest([
            'HTTP_HOST' => 'hr.muwasco.co.ke',
            'HTTPS' => 'on',
            'HTTP_ORIGIN' => 'https://hr.muwasco.co.ke',
        ]);

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_cross_site_origin_is_rejected(): void
    {
        $this->stateChangingRequest([
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);

        $this->assertFalse(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_lookalike_origin_is_rejected(): void
    {
        // hr.muwasco.co.ke.evil.example must NOT satisfy a host match
        // against hr.muwasco.co.ke (exact host:port, never a prefix).
        $this->stateChangingRequest([
            'HTTP_HOST' => 'hr.muwasco.co.ke',
            'HTTP_ORIGIN' => 'https://hr.muwasco.co.ke.evil.example',
        ]);

        $this->assertFalse(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_referer_fallback_allows_same_site(): void
    {
        $this->stateChangingRequest([
            'HTTP_HOST' => 'localhost',
            'HTTP_REFERER' => 'http://localhost/hrdemo/attendance',
        ]);

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_referer_fallback_rejects_foreign_site(): void
    {
        $this->stateChangingRequest([
            'HTTP_HOST' => 'localhost',
            'HTTP_REFERER' => 'https://evil.example/attack.html',
        ]);

        $this->assertFalse(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_cors_allowlisted_origin_passes(): void
    {
        $allowed = (array) config('cors.allowed_origins', []);
        if ($allowed === []) {
            $this->markTestSkipped('No CORS allowlist configured.');
        }

        // The Vite dev server origin is not the API's own host: it must
        // pass via the allowlist, not the self-origin rule.
        $this->stateChangingRequest([
            'HTTP_HOST' => 'api.internal',
            'HTTP_ORIGIN' => (string) $allowed[0],
        ]);

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }

    public function test_enforcement_can_be_disabled_by_config(): void
    {
        $_ENV['CSRF_ORIGIN_ENFORCE'] = 'false';

        $this->stateChangingRequest([
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);

        $this->assertTrue(SecurityMiddleware::passesStateChangeOriginCheck());
    }
}

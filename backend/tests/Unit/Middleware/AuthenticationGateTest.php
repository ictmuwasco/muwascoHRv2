<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use App\Middleware\AuthenticationMiddleware;

/**
 * Phase 7 regression tests — the global authentication gate (P7-4/P7-8).
 *
 * Pins the server-side boundary between PUBLIC and AUTHENTICATED routes.
 * The public allowlist is deliberately tiny: requesting anything else with
 * no session must be detected as unauthenticated (the middleware answers
 * 401 via ApiResponse once it sees this state — see denyUnauthenticated()).
 */
class AuthenticationGateTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    /** @var array<string, string> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionBackup = $_SESSION;
        $_SESSION = [];

        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;

        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function test_no_session_means_unauthenticated(): void
    {
        // Empty session, no Authorization header, no access_token cookie.
        $this->assertFalse(AuthenticationMiddleware::isAuthenticated());
    }

    public function test_session_without_valid_flag_means_unauthenticated(): void
    {
        // A session cookie may exist but never mark a user authenticated
        // unless the login flow set the session_valid flag.
        $_SESSION['user_id'] = 7;
        $this->assertFalse(AuthenticationMiddleware::isAuthenticated());
    }

    public function test_current_route_normalization_matches_the_public_allowlist(): void
    {
        // These exact "METHOD /path" strings are what PUBLIC_ROUTES compares
        // against. If normalization ever drifts (prefix not stripped, method
        // casing changed), the allowlist stops matching and EVERY request
        // would 401 — or, worse, a route could fall out of the allowlist and
        // become public via another route's match.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/hrdemo/api/auth/login';
        $this->assertSame('POST /auth/login', AuthenticationMiddleware::currentRoute());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/hrdemo/api/system/client-errors';
        $this->assertSame('POST /system/client-errors', AuthenticationMiddleware::currentRoute());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/hrdemo/api/consent/status';
        $this->assertSame('GET /consent/status', AuthenticationMiddleware::currentRoute());
    }

    public function test_protected_route_is_never_treated_as_public(): void
    {
        // Directors/employee/leave data endpoints must never match the
        // public allowlist — the middleware gate is what keeps them 401
        // without authentication.
        foreach ([
            'GET /employees',
            'GET /attendance/my-records',
            'GET /leave',
            'GET /meetings',
            'GET /reports/employees',
            'GET /audit',
            'PUT /users/1',
        ] as $route) {
            [$method, $path] = explode(' ', $route, 2);
            $_SERVER['REQUEST_METHOD'] = $method;
            $_SERVER['REQUEST_URI'] = '/api' . $path;
            $this->assertNotSame($route, 'public-gate-miss', "$route must require authentication");
            // currentRoute() must produce the exact method-path pair that the
            // allowlist compares — and that pair is NOT in the public list.
            $this->assertSame($route, AuthenticationMiddleware::currentRoute());
        }
    }
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * Phase 7 regression tests — security policy configuration (P7-3/P7-5/P7-7).
 *
 * Pins the ships-with-the-app policy layer: CSP composition, security
 * headers, CORS posture, web-server protections and the API resource
 * contract. These are source assertions (same convention as
 * PrivilegeEscalationTest) so the controls cannot silently regress.
 */
class SecurityPolicyConfigurationTest extends TestCase
{
    private string $middlewareSource = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->middlewareSource = (string) file_get_contents(
            __DIR__ . '/../../../app/Middleware/SecurityMiddleware.php'
        );
    }

    public function test_csp_does_not_allow_unsafe_inline_scripts(): void
    {
        // script-src must be self + explicit CDN host(s) — never 'unsafe-inline'
        // (inline script = XSS once any injection exists).
        $this->assertStringContainsString("script-src 'self'", $this->middlewareSource);
        $this->assertStringNotContainsString(
            "script-src 'self' 'unsafe-inline'",
            $this->middlewareSource
        );
    }

    public function test_csp_blocks_frame_embedding(): void
    {
        $this->assertStringContainsString("frame-ancestors 'none'", $this->middlewareSource);
    }

    public function test_browser_isolation_and_integrity_headers_are_set(): void
    {
        foreach ([
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy: same-origin',
            'Cross-Origin-Resource-Policy: same-origin',
            'Permissions-Policy:',
            'Content-Security-Policy:',
        ] as $header) {
            $this->assertStringContainsString($header, $this->middlewareSource, "Missing header: $header");
        }
    }

    public function test_cors_configuration_never_uses_wildcard(): void
    {
        $cors = (string) file_get_contents(__DIR__ . '/../../../config/cors.php');

        $this->assertStringNotContainsString("'*'", $cors, 'CORS must never allow all origins');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin: *', $cors);
    }

    public function test_webroot_blocks_sensitive_files_and_listing(): void
    {
        $htaccess = (string) file_get_contents(BASE_PATH . '/.htaccess');

        $this->assertStringContainsString('Options -Indexes', $htaccess);
        $this->assertStringContainsString('\.(env|log|sql|md|lock)$', $htaccess);
        $this->assertStringContainsString('composer\.json', $htaccess);
    }

    public function test_uploads_tree_is_hardened_against_direct_access_and_execution(): void
    {
        $uploadHtaccess = BASE_PATH . '/backend/public/uploads/.htaccess';
        $this->assertFileExists($uploadHtaccess);

        $content = (string) file_get_contents($uploadHtaccess);

        // Deny all direct web access.
        $this->assertStringContainsString('Require all denied', $content);
        // Never execute anything stored there.
        $this->assertStringContainsString('php_flag engine off', $content);
        $this->assertStringContainsString('RemoveHandler', $content);
        $this->assertStringContainsString('RemoveType', $content);
    }

    public function test_api_resources_never_expose_authentication_secrets(): void
    {
        $userResource = (string) file_get_contents(__DIR__ . '/../../../app/Responses/UserResource.php');

        foreach (['password', 'password_hash', 'session_token', 'login_identifier', 'remember_token'] as $secret) {
            // The field must never appear as an output key.
            $this->assertStringNotContainsString(
                "'$secret'",
                $userResource,
                "UserResource must not expose $secret"
            );
        }
    }
}
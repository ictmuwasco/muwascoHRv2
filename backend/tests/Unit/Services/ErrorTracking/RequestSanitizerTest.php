<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\RequestSanitizer;

/**
 * Security-critical: sensitive HR data must NEVER reach the error tables.
 */
class RequestSanitizerTest extends TestCase
{
    public function test_redacts_top_level_sensitive_keys(): void
    {
        $json = RequestSanitizer::sanitizeToJson([
            'email'    => 'employee@example.com',
            'password' => 'super-secret',
            'token'    => 'jwt-value',
        ]);

        $decoded = json_decode((string) $json, true);

        $this->assertSame('employee@example.com', $decoded['email']);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertSame('[REDACTED]', $decoded['token']);
    }

    public function test_redacts_nested_structures_and_variants(): void
    {
        $json = RequestSanitizer::sanitizeToJson([
            'user' => [
                'password_confirmation' => 'x',
                'profile' => ['api_key' => 'k', 'name' => 'Jane'],
            ],
            'AUTHORIZATION' => 'Bearer abc',
            'items' => [['access_token' => 't']],
        ]);

        $d = json_decode((string) $json, true);

        $this->assertSame('[REDACTED]', $d['user']['password_confirmation']);
        $this->assertSame('[REDACTED]', $d['user']['profile']['api_key']);
        $this->assertSame('Jane', $d['user']['profile']['name']);
        $this->assertSame('[REDACTED]', $d['AUTHORIZATION']);
        $this->assertSame('[REDACTED]', $d['items'][0]['access_token']);
    }

    public function test_headers_allow_list_drops_everything_else(): void
    {
        $json = RequestSanitizer::sanitizeHeaders([
            'CONTENT_TYPE'   => 'application/json',
            'HTTP_ACCEPT'    => 'application/json',
            'HTTP_COOKIE'    => 'session=steal-me',
            'HTTP_AUTHORIZATION' => 'Bearer eyJ...',
            'HTTP_X_API_KEY' => 'nope',
        ]);

        $d = json_decode((string) $json, true);

        $this->assertStringNotContainsString('steal-me', (string) $json);
        $this->assertStringNotContainsString('eyJ', (string) $json);
        $this->assertArrayHasKey('accept', array_change_key_case($d ?? [], CASE_LOWER));
    }

    public function test_scrub_secrets_from_text_masks_bearer_tokens(): void
    {
        $text = 'Authorization: Bearer abc.def.ghi failed for user=7 token="xyz123"';
        $scrubbed = RequestSanitizer::scrubSecretsFromText($text);

        $this->assertStringNotContainsString('abc.def.ghi', $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);
    }

    public function test_scrub_secrets_can_skip_clamping_for_traces(): void
    {
        $long = str_repeat('frame; ', 500); // > default 512 clamp
        $kept = RequestSanitizer::scrubSecretsFromText($long, false);

        $this->assertGreaterThan(512, strlen($kept));
    }

    public function test_long_strings_are_clamped(): void
    {
        $out = RequestSanitizer::sanitizeToJson(['bio' => str_repeat('a', 5000)]);
        $this->assertLessThan(600, strlen((string) $out));
    }
}

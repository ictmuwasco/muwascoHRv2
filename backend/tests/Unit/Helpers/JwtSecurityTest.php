<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\TestCase;
use App\Helpers\JWT;
use Firebase\JWT\JWT as FirebaseJWT;

/**
 * Phase 1 JWT security tests.
 *
 * Covers: fail-safe secret configuration, signature verification, algorithm
 * enforcement (alg=none rejection), expiration, issuer validation and
 * access/refresh token type separation.
 */
class JwtSecurityTest extends TestCase
{
    private const SECRET = 'phase1-jwt-test-secret-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        putenv('JWT_SECRET=' . self::SECRET);
        $_ENV['JWT_SECRET'] = self::SECRET;
        $_SERVER['JWT_SECRET'] = self::SECRET;
        JWT::resetInstance();
    }

    protected function tearDown(): void
    {
        JWT::resetInstance();
        parent::tearDown();
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function sign(array $payload, ?string $secret = null): string
    {
        return FirebaseJWT::encode($payload, $secret ?? self::SECRET, 'HS256');
    }

    private function payload(int $expOffset = 3600, string $type = 'access', string $iss = 'muwasco-hr-system'): array
    {
        $now = time();

        return [
            'iss' => $iss,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $expOffset,
            'sub' => 7,
            'email' => 'user@example.com',
            'role' => 'hr',
            'type' => $type,
        ];
    }

    public function test_missing_secret_is_rejected(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET'], $_SERVER['JWT_SECRET']);
        JWT::resetInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');

        JWT::getInstance();
    }

    public function test_known_default_secret_is_rejected(): void
    {
        putenv('JWT_SECRET=your-jwt-secret-key-change-in-production');
        $_ENV['JWT_SECRET'] = 'your-jwt-secret-key-change-in-production';
        $_SERVER['JWT_SECRET'] = 'your-jwt-secret-key-change-in-production';
        JWT::resetInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publicly-known');

        JWT::getInstance();
    }

    public function test_short_secret_is_rejected(): void
    {
        putenv('JWT_SECRET=too-short');
        $_ENV['JWT_SECRET'] = 'too-short';
        $_SERVER['JWT_SECRET'] = 'too-short';
        JWT::resetInstance();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32');

        JWT::getInstance();
    }

    public function test_valid_access_token_roundtrips(): void
    {
        $jwt = JWT::getInstance();

        $token = $jwt->generateAccessToken(['id' => 7, 'email' => 'user@example.com', 'role' => 'hr', 'employee_id' => 3]);
        $claims = $jwt->validateAccessToken($token);

        $this->assertNotNull($claims);
        $this->assertSame(7, (int) $claims->sub);
        $this->assertSame('access', $claims->type);
        $this->assertSame('hr', $claims->role);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $jwt = JWT::getInstance();
        $token = $jwt->generateAccessToken(['id' => 7, 'email' => 'user@example.com', 'role' => 'hr']);

        $parts = explode('.', $token);
        $signature = $parts[2];
        $signature[0] = $signature[0] === 'A' ? 'B' : 'A';
        $parts[2] = $signature;

        $this->assertNull($jwt->validateAccessToken(implode('.', $parts)));
    }

    public function test_token_signed_with_wrong_secret_is_rejected(): void
    {
        $jwt = JWT::getInstance();
        $foreign = $this->sign($this->payload(), 'another-secret-0123456789abcdef0123456789');

        $this->assertNull($jwt->validateAccessToken($foreign));
    }

    public function test_alg_none_token_is_rejected(): void
    {
        $jwt = JWT::getInstance();

        $header = self::b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $body = self::b64url(json_encode($this->payload()));
        $unsigned = $header . '.' . $body . '.';

        $this->assertNull($jwt->validateAccessToken($unsigned));
    }

    public function test_expired_token_is_rejected(): void
    {
        $jwt = JWT::getInstance();
        $expired = $this->sign($this->payload(-120));

        $this->assertNull($jwt->validateToken($expired));
        $this->assertNull($jwt->validateAccessToken($expired));
    }

    public function test_wrong_issuer_is_rejected(): void
    {
        $jwt = JWT::getInstance();
        $forged = $this->sign($this->payload(3600, 'access', 'https://evil.example'));

        $this->assertNull($jwt->validateAccessToken($forged));
    }

    public function test_refresh_token_cannot_authenticate(): void
    {
        $jwt = JWT::getInstance();
        $refresh = $jwt->generateRefreshToken(['id' => 7]);

        $this->assertNull($jwt->validateAccessToken($refresh));

        $claims = $jwt->validateRefreshToken($refresh);
        $this->assertNotNull($claims);
        $this->assertSame('refresh', $claims->type);
        $this->assertNotEmpty($claims->token_id);
    }

    public function test_access_token_cannot_be_used_as_refresh_token(): void
    {
        $jwt = JWT::getInstance();
        $access = $jwt->generateAccessToken(['id' => 7]);

        $this->assertNull($jwt->validateRefreshToken($access));
    }
}
<?php

declare(strict_types=1);

namespace App\Helpers;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * JWT Helper - Token-based authentication using firebase/php-jwt.
 *
 * Handles access tokens, refresh tokens, and secure token management.
 *
 * Security hardening (Phase 1):
 *   - The signing secret MUST come from the JWT_SECRET environment variable.
 *     Missing, weak, or publicly-known default secrets abort authentication
 *     with a secure-configuration error (fail-safe) instead of silently
 *     continuing with a predictable fallback.
 *   - Only HS256 is accepted. The trusted algorithm is enforced server-side;
 *     the attacker-controlled token header can never select a different
 *     algorithm (firebase/php-jwt decodes strictly with the algorithm
 *     embedded in the Key object, so alg=none fails).
 *   - validateToken() verifies the signature, expiration (exp/nbf/iat) and
 *     the issuer claim.
 *   - validateAccessToken() / validateRefreshToken() enforce the `type` claim
 *     so refresh tokens can never authenticate against the API and access
 *     tokens can never be used to mint new tokens.
 */
class JWT
{
    /** Minimum accepted JWT_SECRET length in bytes. */
    public const MIN_SECRET_LENGTH = 32;

    /** Publicly-known placeholder secrets that must never be used. */
    private const FORBIDDEN_SECRETS = [
        'your-secret-key-here',
        'your-jwt-secret-key-change-in-production',
        'your-jwt-secret-key',
        'change-me',
        'changeme',
        'change-me-in-production',
        'please-change-me',
        'secret',
        'jwt_secret',
        'supersecret',
        'my-secret-key',
        'secretkey',
        '1234567890',
        '0123456789abcdef0123456789abcdef',
    ];

    private static ?JWT $instance = null;
    private string $secret;
    private string $issuer;
    private int $accessExpiry;
    private int $refreshExpiry;

    private function __construct()
    {
        $secret = \env('JWT_SECRET');

        if (!is_string($secret) || trim($secret) === '') {
            throw new \RuntimeException(
                'Secure configuration error: JWT_SECRET is not set. The application '
                . 'refuses to operate authentication without a strong, unique secret. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        self::assertSecureSecret($secret);
        $this->secret = $secret;

        $this->issuer        = (string) \env('JWT_ISSUER', 'muwasco-hr-system');
        $this->accessExpiry  = max(60, (int) \env('JWT_ACCESS_TOKEN_EXPIRY', 3600));
        $this->refreshExpiry = max(300, (int) \env('JWT_REFRESH_TOKEN_EXPIRY', 604800));
    }

    /**
     * Reject missing, short, or publicly-known default secrets (fail-safe).
     */
    public static function assertSecureSecret(string $secret): void
    {
        $normalized = trim($secret);

        if (strlen($normalized) < self::MIN_SECRET_LENGTH) {
            throw new \RuntimeException(
                'Secure configuration error: JWT_SECRET must be at least '
                . self::MIN_SECRET_LENGTH . ' characters of random data. '
                . 'Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (in_array(strtolower($normalized), self::FORBIDDEN_SECRETS, true)) {
            throw new \RuntimeException(
                'Secure configuration error: JWT_SECRET is set to a publicly-known '
                . 'default value. Replace it with a unique random secret before '
                . 'authentication can run.'
            );
        }
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @internal Test helper: force the next getInstance() to rebuild from the
     *           current environment. Never call from production code paths.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Generate an access token for a user.
     */
    public function generateAccessToken(array $user): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->accessExpiry,
            'sub' => (int) $user['id'],
            'email' => $user['email'] ?? '',
            'role' => $user['role'] ?? '',
            'employee_id' => $user['employee_id'] ?? null,
            'type' => 'access',
        ];

        return FirebaseJWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Generate a refresh token.
     */
    public function generateRefreshToken(array $user): string
    {
        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->refreshExpiry,
            'sub' => (int) $user['id'],
            'type' => 'refresh',
            'token_id' => bin2hex(random_bytes(16)),
        ];

        return FirebaseJWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Validate and decode a token (signature + expiry + issuer).
     */
    public function validateToken(string $token): ?object
    {
        try {
            // firebase/php-jwt only accepts the algorithm supplied in the Key
            // object, so alg=none or algorithm-confusion tokens fail here.
            $decoded = FirebaseJWT::decode($token, new Key($this->secret, 'HS256'));

            if (!isset($decoded->iss) || $decoded->iss !== $this->issuer) {
                \logger()->warning('JWT issuer mismatch', ['expected' => $this->issuer]);
                return null;
            }

            return $decoded;
        } catch (ExpiredException $e) {
            // Routine expiry - not an error worth logging.
            return null;
        } catch (\Throwable $e) {
            \logger()->error('JWT validation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate a token that MUST be an access token (type=access).
     * Refresh tokens are rejected here - they must never authenticate APIs.
     */
    public function validateAccessToken(string $token): ?object
    {
        $decoded = $this->validateToken($token);

        if ($decoded === null || !isset($decoded->type) || $decoded->type !== 'access' || empty($decoded->sub)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Validate a token that MUST be a refresh token (type=refresh).
     */
    public function validateRefreshToken(string $token): ?object
    {
        $decoded = $this->validateToken($token);

        if ($decoded === null || !isset($decoded->type) || $decoded->type !== 'refresh' || empty($decoded->sub) || empty($decoded->token_id)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Refresh an access token using a refresh token (rotation + revocation).
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $decoded = $this->validateRefreshToken($refreshToken);

        if (!$decoded) {
            return null;
        }

        // Verify the refresh token is still valid in the database
        $db = \db();
        $stored = $db->fetchOne(
            "SELECT * FROM refresh_tokens WHERE token_id = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()",
            'si',
            [$decoded->token_id, $decoded->sub]
        );

        if (!$stored) {
            return null;
        }

        // Get user data
        $user = $db->fetchOne(
            "SELECT id, email, role FROM users WHERE id = ? AND is_active = 1",
            'i',
            [$decoded->sub]
        );

        if (!$user) {
            return null;
        }

        // Generate new tokens
        $newAccessToken = $this->generateAccessToken($user);
        $newRefreshToken = $this->generateRefreshToken($user);

        // Revoke old refresh token
        $db->update(
            'refresh_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'token_id = ?',
            's',
            [$decoded->token_id]
        );

        // Store new refresh token
        $newDecoded = $this->validateToken($newRefreshToken);
        $db->insert('refresh_tokens', [
            'token_id' => $newDecoded->token_id,
            'user_id' => $user['id'],
            'expires_at' => date('Y-m-d H:i:s', $newDecoded->exp),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => $this->accessExpiry,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Revoke all refresh tokens for a user.
     */
    public function revokeAllTokens(int $userId): void
    {
        $db = \db();
        $db->update(
            'refresh_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'user_id = ? AND revoked_at IS NULL',
            'i',
            [$userId]
        );
    }

    /**
     * Get the authenticated user from the current request.
     */
    public function getAuthenticatedUser(): ?array
    {
        $token = $this->extractToken();

        if (!$token) {
            return null;
        }

        $decoded = $this->validateAccessToken($token);

        if (!$decoded) {
            return null;
        }

        $db = \db();
        return $db->fetchOne(
            "SELECT id, email, role, first_name, last_name, designation, is_active
             FROM users WHERE id = ? AND is_active = 1",
            'i',
            [$decoded->sub]
        );
    }

    /**
     * Extract JWT token from the request headers.
     */
    private function extractToken(): ?string
    {
        // Check Authorization header first
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Check cookie as fallback
        if (isset($_COOKIE['access_token'])) {
            return $_COOKIE['access_token'];
        }

        return null;
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
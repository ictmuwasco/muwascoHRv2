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
 */
class JWT
{
    private static ?JWT $instance = null;
    private string $secret;
    private string $issuer;
    private int $accessExpiry;
    private int $refreshExpiry;

    private function __construct()
    {
        $this->secret = \env('JWT_SECRET', 'your-jwt-secret-key-change-in-production');
        $this->issuer = \env('JWT_ISSUER', 'muwasco-hr-system');
        $this->accessExpiry = (int) \env('JWT_ACCESS_TOKEN_EXPIRY', 3600);
        $this->refreshExpiry = (int) \env('JWT_REFRESH_TOKEN_EXPIRY', 604800);
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
            'sub' => $user['id'],
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
            'sub' => $user['id'],
            'type' => 'refresh',
            'token_id' => bin2hex(random_bytes(16)),
        ];

        return FirebaseJWT::encode($payload, $this->secret, 'HS256');
    }

    /**
     * Validate and decode a token.
     */
    public function validateToken(string $token): ?object
    {
        try {
            $decoded = FirebaseJWT::decode($token, new Key($this->secret, 'HS256'));
            return $decoded;
        } catch (ExpiredException $e) {
            return null;
        } catch (\Exception $e) {
            \logger()->error('JWT validation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Refresh an access token using a refresh token.
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $decoded = $this->validateToken($refreshToken);
        
        if (!$decoded || !isset($decoded->type) || $decoded->type !== 'refresh') {
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

        $decoded = $this->validateToken($token);
        
        if (!$decoded || !isset($decoded->type) || $decoded->type !== 'access') {
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
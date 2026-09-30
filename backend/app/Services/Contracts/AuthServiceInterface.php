<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * Auth Service Interface
 *
 * Defines the contract for authentication and authorization business logic.
 */
interface AuthServiceInterface extends ServiceInterface
{
    public function setUserRepository(UserRepositoryInterface $repository): void;
    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void;

    /**
     * Authenticate user with email and password.
     */
    public function login(string $email, string $password, bool $rememberMe = false): array;

    /**
     * Logout user.
     */
    public function logout(int $userId): bool;

    /**
     * Refresh user token.
     *
     * @deprecated Renewal no longer works from a user id, because identifying
     *             the caller requires a still-valid access token - which is
     *             exactly what has expired by the time renewal is needed. Use
     *             {@see self::renewFromRefreshToken()} instead.
     */
    public function refreshToken(int $userId): ?string;

    /**
     * Issue a new access token from a refresh-token cookie value.
     *
     * @return array{token:string,expires_in:int,refresh_expires_in:int}
     * @throws \InvalidArgumentException when the token is expired/revoked
     */
    public function renewFromRefreshToken(string $refreshToken): array;

    /**
     * Revoke every outstanding refresh token for a user.
     */
    public function revokeAllRefreshTokens(int $userId): void;

    /**
     * Validate user credentials.
     */
    public function validateCredentials(string $email, string $password): bool;

    /**
     * Get user by email.
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Update user password.
     */
    public function updatePassword(int $userId, string $newPassword): bool;

    /**
     * Reset user password.
     */
    public function resetPassword(string $email, string $newPassword): bool;

    /**
     * Check if user is active.
     */
    public function isUserActive(int $userId): bool;

    /**
     * Get user permissions.
     */
    public function getUserPermissions(int $userId): array;

    /**
     * Verify token.
     */
    public function verifyToken(string $token): ?array;
}
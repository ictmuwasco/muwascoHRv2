<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\AuthServiceInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Helpers\Hash;
use App\Helpers\Session;

/**
 * Auth Service
 *
 * Contains business logic for authentication and authorization.
 * Handles login, logout, token management, and password operations.
 */
class AuthService implements AuthServiceInterface
{
    private ?UserRepositoryInterface $userRepository = null;
    private ?EmployeeRepositoryInterface $employeeRepository = null;
    private array $dependencies = [];

    /** @var Hash|null Hash helper (declared to avoid PHP 8.2 dynamic-property deprecation) */
    private ?Hash $hash = null;

    /** @var Session|null Session helper (declared to avoid PHP 8.2 dynamic-property deprecation) */
    private ?Session $session = null;

    public function __construct(
        UserRepositoryInterface $userRepository = null,
        Hash $hash = null,
        Session $session = null,
        EmployeeRepositoryInterface $employeeRepository = null
    ) {
        $this->userRepository = $userRepository;
        $this->employeeRepository = $employeeRepository;
        $this->hash = $hash ?? Hash::getInstance();
        $this->session = $session ?? Session::getInstance();
    }

    public function setUserRepository(UserRepositoryInterface $repository): void
    {
        $this->userRepository = $repository;
    }

    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void
    {
        $this->employeeRepository = $repository;
    }

    public function setDependency(string $name, mixed $dependency): void
    {
        $this->dependencies[$name] = $dependency;
    }

    public function getDependency(string $name): mixed
    {
        return $this->dependencies[$name] ?? null;
    }

    public function login(string $email, string $password, bool $rememberMe = false): array
    {
        // Business rule: Validate input
        if (empty($email) || empty($password)) {
            throw new \InvalidArgumentException('Email and password are required');
        }

        // Business rule: Normalize email
        $email = strtolower(trim($email));

        // Business rule: Get user by email
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            // Consistent error: never reveal whether the account exists.
            throw new \InvalidArgumentException('Invalid credentials');
        }

        // Business rule: Validate password BEFORE the account-status check so
        // every failure path is indistinguishable (no user enumeration).
        $passwordValid = $this->hash->verify($password, (string) ($user['password'] ?? ''));

        if (!$passwordValid) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        // Business rule: Check if user is active. The generic message keeps
        // the response identical to a wrong-password failure; the detailed
        // reason is only written to the security log.
        if (!$this->isUserActive($user['id'])) {
            \logger()->warning('auth.login_inactive_account', ['user_id' => (int) $user['id']]);
            throw new \InvalidArgumentException('Invalid credentials');
        }

        // Business rule: Get employee details
        $employee = $this->employeeRepository->findByEmail($email);

        // Include employee_id in user data for JWT token
        if ($employee && isset($employee['id'])) {
            $user['employee_id'] = $employee['id'];
        }

        // Business rule: Check whether the user has already accepted the current consent version.
        // This is included in the login response so the frontend can decide the redirect
        // destination immediately, without a follow-up API call that could race with
        // session cookie propagation.
        $consentAccepted = false;
        try {
            $consentModel = new \App\Models\Consent();
            $consentAccepted = $consentModel->hasAcceptedVersion((int)$user['id'], \App\Models\Consent::CURRENT_VERSION);
        } catch (\Throwable $e) {
            \logger()->warning('Consent check during login failed', ['error' => $e->getMessage()]);
        }

        // Business rule: Generate the token pair (JWT or session). Issuing the
        // refresh token here is what lets the SPA recover later WITHOUT asking
        // the user to sign in again - see renewFromRefreshToken().
        $tokens = $this->issueTokenPair($user);
        $token = $tokens['token'];

        // Business rule: Update last login
        $this->updateLastLogin($user['id']);

        // Security: Prevent session fixation — new session ID after successful auth
        if (headers_sent() === false && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // The access-token cookie is already set by issueTokenPair() above.

        // Business rule: Create session
        $this->session->set('user_id', $user['id']);
        $this->session->set('login_time', time());
        $this->session->set('user_email', $user['email']);
        $this->session->set('user_role', $user['role']);
        $this->session->set('user_name', trim($user['first_name'] . ' ' . $user['last_name']));
        $this->session->set('session_valid', true);
        $this->session->set('last_activity', time());

        if ($employee) {
            $this->session->set('employee_id', $employee['id']);
            $this->session->set('employee_name', trim($employee['first_name'] . ' ' . $employee['last_name']));
        }

        // Business rule: Set remember me cookie if requested
        if ($rememberMe) {
            $this->setRememberMeCookie($user['id']);
        }

        // Return user data and token
        return [
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role'],
                'employee' => $employee,
                'consent_accepted' => $consentAccepted,
            ],
            'token' => $token,
            // Let the SPA schedule a proactive renewal before the access token
            // lapses, instead of waiting for a 401 to discover it.
            'expires_in' => $tokens['expires_in'],
            'refresh_expires_in' => $tokens['refresh_expires_in'],
        ];
    }

    public function logout(int $userId = 0): bool
    {
        // Business rule: Check if user exists (only if userId provided)
        if ($userId > 0) {
            $user = $this->userRepository->findById($userId);
            if (!$user) {
                throw new \InvalidArgumentException('User not found');
            }
        }

        // Business rule: Clear session
        $this->session->destroy();

        // Business rule: Clear remember me cookie
        $this->clearRememberMeCookie();

        // Security: Revoke every outstanding refresh token, so a cookie copied
        // before logout cannot be replayed to resurrect the session.
        $this->revokeAllRefreshTokens((int) $userId);
        $this->clearRefreshTokenCookie();

        // Security: Clear the httpOnly access-token cookie (F-05)
        $this->clearAccessTokenCookie();

        return true;
    }

    public function refreshToken(int $userId): ?string
    {
        // Business rule: Check if user exists
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Check if user is active
        if (!$this->isUserActive($userId)) {
            throw new \InvalidArgumentException('User account is inactive');
        }

        // Business rule: Generate new token
        return $this->generateToken($user);
    }

    public function validateCredentials(string $email, string $password): bool
    {
        // Business rule: Normalize email
        $email = strtolower(trim($email));

        // Business rule: Get user by email
        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            return false;
        }

        // Business rule: Check if user is active
        if (!$this->isUserActive($user['id'])) {
            return false;
        }

        // Business rule: Validate password
        return $this->hash->verify($password, $user['password']);
    }

    public function getUserByEmail(string $email): ?array
    {
        // Business rule: Normalize email
        $email = strtolower(trim($email));

        return $this->userRepository->findByEmail($email);
    }

    public function getUserById(int $userId): ?array
    {
        return $this->userRepository->findById($userId);
    }

    public function updatePassword(int $userId, string $newPassword): bool
    {
        // Business rule: Check if user exists
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Validate password strength
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        // Business rule: Hash password
        $passwordHash = $this->hash->make($newPassword);

        // Business rule: Update password
        $updated = $this->userRepository->updatePassword($userId, $passwordHash);

        // Security: a password change invalidates every refresh token issued
        // for this user, forcing re-authentication on other devices.
        if ($updated) {
            try {
                \App\Helpers\JWT::getInstance()->revokeAllTokens($userId);
            } catch (\Throwable $e) {
                \logger()->warning('Refresh token revocation failed after password change', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    public function resetPassword(string $email, string $newPassword): bool
    {
        // Business rule: Normalize email
        $email = strtolower(trim($email));

        // Business rule: Get user by email
        $user = $this->userRepository->findByEmail($email);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Validate password strength
        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters');
        }

        // Business rule: Hash password
        $passwordHash = $this->hash->make($newPassword);

        // Business rule: Update password
        $updated = $this->userRepository->updatePassword($user['id'], $passwordHash);

        // Security: invalidate outstanding tokens after a reset.
        if ($updated) {
            try {
                \App\Helpers\JWT::getInstance()->revokeAllTokens((int) $user['id']);
            } catch (\Throwable $e) {
                \logger()->warning('Refresh token revocation failed after password reset', ['user_id' => (int) $user['id'], 'error' => $e->getMessage()]);
            }
        }

        return $updated;
    }

    public function isUserActive(int $userId): bool
    {
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return false;
        }

        return $user['is_active'] == 1;
    }

    public function getUserPermissions(int $userId): array
    {
        // Business rule: Get user
        $user = $this->userRepository->findWithEmployee($userId);
        if (!$user) {
            return [];
        }

        // Business rule: Get permissions based on role
        $permissions = [];

        // Admin has all permissions
        if ($user['role'] === 'admin') {
            return ['*'];
        }

        // Role-based permissions
        switch ($user['role']) {
            case 'hr':
                $permissions = [
                    'employees.view',
                    'employees.create',
                    'employees.edit',
                    'employees.delete',
                    'attendance.view',
                    'attendance.manage',
                    'leave.view',
                    'leave.approve',
                    'leave.reject',
                    'reports.view',
                    'users.view',
                ];
                break;

            case 'manager':
                $permissions = [
                    'employees.view',
                    'attendance.view',
                    'leave.view',
                    'leave.approve',
                    'leave.reject',
                    'reports.view',
                ];
                break;

            case 'employee':
                $permissions = [
                    'profile.view',
                    'profile.edit',
                    'attendance.view',
                    'leave.view',
                    'leave.apply',
                ];
                break;
        }

        return $permissions;
    }

    public function verifyToken(string $token): ?array
    {
        // Decode and cryptographically verify the token; only ACCESS tokens
        // are accepted (refresh tokens are rejected).
        $decoded = \App\Helpers\JWT::getInstance()->validateAccessToken($token);

        if ($decoded === null) {
            return null;
        }

        return [
            'sub'         => (int) $decoded->sub,
            'email'       => $decoded->email ?? '',
            'role'        => $decoded->role ?? '',
            'employee_id' => isset($decoded->employee_id) ? (int) $decoded->employee_id : null,
            'iat'         => isset($decoded->iat) ? (int) $decoded->iat : null,
            'exp'         => isset($decoded->exp) ? (int) $decoded->exp : null,
            'type'        => 'access',
        ];
    }

    /**
     * Generate a signed JWT access token for the user.
     */
    private function generateToken(array $user): string
    {
        return \App\Helpers\JWT::getInstance()->generateAccessToken($user);
    }

    /**
     * Issue a fresh access+refresh pair and persist the refresh token.
     *
     * Called on login AND on every renewal. The refresh token is stored
     * HASHED alongside its token_id, so a database leak does not hand an
     * attacker usable renewal credentials.
     *
     * @return array{token:string,expires_in:int,refresh_expires_in:int}
     */
    private function issueTokenPair(array $user): array
    {
        $access = $this->generateToken($user);
        $refresh = \App\Helpers\JWT::getInstance()->generateRefreshToken($user);

        $this->setAccessTokenCookie($access);
        $this->setRefreshTokenCookie($refresh);
        $this->persistRefreshToken((int) $user['id'], $refresh);

        return [
            'token'              => $access,
            'expires_in'         => (int) \env('JWT_ACCESS_TOKEN_EXPIRY', 28800),
            'refresh_expires_in' => (int) \env('JWT_REFRESH_TOKEN_EXPIRY', 2592000),
        ];
    }

    /**
     * Persist a refresh token so it can be revoked and so a stolen cookie can
     * be traced to a single issued credential.
     */
    private function persistRefreshToken(int $userId, string $refreshToken): void
    {
        $payload = $this->decodeRefreshToken($refreshToken);
        if ($payload === null) {
            return;
        }
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $expiresAt = date('Y-m-d H:i:s', time() + (int) \env('JWT_REFRESH_TOKEN_EXPIRY', 2592000));
        // bind_param needs VARIABLES by reference: a cast expression such as
        // (string) $payload->token_id is a temporary and raises
        // "Argument #2 cannot be passed by reference".
        $tokenId = (string) $payload->token_id;
        $stmt = $db->prepare(
            'INSERT INTO refresh_tokens (token_id, user_id, expires_at, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        // Types must match the VALUES, not the columns' apparent nature:
        // expires_at is a DATETIME but must be bound as a string ('s').
        // Declaring it 'i' makes mysqli cast '2026-10-27 08:00:00' to 0, so
        // every refresh token is stored already expired and renewal can
        // never succeed. token_id=s, user_id=i, expires_at=s.
        $stmt->bind_param('sis', $tokenId, $userId, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Decode a refresh token WITHOUT enforcing `exp`.
     *
     * firebase/php-jwt throws on an expired token, but we need the claims to
     * decide WHY it failed. Every caller re-checks expiry against the database
     * record, so an expired token can never actually be redeemed.
     */
    private function decodeRefreshToken(string $token): ?object
    {
        try {
            $decoded = \Firebase\JWT\JWT::decode(
                $token,
                new \Firebase\JWT\Key((string) \env('JWT_SECRET'), 'HS256')
            );
            if (($decoded->type ?? '') !== 'refresh' || !isset($decoded->sub, $decoded->token_id)) {
                return null;
            }
            return $decoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Revoke one issued refresh token.
     */
    private function revokeRefreshTokenId(string $tokenId): void
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE token_id = ? AND revoked_at IS NULL');
        $stmt->bind_param('s', $tokenId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Revoke EVERY refresh token for a user (logout / password change) so a
     * stolen cookie cannot outlive the session that created it.
     */
    public function revokeAllRefreshTokens(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare('UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    /**
     * Renew an access token from the refresh-token cookie.
     *
     * This is what makes an expired access token RECOVERABLE. The previous
     * implementation identified the caller with getAuthUserId(), which needs
     * a still-valid access token or a live PHP session - so once the access
     * token expired, renewal was impossible and the user was signed out. The
     * refresh cookie is independent of the access token, so it survives.
     *
     * @throws \InvalidArgumentException when the refresh token is unusable
     * @return array{token:string,expires_in:int,refresh_expires_in:int}
     */
    public function renewFromRefreshToken(string $refreshToken): array
    {
        $payload = $this->decodeRefreshToken($refreshToken);
        if ($payload === null) {
            throw new \InvalidArgumentException('Session expired. Please sign in again.');
        }

        $userId = (int) $payload->sub;
        $tokenId = (string) $payload->token_id;
        $db = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $db->prepare(
            'SELECT id, expires_at FROM refresh_tokens
             WHERE token_id = ? AND user_id = ? AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->bind_param('si', $tokenId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || strtotime((string) $row['expires_at']) <= time()) {
            if ($row) {
                $this->revokeRefreshTokenId($tokenId);
            }
            throw new \InvalidArgumentException('Session expired. Please sign in again.');
        }

        $user = $this->userRepository->findById($userId);
        if (!$user || !$this->isUserActive($userId)) {
            $this->revokeRefreshTokenId($tokenId);
            throw new \InvalidArgumentException('Session expired. Please sign in again.');
        }

        $employee = $this->employeeRepository->findByEmail((string) $user['email']);
        if ($employee && isset($employee['id'])) {
            $user['employee_id'] = $employee['id'];
        }

        // Rotate: the presented token is single-use, so a stolen cookie is
        // usable at most once and the theft becomes detectable (a second
        // attempt finds the row already revoked).
        $this->revokeRefreshTokenId($tokenId);

        // Restore the session so the request that triggered renewal is also
        // authorised, not just the ones that follow it.
        $this->session->set('user_id', $userId);
        $this->session->set('user_role', $user['role']);
        $this->session->set('user_email', $user['email']);
        $this->session->set('session_valid', true);
        $this->session->set('last_activity', time());

        return $this->issueTokenPair($user);
    }

    /**
     * Set the refresh token as a long-lived httpOnly cookie.
     */
    private function setRefreshTokenCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie('refresh_token', $token, [
            'expires'  => time() + (int) \env('JWT_REFRESH_TOKEN_EXPIRY', 2592000),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->isSecureRequest(),
            'httponly' => true,
            // Lax (not Strict) so the cookie still rides along on the
            // top-level navigation back into the SPA after a hard reload.
            'samesite' => 'Lax',
        ]);
    }

    /** Clear the refresh cookie on logout / failed renewal. */
    private function clearRefreshTokenCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie('refresh_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function isSecureRequest(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    }

    /**
     * Set the access token as an httpOnly, SameSite cookie.
     */
    private function setAccessTokenCookie(string $token): void
    {
        if (headers_sent()) {
            return;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443;

        setcookie(
            'access_token',
            $token,
            [
                'expires'  => time() + (int) \env('JWT_ACCESS_TOKEN_EXPIRY', 3600),
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Clear the access-token cookie on logout.
     */
    private function clearAccessTokenCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie('access_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Update last login timestamp.
     */
    private function updateLastLogin(int $userId): void
    {
        $this->userRepository->update($userId, [
            'last_activity' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Set remember me cookie.
     */
    private function setRememberMeCookie(int $userId): void
    {
        if (headers_sent()) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 60 * 60); // 30 days

        setcookie('remember_me', $token, $expiry, '/', '', false, true);
    }

    /**
     * Clear remember me cookie.
     */
    private function clearRememberMeCookie(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie('remember_me', '', time() - 3600, '/', '', false, true);
    }
}

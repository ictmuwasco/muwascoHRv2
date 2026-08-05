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

        // DEBUG: Log login attempt
        error_log("Login attempt for email: {$email}");
        
        // Business rule: Get user by email
        $user = $this->userRepository->findByEmail($email);
        
        // DEBUG: Log if user was found
        if (!$user) {
            error_log("User not found for email: {$email}");
            throw new \InvalidArgumentException('Invalid credentials');
        }
        
        error_log("User found: " . print_r($user['email'], true));

        // Business rule: Check if user is active
        if (!$this->isUserActive($user['id'])) {
            error_log("User account is inactive: {$email}");
            throw new \InvalidArgumentException('User account is inactive');
        }

        // Business rule: Validate password
        $passwordValid = $this->hash->verify($password, $user['password']);
        error_log("Password verification for {$email}: " . ($passwordValid ? 'SUCCESS' : 'FAILED'));
        
        if (!$passwordValid) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        // Business rule: Get employee details
        $employee = $this->employeeRepository->findByEmail($email);

        // Business rule: Generate token (JWT or session)
        $token = $this->generateToken($user);

        // Business rule: Update last login
        $this->updateLastLogin($user['id']);

        // Business rule: Create session
        $this->session->set('user_id', $user['id']);
        $this->session->set('user_email', $user['email']);
        $this->session->set('user_role', $user['role']);
        $this->session->set('user_name', trim($user['first_name'] . ' ' . $user['last_name']));

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
            ],
            'token' => $token,
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
        return $this->userRepository->updatePassword($userId, $passwordHash);
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
        return $this->userRepository->updatePassword($user['id'], $passwordHash);
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
        // Business rule: Decode and verify JWT token
        // This would use Firebase JWT library
        // For now, return null (not implemented)
        return null;
    }

    /**
     * Generate JWT token for user.
     */
    private function generateToken(array $user): string
    {
        // Business rule: Generate JWT token
        // This would use Firebase JWT library
        // For now, return a simple token
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'exp' => time() + (24 * 60 * 60), // 24 hours
        ];

        return base64_encode(json_encode($payload));
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
        $token = bin2hex(random_bytes(32));
        $expiry = time() + (30 * 24 * 60 * 60); // 30 days

        setcookie('remember_me', $token, $expiry, '/', '', false, true);
        
        // Store token in database (would need a remember_tokens table)
        // For now, just set the cookie
    }

    /**
     * Clear remember me cookie.
     */
    private function clearRememberMeCookie(): void
    {
        setcookie('remember_me', '', time() - 3600, '/', '', false, true);
    }
}
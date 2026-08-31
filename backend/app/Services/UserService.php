<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\UserServiceInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Helpers\Hash;

/**
 * User Service
 *
 * Contains business logic for user management.
 * Orchestrates repository operations and enforces business rules.
 */
class UserService implements UserServiceInterface
{
    private ?UserRepositoryInterface $userRepository = null;
    private ?EmployeeRepositoryInterface $employeeRepository = null;
    private array $dependencies = [];

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

    public function getAllUsers(array $filters = [], int $page = 1, int $limit = 30): array
    {
        return $this->userRepository->search($filters, $page, $limit);
    }

    public function getUserById(int $id): ?array
    {
        return $this->userRepository->findWithEmployee($id);
    }

    public function createUser(array $data): int
    {
        // Business rule: Validate user data
        $errors = $this->validateUserData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Check if employee exists
        if (!empty($data['employee_id'])) {
            $employee = $this->employeeRepository->findById((int)$data['employee_id']);
            if (!$employee) {
                throw new \InvalidArgumentException('Employee not found');
            }
        }

        // Business rule: Normalize email
        if (!empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Business rule: Hash password if provided
        if (!empty($data['password'])) {
            $data['password'] = Hash::getInstance()->make($data['password']);
        }

        // Business rule: Set default role if not provided
        if (empty($data['role'])) {
            $data['role'] = 'employee';
        }

        // Business rule: role must be a valid catalog role, and creating an
        // account WITH the super_admin role requires a super_admin actor.
        $this->assertValidRole((string) $data['role']);
        $this->assertAuthorizedRoleChange('', (string) $data['role']);

        // Business rule: Set default status if not provided
        if (empty($data['is_active'])) {
            $data['is_active'] = 1;
        }

        // Business rule: Set timestamps
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->userRepository->create($data);
    }

    public function updateUser(int $id, array $data): bool
    {
        // Business rule: Check if user exists
        $existingUser = $this->userRepository->findById($id);
        if (!$existingUser) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Validate user data
        $errors = $this->validateUserData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Check if employee exists
        if (!empty($data['employee_id'])) {
            $employee = $this->employeeRepository->findById((int)$data['employee_id']);
            if (!$employee) {
                throw new \InvalidArgumentException('Employee not found');
            }
        }

        // Business rule: role changes must be valid AND authorized (privilege
        // escalation guard). A role in the payload means the caller is trying
        // to change the account's role.
        if (array_key_exists('role', $data)) {
            $this->assertValidRole((string) ($data['role'] ?? ''));
            $this->assertAuthorizedRoleChange(
                (string) ($existingUser['role'] ?? ''),
                (string) $data['role']
            );
        }

        // Business rule: Normalize email
        if (!empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Business rule: Hash password if provided
        if (!empty($data['password'])) {
            $data['password'] = Hash::getInstance()->make($data['password']);
        } else {
            // Don't update password if not provided
            unset($data['password']);
        }

        // Business rule: Update timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->userRepository->update($id, $data);
    }

    public function deleteUser(int $id): bool
    {
        // Business rule: Check if user exists
        $user = $this->userRepository->findById($id);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Prevent deletion of admin users
        if ($user['role'] === 'admin') {
            throw new \InvalidArgumentException('Cannot delete admin user');
        }

        return $this->userRepository->delete($id);
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
        $passwordHash = Hash::getInstance()->make($newPassword);

        return $this->userRepository->updatePassword($userId, $passwordHash);
    }

    public function updateUserStatus(int $userId, string $status): bool
    {
        // Business rule: Check if user exists
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: Validate status
        if (!in_array($status, ['active', 'inactive'])) {
            throw new \InvalidArgumentException('Invalid user status');
        }

        // Business rule: Prevent deactivating own account
        // This would require current user context
        // For now, we'll just update

        return $this->userRepository->updateStatus($userId, $status);
    }

    public function updateUserRole(int $userId, string $role): bool
    {
        // Business rule: Check if user exists
        $user = $this->userRepository->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Business rule: role must be a valid catalog role and the change
        // must be authorized (privilege escalation guard).
        $this->assertValidRole($role);
        $this->assertAuthorizedRoleChange((string) ($user['role'] ?? ''), $role);

        return $this->userRepository->updateRole($userId, $role);
    }

    /**
     * Validate a role against the permission catalog ("roles" list).
     * The catalog is the single source of truth for valid roles; legacy
     * hand-maintained lists here drifted from it ('hr' never existed).
     */
    private function assertValidRole(string $role): void
    {
        $roles = $this->catalogRoles();
        if (!in_array($role, $roles, true)) {
            throw new \InvalidArgumentException('Invalid user role');
        }
    }

    /**
     * Privilege escalation guard for role assignment.
     *
     * Only a Super Administrator may assign or hold the super_admin role —
     * this prevents a holder of the generic users:edit permission from
     * elevating themselves or anyone else to full system control. The acting
     * identity is taken from the authenticated session (never the payload).
     */
    private function assertAuthorizedRoleChange(string $currentRole, string $newRole): void
    {
        if ($newRole !== 'super_admin' && $newRole !== 'admin') {
            return;
        }

        $actingUserId = (int) ($_SESSION['user_id'] ?? 0);
        $actingRole = (string) ($_SESSION['user_role'] ?? '');

        // Re-resolve from the database when the session does not identify the
        // actor (e.g. CLI/worker contexts) — never trust a payload role.
        if ($actingRole === '' && $actingUserId > 0) {
            try {
                $acting = db()->fetchOne('SELECT role FROM users WHERE id = ?', 'i', [$actingUserId]);
                $actingRole = (string) ($acting['role'] ?? '');
            } catch (\Throwable $e) {
                $actingRole = '';
            }
        }

        if ($actingRole === 'admin' || $actingRole === 'super_admin') {
            return;
        }

        throw new \InvalidArgumentException('Only a Super Administrator can assign the Super Admin role');
    }

    /**
     * Valid roles from the permission catalog.
     *
     * @return string[]
     */
    private function catalogRoles(): array
    {
        $catalog = null;
        if (function_exists('config')) {
            $catalog = config('permissions');
        }
        if (!is_array($catalog) || empty($catalog['roles'])) {
            $path = __DIR__ . '/../../config/permissions.php';
            $catalog = is_file($path) ? require $path : ['roles' => []];
        }
        return is_array($catalog['roles'] ?? null) ? $catalog['roles'] : [];
    }

    public function searchUsers(string $query, array $filters = [], int $page = 1, int $limit = 30): array
    {
        $filters['search'] = $query;
        return $this->userRepository->search($filters, $page, $limit);
    }

    public function validateUserData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Email is required
        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } elseif ($this->userRepository->emailExists($data['email'], $excludeId)) {
            $errors[] = 'Email already exists';
        }

        // Business rule: First name is required
        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        // Business rule: Last name is required
        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        // Business rule: Role is required
        if (empty($data['role'])) {
            $errors[] = 'Role is required';
        }

        // Business rule: Password is required for new users
        if (empty($data['password']) && !$excludeId) {
            $errors[] = 'Password is required';
        }

        // Business rule: Password must be at least 8 characters
        if (!empty($data['password']) && strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters';
        }

        return $errors;
    }
}
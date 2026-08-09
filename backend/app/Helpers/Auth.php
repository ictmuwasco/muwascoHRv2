<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Helpers\RBAC;
use App\Helpers\AuthorizationService;

/**
 * Auth Helper - Authentication and authorization utilities
 * 
 * Provides convenient methods for authentication checks and permission verification.
 * Works with both session-based and JWT-based authentication.
 */
class Auth
{
    private static ?Auth $instance = null;
    private ?RBAC $rbac = null;
    private ?AuthorizationService $authService = null;

    private function __construct()
    {
        $this->rbac = RBAC::getInstance();
        $this->authService = AuthorizationService::getInstance();
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
     * Check if a user is currently authenticated (session or JWT).
     */
    public function check(): bool
    {
        // First check PHP session
        if (isset($_SESSION['user_id']) 
            && isset($_SESSION['session_valid']) 
            && $_SESSION['session_valid'] === true) {
            return true;
        }

        // If session is not valid, try to authenticate via JWT token
        return $this->authenticateFromToken();
    }

    /**
     * Try to authenticate the user from a JWT token in the Authorization header.
     * This allows the frontend to maintain authentication across page reloads
     * even when the PHP session has expired.
     */
    private function authenticateFromToken(): bool
    {
        // Check both HTTP_AUTHORIZATION and REDIRECT_HTTP_AUTHORIZATION
        // (Apache sometimes uses the REDIRECT_ prefix when rewriting)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] 
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
            ?? '';
        $token = '';

        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
        }

        // Fallback: read the httpOnly access_token cookie (F-05)
        if ($token === '' && isset($_COOKIE['access_token'])) {
            $token = $_COOKIE['access_token'];
        }

        if ($token === '') {
            return false;
        }

        try {
            // Verify the JWT signature and expiry (firebase/php-jwt, HS256)
            $decoded = \App\Helpers\JWT::getInstance()->validateToken($token);
            if ($decoded === null) {
                return false;
            }

            // Must be an access token (refresh tokens must not authenticate)
            if (!isset($decoded->type) || $decoded->type !== 'access') {
                return false;
            }

            if (empty($decoded->sub)) {
                return false;
            }

            // Restore session from the verified token
            $_SESSION['user_id'] = (int)$decoded->sub;
            $_SESSION['user_email'] = $decoded->email ?? '';
            $_SESSION['user_role'] = $decoded->role ?? '';
            $_SESSION['session_valid'] = true;
            $_SESSION['last_activity'] = time();

            // Clear authorization cache since the session was just restored
            $this->authService->clearCache();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }

        return [
            'id'          => $_SESSION['user_id'] ?? 0,
            'email'       => $_SESSION['user_email'] ?? '',
            'name'        => $_SESSION['user_name'] ?? '',
            'role'        => $_SESSION['user_role'] ?? '',
            'designation' => $_SESSION['designation'] ?? '',
            'employee_id' => $_SESSION['employee_id'] ?? null,
        ];
    }

    /**
     * Get the authenticated user's ID.
     */
    public function id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    /**
     * Get the authenticated user's role.
     */
    public function role(): string
    {
        return $_SESSION['user_role'] ?? '';
    }

    /**
     * Check if the current user has a specific permission.
     * Uses hybrid authorization (RBAC + user overrides).
     * 
     * @param string $module The module name (e.g., 'employees', 'attendance')
     * @param string $action The action (e.g., 'view', 'create', 'edit', 'delete')
     * @return bool
     */
    public function hasPermission(string $module, string $action): bool
    {
        if (!$this->check()) {
            return false;
        }

        // For page-level access (view action), use hybrid authorization
        if ($action === 'view') {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            return $this->authService->hasPageAccess($userId, $module);
        }

        // For other actions, fall back to RBAC
        $role = $_SESSION['user_role'] ?? '';
        return $this->rbac->hasPermission($role, $module, $action);
    }

    /**
     * Check if the current user has any of the given permissions.
     * 
     * @param array $permissions Array of 'module:action' strings or ['module' => 'action'] pairs
     * @return bool
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && str_contains($permission, ':')) {
                [$module, $action] = explode(':', $permission, 2);
                if ($this->hasPermission($module, $action)) {
                    return true;
                }
            } elseif (is_array($permission)) {
                foreach ($permission as $module => $action) {
                    if ($this->hasPermission($module, $action)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check if the current user has all of the given permissions.
     * 
     * @param array $permissions Array of 'module:action' strings or ['module' => 'action'] pairs
     * @return bool
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (is_string($permission) && str_contains($permission, ':')) {
                [$module, $action] = explode(':', $permission, 2);
                if (!$this->hasPermission($module, $action)) {
                    return false;
                }
            } elseif (is_array($permission)) {
                foreach ($permission as $module => $action) {
                    if (!$this->hasPermission($module, $action)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * Verify a user's password.
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Hash a password.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * Check if the current user is a super admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->check() && $_SESSION['user_role'] === 'super_admin';
    }

    /**
     * Check if the current user is an HR manager.
     */
    public function isHRManager(): bool
    {
        return $this->check() && $_SESSION['user_role'] === 'hr_manager';
    }

    /**
     * Check if the current user is a department head.
     */
    public function isDeptHead(): bool
    {
        return $this->check() && $_SESSION['user_role'] === 'dept_head';
    }

    /**
     * Check if the current user is a manager (any level).
     */
    public function isManager(): bool
    {
        if (!$this->check()) {
            return false;
        }

        $managerRoles = ['super_admin', 'hr_manager', 'dept_head', 'manager', 'section_head', 'sub_section_head'];
        return in_array($_SESSION['user_role'], $managerRoles, true);
    }

    /**
     * Get all permissions for the current user.
     * Returns role permissions (for backward compatibility).
     */
    public function getPermissions(): array
    {
        if (!$this->check()) {
            return [];
        }

        $role = $_SESSION['user_role'] ?? '';
        return $this->rbac->getRolePermissions($role);
    }

    /**
     * Get all effective page permissions for the current user.
     * Includes both role permissions and user-specific overrides.
     * 
     * @return array Array of ['page_id', 'page_name', 'allowed', 'source']
     */
    public function getEffectivePagePermissions(): array
    {
        if (!$this->check()) {
            return [];
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        return $this->authService->getAllEffectivePermissions($userId);
    }

    /**
     * Check if the current user has page access (hybrid authorization).
     * 
     * @param string $pageId Page identifier
     * @return bool
     */
    public function hasPageAccess(string $pageId): bool
    {
        if (!$this->check()) {
            return false;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        return $this->authService->hasPageAccess($userId, $pageId);
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

/**
 * Global helper function to check if user is authenticated.
 */
function auth(): ?Auth
{
    return Auth::getInstance();
}

/**
 * Global helper function to check if user is logged in.
 */
function isLoggedIn(): bool
{
    return Auth::getInstance()->check();
}

/**
 * Global helper function to get the authenticated user.
 */
function currentUser(): ?array
{
    return Auth::getInstance()->user();
}

/**
 * Global helper function to check permissions.
 * Usage: hasPermission('employees', 'view') or hasPermission(['employees:view', 'attendance:view'])
 */
function hasPermission(string|array $module, string $action = ''): bool
{
    $auth = Auth::getInstance();
    
    if (is_array($module)) {
        return $auth->hasAnyPermission($module);
    }
    
    return $auth->hasPermission($module, $action);
}

/**
 * Global helper function to check if user has any of the given permissions.
 */
function hasAnyPermission(array $permissions): bool
{
    return Auth::getInstance()->hasAnyPermission($permissions);
}

/**
 * Global helper function to check if user has all of the given permissions.
 */
function hasAllPermissions(array $permissions): bool
{
    return Auth::getInstance()->hasAllPermissions($permissions);
}
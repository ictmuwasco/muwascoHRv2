<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

/**
 * RBAC - Role-Based Access Control helper.
 *
 * Reads role permissions from the `role_permissions` table populated by
 * migration 004_role_permissions.sql. Provides singleton access and a small
 * in-process cache so permission checks don't hit the DB on every request.
 *
 * Two call patterns are supported to match existing call-sites:
 *   - $rbac->hasPermission($role, $module, $action)   // explicit role
 *   - $rbac->currentUserCan($module, $action)         // uses $_SESSION
 *   - $rbac->requirePermission($module, $action)      // guard using $_SESSION
 *   - $rbac->getRolePermissions($role)                // full role set
 */
class RBAC
{
    private static ?RBAC $instance = null;

    /** @var array<string, array<string, bool>> role -> module.action -> granted */
    private array $cache = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check whether the given role has permission for module.action.
     */
    public function hasPermission(string $role, string $module, string $action): bool
    {
        $role = $this->normalizeRole($role);
        $module = $this->normalizeModule($module);
        $action = $this->normalizeAction($action);

        // super_admin always has full access regardless of table contents.
        if ($role === 'super_admin') {
            return true;
        }

        $granted = $this->lookup($role, $module, $action);
        if ($granted !== null) {
            return $granted;
        }

        // Fallback: if no row exists for this role/module/action combination,
        // treat as denied. (The role_permissions table is the source of truth.)
        return false;
    }

    /**
     * Check the currently authenticated session user's permission.
     * Reads the role from $_SESSION populated by AuthService::login().
     */
    public function currentUserCan(string $module, string $action): bool
    {
        $role = $_SESSION['user_role'] ?? '';
        if ($role === '') {
            return false;
        }
        return $this->hasPermission($role, $module, $action);
    }

    /**
     * Guard variant of currentUserCan().
     * Throws an exception when access is denied so the caller's exception
     * handler can produce the appropriate 403 response.
     */
    public function requirePermission(string $module, string $action): void
    {
        if (!$this->currentUserCan($module, $action)) {
            throw new \RuntimeException("Access denied: {$module}:{$action}");
        }
    }

    /**
     * Return the full permission map for a role.
     *
     * @return array<int, array{module:string, action:string, is_granted:bool}>
     */
    public function getRolePermissions(string $role): array
    {
        $role = $this->normalizeRole($role);

        $conn = db()->getConnection();
        $stmt = $conn->prepare(
            'SELECT module, action, is_granted FROM role_permissions WHERE role = ?'
        );
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(\MYSQLI_ASSOC);
        $stmt->close();

        $perms = [];
        foreach ($rows as $row) {
            $perms[] = [
                'module'     => $row['module'],
                'action'     => $row['action'],
                'is_granted' => (int) $row['is_granted'] === 1,
            ];
        }
        return $perms;
    }

    /**
     * Look up a single (role, module.action) tuple, returning the grant value
     * (true/false) if a row exists, or null when no row is present.
     */
    private function lookup(string $role, string $module, string $action): ?bool
    {
        $key = "{$role}|{$module}.{$action}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        try {
            $conn = db()->getConnection();

            // Check if role_permissions table exists
            $tableResult = $conn->query("SHOW TABLES LIKE 'role_permissions'");
            if ($tableResult->num_rows === 0) {
                // Table doesn't exist — deny by default (never allow-all)
                $this->cache[$key] = false;
                return false;
            }
            $tableResult->free();

            $stmt = $conn->prepare(
                'SELECT is_granted FROM role_permissions
                 WHERE role = ? AND module = ? AND action = ?
                 LIMIT 1'
            );
            $stmt->bind_param('sss', $role, $module, $action);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            $value = $row === null ? null : ((int) $row['is_granted'] === 1);
            $this->cache[$key] = $value;
            return $value;
        } catch (\Throwable $e) {
            // Query failed — deny by default (never allow-all)
            $this->cache[$key] = false;
            return false;
        }
    }

    /**
     * Normalize role identifiers: callers pass 'admin' or 'super_admin' but
     * the users.role enum only contains the latter, so map legacy aliases.
     */
    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if ($role === 'admin') {
            return 'super_admin';
        }
        return $role;
    }

    private function normalizeModule(string $module): string
    {
        return strtolower(trim($module));
    }

    private function normalizeAction(string $action): string
    {
        return strtolower(trim($action));
    }

    private function __clone(): void {}

    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;

use App\Services\PermissionService;
use App\Services\AuditService;

/**
 * PermissionController
 *
 * REST API for the User Permission Management dashboard.
 * Provides endpoints to view the permission catalog, manage user overrides,
 * and retrieve effective permissions.
 *
 * Authorization:
 *   All endpoints require the `permission_overrides:view` permission.
 *   Write operations (set/remove override) require `permission_overrides:manage`.
 *
 * Endpoints:
 *   GET    /api/permissions/catalog                 - Permission catalog (modules/actions/roles)
 *   GET    /api/permissions/statistics              - Summary statistics
 *   GET    /api/permissions/users                   - Paginated user list for selection
 *   GET    /api/permissions/users/{userId}          - User + role permissions + overrides + effective
 *   GET    /api/permissions/overrides               - All overrides with user info (filterable)
 *   POST   /api/permissions/users/{userId}/overrides - Set/update a permission override
 *   DELETE /api/permissions/users/{userId}/overrides - Remove an override
 *         body: { module, action }
 */
class PermissionController extends BaseController
{
    private PermissionService $permissionService;

    public function __construct()
    {
        $this->permissionService = PermissionService::getInstance();
    }

    /**
     * GET /api/permissions/catalog
     */
    public function catalog(): void
    {
        $this->requirePermission('permission_overrides', 'view');

        $this->success([
            'modules' => $this->permissionService->getPermissionCatalog(),
            'roles'   => $this->permissionService->getRoles(),
        ], 'Permission catalog retrieved successfully');
    }

    /**
     * GET /api/permissions/statistics
     */
    public function statistics(): void
    {
        $this->requirePermission('permission_overrides', 'view');

        $this->success($this->permissionService->getStatistics(), 'Permission statistics retrieved successfully');
    }

    /**
     * GET /api/permissions/users
     */
    public function users(): void
    {
        $this->requirePermission('permission_overrides', 'view');

        $search = $this->getSearchQuery();
        [$page, $perPage] = $this->getPaginationParams();

        $result = $this->permissionService->getUsers($search, $page, $perPage);
        $this->success($result, 'Users retrieved successfully');
    }

    /**
     * GET /api/permissions/users/{userId}
     */
    public function userPermissions(int $userId): void
    {
        $this->requirePermission('permission_overrides', 'view');

        // Get user info + role permissions
        $result = $this->permissionService->getUserRolePermissions($userId);

        if ($result['user'] === null) {
            $this->notFound('User not found');
        }

        // Get user overrides
        $overrides = $this->permissionService->getUserOverrides($userId);

        // Get effective permissions
        $effective = $this->permissionService->getEffectivePermissions($userId);

        $this->success([
            'user'             => $result['user'],
            'role'             => $result['user']['role'],
            // Server-resolved organisational scope of the TARGET user so the
            // permission management UI can show why a user is unit-restricted.
            // Resolved from the DB — never from client input.
            'org_scope'        => \App\Helpers\OrgScope::forUser($userId),
            'role_permissions' => $result['role_permissions'],
            'overrides'        => $overrides,
            'effective'        => $effective,
        ], 'User permissions retrieved successfully');
    }

    /**
     * GET /api/permissions/overrides
     */
    public function overrides(): void
    {
        $this->requirePermission('permission_overrides', 'view');

        $filters = [];
        foreach (['user_id', 'module', 'action', 'permission_type'] as $param) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $filters[$param] = $_GET[$param];
            }
        }

        $result = $this->permissionService->getAllOverrides($filters);
        $this->success($result, 'Permission overrides retrieved successfully');
    }

    /**
     * POST /api/permissions/users/{userId}/overrides
     */
    public function setOverride(int $userId): void
    {
        $this->requirePermission('permission_overrides', 'manage');

        $body = $this->getJsonBody();
        $missing = $this->validateRequired($body, ['module', 'action', 'permission_type']);

        if ($missing) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 400);
        }

        $grantedBy = $this->getUserId();
        $notes = $body['notes'] ?? null;

        $result = $this->permissionService->setOverride(
            $userId,
            (string) $body['module'],
            (string) $body['action'],
            (string) $body['permission_type'],
            $grantedBy,
            $notes
        );

        if (!$result['success']) {
            $this->error($result['message'], 400);
        }

        $this->success(null, $result['message']);
    }

    /**
     * DELETE /api/permissions/users/{userId}/overrides
     * Body: { module, action }
     */
    public function removeOverride(int $userId): void
    {
        $this->requirePermission('permission_overrides', 'manage');

        $body = $this->getJsonBody();
        $missing = $this->validateRequired($body, ['module', 'action']);

        if ($missing) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 400);
        }

        $updatedBy = $this->getUserId();

        $result = $this->permissionService->removeOverride(
            $userId,
            (string) $body['module'],
            (string) $body['action'],
            $updatedBy
        );

        if (!$result['success']) {
            $this->error($result['message'], 400);
        }

        $this->success(null, $result['message']);
    }

    /**
     * GET /api/permissions/roles
     */
    public function roles(): void
    {
        $this->requirePermission('permission_overrides', 'view');

        $this->success([
            'roles' => $this->permissionService->getRoles(),
        ], 'Roles retrieved successfully');
    }
}


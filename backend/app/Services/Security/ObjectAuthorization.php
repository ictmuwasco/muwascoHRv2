<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Helpers\AuthorizationService;

/**
 * ObjectAuthorization — reusable trait for object-level authorization.
 *
 * Provides standardized methods for enforcing ownership-based or
 * role-based access to specific resources. Used across controllers
 * to prevent IDOR/BOLA vulnerabilities.
 *
 * SECURITY MODEL: Every method logs a security event on denial before
 * returning false. The controller must then return a 403 response.
 */
trait ObjectAuthorization
{
    /**
     * Require that the user owns the resource OR has one of the allowed roles.
     *
     * @param array  $resource The resource data (must include user_id or owner_id)
     * @param string $ownerField The field name containing the owner's user ID
     * @param array $allowedRoles Roles that bypass ownership check
     * @param string $resourceType For security logging
     * @param int|string $resourceId For security logging
     * @return bool
     */
    protected function requireOwnershipOrRole(
        array $resource,
        string $ownerField = 'user_id',
        array $allowedRoles = ['super_admin', 'hr_manager'],
        string $resourceType = 'resource',
        int|string $resourceId = 0
    ): bool {
        $userId = $this->getAuthUserId();
        if ($userId <= 0) return false;

        // Check if user has an allowed role
        if ($this->userHasRole($allowedRoles)) return true;

        // Check ownership
        if (isset($resource[$ownerField]) && (int) $resource[$ownerField] === $userId) {
            return true;
        }

        // Deny — log security event
        SecurityEventService::getInstance()->record(
            SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS,
            SecurityEventService::SEVERITY_HIGH,
            65,
            [
                'user_id' => $userId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'response_status' => 403,
                'action_taken' => SecurityEventService::ACTION_DENIED,
                'description' => "Unauthorized access attempt to {$resourceType}#{$resourceId}",
            ]
        );

        return false;
    }

    /**
     * Require a specific permission AND ownership of the resource.
     */
    protected function requirePermissionAndOwnership(
        string $permission,
        array $resource,
        string $ownerField = 'user_id',
        string $resourceType = 'resource',
        int|string $resourceId = 0
    ): bool {
        $userId = $this->getAuthUserId();
        if ($userId <= 0) return false;

        // Check permission first
        if (!AuthorizationService::getInstance()->hasPermission($userId, ...explode(':', $permission))) {
            return false;
        }

        // Check ownership
        if (isset($resource[$ownerField]) && (int) $resource[$ownerField] === $userId) {
            return true;
        }

        // Deny — log security event
        SecurityEventService::getInstance()->record(
            SecurityEventService::IDOR_ATTEMPT,
            SecurityEventService::SEVERITY_HIGH,
            70,
            [
                'user_id' => $userId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'response_status' => 403,
                'action_taken' => SecurityEventService::ACTION_DENIED,
                'description' => "IDOR attempt: user {$userId} tried to access {$resourceType}#{$resourceId}",
            ]
        );

        return false;
    }

    /**
     * Check if the authenticated user has any of the given roles.
     */
    protected function userHasRole(array $roles): bool
    {
        $userId = $this->getAuthUserId();
        if ($userId <= 0) return false;

        try {
            $user = \App\Helpers\Auth::getInstance()->getUserById($userId);
            return $user && in_array($user['role'] ?? '', $roles, true);
        } catch (\Throwable $e) { return false; }
    }

    /**
     * Get the authenticated user ID. Override in BaseController if needed.
     */
    abstract protected function getAuthUserId(): int;
}

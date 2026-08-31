<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;
use App\Helpers\AuthorizationService;

/**
 * Authorization Middleware — server-side route permission enforcement (Phase 2).
 *
 * The required permission for an endpoint is defined EXCLUSIVELY by trusted
 * server-side code: every route registered in the root api.php router carries
 * its permission as "module:action" (or null = authenticated-only, with
 * ownership/organizational scope enforced inside the controller). The router
 * calls AuthorizationMiddleware::enforce() with that value BEFORE the
 * controller runs.
 *
 * Security invariants:
 *  - The request can NEVER define its own permission requirement. The legacy
 *    behaviour of reading client-supplied permission parameters from
 *    the request superglobals was a privilege-escalation hole and has been
 *    removed.
 *  - The permission is resolved through the single authorization engine
 *    (AuthorizationService): super_admin policy + user overrides + role
 *    permissions + default deny.
 *  - This middleware runs ON TOP of the global authentication gate
 *    (AuthenticationMiddleware::process) — a request reaching here is already
 *    authenticated, so the authenticated user id is always available.
 *  - Controllers keep their own requirePermission() checks as defense in
 *    depth; this gate guarantees the route-level requirement is server-defined
 *    and deterministic.
 */
class AuthorizationMiddleware extends BaseMiddleware
{
    /**
     * Enforce a server-defined route permission ("module:action").
     * Emits a JSON 403 and terminates the request when denied.
     *
     * @param string $permission e.g. "employees:view"
     */
    public static function enforce(string $permission): void
    {
        if (self::check($permission)) {
            return;
        }

        \App\Helpers\ApiResponse::error(
            'Forbidden - insufficient permissions for this action.',
            'FORBIDDEN',
            [],
            403
        );
    }

    /**
     * Testable check for a server-defined route permission.
     *
     * @param string $permission "module:action" (e.g. "employees:view")
     */
    public static function check(string $permission): bool
    {
        $parts = explode(':', $permission, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            // A malformed server-side mapping is a development error — deny.
            return false;
        }

        [$module, $action] = $parts;

        // The authenticated user id (never a role string — roles are derived
        // inside AuthorizationService from trusted context).
        $userId = Auth::getInstance()->id();

        return AuthorizationService::getInstance()->hasPermission($userId, $module, $action);
    }

    /**
     * Pipeline entry (handle pattern) — kept for middleware-pipeline callers.
     *
     * Without a server-defined permission there is nothing to enforce here:
     * route permissions are attached to the route definitions in api.php and
     * enforced via enforce(). This class intentionally does NOT read any
     * request parameters.
     */
    public function handle(callable $next): mixed
    {
        return $next();
    }
}

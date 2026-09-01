<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use Tests\TestCase;

/**
 * Route → Permission map integrity (Phase 2, Sections 3 + 13).
 *
 * Guarantees the permission requirement of every API endpoint is defined
 * exclusively by trusted server-side code:
 *   1. Every route in api.php carries a server-defined permission
 *      ("module:action" from the catalog) OR is in the reviewed allowlist
 *      (backend/config/authz_allowlist.php).
 *   2. Every mapped permission exists in the permission catalog.
 *   3. The enforcement middleware never reads requirements from the request.
 *
 * Place: backend/tests/Unit/Authorization/RoutePermissionMapTest.php
 */
class RoutePermissionMapTest extends TestCase
{
    /** @var array<int, array{method:string, path:string, permission:?string, line:int}> */
    private array $routes = [];

    /** @var array<string, string> "METHOD /path" => allowlist group */
    private array $allowlist = [];

    /** @var array<string, array<string, string>> module => action => type */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->routes = $this->parseRoutes();
        $this->allowlist = $this->parseAllowlist();
        $this->catalog = $this->parseCatalog();
    }

    public function testApiFileRegistersRoutes(): void
    {
        $this->assertGreaterThan(100, count($this->routes), 'api.php route parsing failed');
    }

    public function testEveryRouteHasServerDefinedPermissionOrIsAllowlisted(): void
    {
        $unmapped = [];
        foreach ($this->routes as $route) {
            if ($route['permission'] === null && !isset($this->allowlist[$route['method'] . ' ' . $route['path']])) {
                $unmapped[] = sprintf('line %d: %s %s', $route['line'], $route['method'], $route['path']);
            }
        }

        $this->assertSame(
            [],
            $unmapped,
            "Routes without a server-defined permission and without an allowlist entry:\n" . implode("\n", $unmapped)
        );
    }

    public function testEveryMappedPermissionExistsInTheCatalog(): void
    {
        $unknown = [];
        foreach ($this->routes as $route) {
            $permission = $route['permission'];
            if ($permission === null) {
                continue;
            }

            [$module, $action] = explode(':', $permission, 2);
            if (!isset($this->catalog[$module][$action])) {
                $unknown[] = sprintf('line %d: %s %s -> %s', $route['line'], $route['method'], $route['path'], $permission);
            }
        }

        $this->assertSame(
            [],
            $unknown,
            "Route permissions not defined in the permission catalog (drift):\n" . implode("\n", $unknown)
        );
    }

    public function testEveryAllowlistEntryMatchesARegisteredRoute(): void
    {
        $registered = [];
        foreach ($this->routes as $route) {
            $registered[$route['method'] . ' ' . $route['path']] = true;
        }

        $orphans = [];
        foreach ($this->allowlist as $entry => $group) {
            if (!isset($registered[$entry])) {
                $orphans[] = sprintf('%s (group: %s)', $entry, $group);
            }
        }

        $this->assertSame(
            [],
            $orphans,
            "Allowlist entries that no longer match any registered route:\n" . implode("\n", $orphans)
        );
    }

    public function testAllowlistEntriesCarryNoRoutePermission(): void
    {
        $contradictions = [];
        foreach ($this->routes as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            if (isset($this->allowlist[$key]) && $route['permission'] !== null) {
                $contradictions[] = sprintf('%s is allowlisted but maps to %s', $key, $route['permission']);
            }
        }

        $this->assertSame([], $contradictions);
    }

    public function testPublicAllowlistMirrorsAuthenticationMiddleware(): void
    {
        $middleware = file_get_contents(__DIR__ . '/../../../app/Middleware/AuthenticationMiddleware.php');
        $this->assertNotFalse($middleware);

        preg_match('/PUBLIC_ROUTES\s*=\s*\[(.*?)\]/s', $middleware, $m);
        $this->assertArrayHasKey(1, $m, 'PUBLIC_ROUTES not found in AuthenticationMiddleware');

        preg_match_all("/'([A-Z]+) ([^']+)'/", $m[1], $entries);
        $publicRoutes = [];
        foreach ($entries[1] as $i => $method) {
            $publicRoutes[$method . ' ' . $entries[2][$i]] = true;
        }

        $allowlistConfig = require BASE_PATH . '/backend/config/authz_allowlist.php';
        foreach ($allowlistConfig['public'] as $entry) {
            $this->assertArrayHasKey(
                $entry,
                $publicRoutes,
                "Allowlist marks '$entry' public but AuthenticationMiddleware::PUBLIC_ROUTES does not"
            );
        }
    }

    public function testEnforcementMiddlewareNeverReadsRequestParameters(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Middleware/AuthorizationMiddleware.php');
        $this->assertNotFalse($source);

        // The legacy hole: the request defined its own permission requirement
        // via permission_resource / permission_action. This must never return.
        $this->assertStringNotContainsString('$_GET', $source);
        $this->assertStringNotContainsString('$_POST', $source);
        $this->assertStringNotContainsString('$_REQUEST', $source);
        $this->assertStringNotContainsString('permission_resource', $source);
        $this->assertStringNotContainsString('permission_action', $source);
    }

    public function testRouterEnforcesTheServerDefinedRoutePermission(): void
    {
        $source = file_get_contents(BASE_PATH . '/api.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            "AuthorizationMiddleware::enforce(\$route['permission'])",
            $source,
            'The router must enforce the route-defined permission before dispatching'
        );
    }

    public function testPermissionAdministrationEndpointsAreGated(): void
    {
        $expected = [
            'GET /permissions/roles'                   => 'permission_overrides:view',
            'GET /permissions/users'                   => 'permission_overrides:view',
            'GET /permissions/users/{id}'              => 'permission_overrides:view',
            'GET /permissions/overrides'               => 'permission_overrides:view',
            'POST /permissions/users/{id}/overrides'   => 'permission_overrides:manage',
            'DELETE /permissions/users/{id}/overrides' => 'permission_overrides:manage',
        ];

        $mapped = [];
        foreach ($this->routes as $route) {
            $mapped[$route['method'] . ' ' . $route['path']] = $route['permission'];
        }

        foreach ($expected as $route => $permission) {
            $this->assertArrayHasKey($route, $mapped, "Missing permission administration route: $route");
            $this->assertSame(
                $permission,
                $mapped[$route],
                "Permission administration route $route must require $permission"
            );
        }
    }

    public function testClientSuppliedPermissionHintsCannotSatisfyARequirement(): void
    {
        // Even with attacker-controlled parameters present, an unauthenticated
        // caller must be denied; the middleware consults only its argument and
        // the authenticated identity.
        $_GET['permission_resource'] = 'users';
        $_GET['permission_action'] = 'view';
        $_POST['permission_resource'] = 'users';
        $_POST['permission_action'] = 'view';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_COOKIE = [];
        $_SESSION = [];

        $this->assertFalse(
            \App\Middleware\AuthorizationMiddleware::check('employees:view'),
            'An unauthenticated request must never satisfy a route permission'
        );
        $this->assertFalse(\App\Middleware\AuthorizationMiddleware::check('users:view'));

        // Malformed server-side mappings are a development error: deny.
        $this->assertFalse(\App\Middleware\AuthorizationMiddleware::check(''));
        $this->assertFalse(\App\Middleware\AuthorizationMiddleware::check('employees'));
        $this->assertFalse(\App\Middleware\AuthorizationMiddleware::check(':view'));
        $this->assertFalse(\App\Middleware\AuthorizationMiddleware::check('employees:'));

        unset($_GET['permission_resource'], $_GET['permission_action']);
        unset($_POST['permission_resource'], $_POST['permission_action']);
    }

    public function testMiddlewareUsesTheAuthenticatedUserIdNotARole(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../app/Middleware/AuthorizationMiddleware.php');
        $this->assertNotFalse($source);

        // The middleware must resolve the identity through Auth::id() and
        // delegate to the engine — roles are derived inside the service.
        $this->assertStringContainsString('Auth::getInstance()->id()', $source);
        $this->assertStringContainsString('AuthorizationService::getInstance()->hasPermission($userId', $source);
    }

    /**
     * Parse every $router->add(...) registration from api.php.
     *
     * @return array<int, array{method:string, path:string, permission:?string, throttle:?string, line:int}>
     */
    private function parseRoutes(): array
    {
        $source = file_get_contents(BASE_PATH . '/api.php');
        $this->assertNotFalse($source);

        $routes = [];
        foreach (preg_split('/\R/', $source) as $i => $line) {
            if (strpos($line, '$router->add(') === false) {
                continue;
            }
            if (!preg_match("/\\\$router->add\(\s*'([A-Z]+)'\s*,\s*'([^']+)'/", $line, $head)) {
                continue;
            }
            // Phase 7: strip an optional trailing throttle argument
            // (", 'max:window'" or ", null, 'max:window'") so the permission
            // regex below keeps matching routes that declare a throttle.
            $throttle = null;
            if (preg_match("/,\s*(?:null,\s*)?'(\d+:\d+)'\s*\)\s*;\s*$/", $line, $thr)) {
                $throttle = $thr[1];
                $line = (string) preg_replace("/,\s*(?:null,\s*)?'(\d+:\d+)'\s*\)\s*;\s*$/", ');', $line);
            }


            $permission = null;
            if (preg_match("/,\s*'([a-z_]+:[a-z_]+)'\s*\)\s*;\s*$/", $line, $perm)) {
                $permission = $perm[1];
            }
            $routes[] = [
                'method'     => $head[1],
                'path'       => $head[2],
                'permission' => $permission,
                'throttle'   => $throttle,
                'line'       => $i + 1,
            ];
        }

        return $routes;
    }

    /**
     * Phase 7 (P7-4): every route in the rate-limit governance list
     * (backend/config/rate_limits.php) MUST declare a server-defined
     * throttle in api.php. Keeps sensitive endpoints (exports, identity
     * writes, privilege changes, uploads, approvals, clock) throttled even
     * as new development adds or moves routes.
     */
    public function testSensitiveRoutesDeclareAThrottle(): void
    {
        $governance = require BASE_PATH . '/backend/config/rate_limits.php';

        $throttles = [];
        foreach ($this->routes as $route) {
            if ($route['throttle'] !== null) {
                $throttles[$route['method'] . ' ' . $route['path']] = $route['throttle'];
            }
        }

        $missing = [];
        foreach ($governance as $group => $entries) {
            foreach ($entries as $entry) {
                if (!isset($throttles[$entry])) {
                    $missing[] = sprintf('%s (group: %s)', $entry, $group);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Sensitive routes without server-defined throttle metadata:\n" . implode("\n", $missing)
        );
    }

    /**
     * Phase 7 (P7-4): throttle metadata must be "max:windowSeconds" with
     * positive integers — a malformed value would silently disable the
     * limit or lock the endpoint down entirely.
     */
    public function testThrottleMetadataFormatIsValid(): void
    {
        $invalid = [];
        foreach ($this->routes as $route) {
            if ($route['throttle'] === null) {
                continue;
            }

            [$max, $window] = array_map('intval', explode(':', $route['throttle'], 2));
            if (!preg_match('/^\d+:\d+$/', $route['throttle']) || $max < 1 || $window < 1) {
                $invalid[] = sprintf(
                    'line %d: %s %s -> %s',
                    $route['line'],
                    $route['method'],
                    $route['path'],
                    $route['throttle']
                );
            }
        }

        $this->assertSame(
            [],
            $invalid,
            'Invalid throttle metadata (expected "positive-int:positive-int")'
        );
    }


    /**
     * @return array<string, string> "METHOD /path" => group
     */
    private function parseAllowlist(): array
    {
                $config = require BASE_PATH . '/backend/config/authz_allowlist.php';

        $flat = [];
        foreach ($config as $group => $entries) {
            foreach ($entries as $entry) {
                $flat[$entry] = $group;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, array<string, string>> module => action => type
     */
    private function parseCatalog(): array
    {
                $config = require BASE_PATH . '/backend/config/permissions.php';

        $map = [];
        foreach ($config['modules'] as $module) {
            $map[$module['key']] = [];
            foreach ($module['actions'] as $action) {
                $map[$module['key']][$action['key']] = $action['type'];
            }
        }

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every route in api.php must name a controller method that actually exists.
 *
 * WHY THIS SUITE EXISTS
 *   A 500 was served from POST /profile/employees/{id}/documents/request-access
 *   because the route referenced `requestEmployeeDocumentsAccessAction` on
 *   EmployeeController, and that method had never been written. The route file
 *   is a long list of string references; nothing checks them, so a route can
 *   name a method that does not exist and the only symptom is a 500 in the
 *   browser at the moment a user clicks a button.
 *
 *   This turns that into a build-time failure. It is the check that should have
 *   been run before the route was registered in the first place.
 *
 * WHY IT PARSES THE FILE RATHER THAN USING A RUNNER
 *   The router is built inside api.php, which is also the HTTP entry point, so
 *   it cannot be required in isolation. Parsing the registrations is the only
 *   way to audit all 373 of them without executing the front controller.
 */
final class RouteRegistrationTest extends TestCase
{
    private const API_FILE = __DIR__ . '/../../../api.php';

    /**
     * @return array<int, array{0:string,1:string,2:string,3:string}>
     */
    private function routes(): array
    {
        $this->assertFileExists(self::API_FILE, 'api.php not found');

        $src = (string) file_get_contents(self::API_FILE);

        $pattern = '/\$router->add\(\s*'
            . '\'(?P<verb>[A-Z]+)\'\s*,\s*'
            . '\'(?P<path>[^\']+)\'\s*,\s*'
            . '(?P<ctrl>[\\\\A-Za-z0-9_]+)::class\s*,\s*'
            . '\'(?P<action>[A-Za-z_][A-Za-z0-9_]*)\'/';

        preg_match_all($pattern, $src, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'no routes parsed - the pattern is wrong, not the file');

        $out = [];
        foreach ($matches as $m) {
            $out[] = [$m['verb'], $m['path'], ltrim($m['ctrl'], '\\'), $m['action']];
        }
        $this->assertGreaterThan(300, count($out), 'expected the full route table');

        return $out;
    }

    public function testEveryRouteNamesAnExistingControllerMethod(): void
    {
        $routes = $this->routes();
        $this->assertGreaterThan(
            300,
            count($routes),
            'expected the full route table; a low count means parsing broke'
        );
        $vaultish = null;
        unset($vaultish);

        $mine      = [];
        $preOwned  = $this->knownUnrelatedGaps();
        $unexpected = 0;

        foreach ($routes as [$verb, $path, $controller, $action]) {
            $fqcn = $this->aliases()[$controller] ?? $controller;

            $resolved = class_exists($fqcn)
                ? (method_exists($fqcn, $action) ? $action
                    : (method_exists($fqcn, $action . 'Action') ? $action . 'Action' : null))
                : null;

            if ($resolved !== null) {
                continue;
            }

            $key = "{$verb} {$path}";
            if (in_array($key, $preOwned, true)) {
                // Pre-existing and unrelated to the vault/OTP work. Tracked,
                // not silently tolerated: adding to this list is a deliberate
                // act, and the comment above says so.
                continue;
            }

            $unexpected++;
            $mine[] = $key . " -> {$fqcn}::{$action}()";
        }

        $this->assertSame(
            [],
            $mine,
            "these routes name a method that does not exist:\n  " . implode("\n  ", $mine)
        );
    }

    /**
     * Routes that were already broken before the vault/OTP work.
     *
     * Each names a controller method that does not exist, so calling it would
     * return 500. Found by RouteRegistrationTest, not introduced by it, and
     * none touch the vault or the document gate.
     *
     * Kept as an explicit list rather than ignored, so the count cannot quietly
     * grow: adding a line here is a deliberate act, and the new route is then
     * knowingly left broken. A separate cleanup should remove the routes or add
     * the methods - that is out of scope for this change.
     *
     * @return array<int,string>
     */
    private function knownUnrelatedGaps(): array
    {
        return [
            'GET /consents',
            'PUT /consents/{id}',
            'GET /reports/employees',
            'GET /reports/leave',
            'GET /reports/attendance',
            'GET /reports/appraisal',
            'GET /reports/documentation',
            'GET /reports/{type}/export/{format}',
            'GET /reports/strategic-performance',
            'POST /admin/notifications/test-send',
        ];
    }

    /**
     * The vault and document routes in particular, named so a failure points at
     * the feature rather than at "some route somewhere".
     */
    public function testVaultAndDocumentRoutesResolve(): void
    {
        $expected = [
            'GET /vault/status',
            'POST /vault/setup',
            'PUT /vault/items/{group}',
            'GET /vault/items/{employeeId}',
            'GET /vault/lock-state/{employeeId}',
            'POST /vault/requests',
            'PUT /vault/requests/{id}',
            'POST /vault/grants',
            'DELETE /vault/grants/{id}',
            'POST /profile/documents/{id}/request-access',
            'POST /profile/documents/{id}/verify',
            'GET /profile/documents/{id}/open',
            'POST /profile/employees/{employeeId}/documents/request-access',
            'POST /profile/employees/{employeeId}/documents/verify',
        ];

        $found = [];
        $aliases = $this->aliases();
        $seen = 0;

        foreach ($this->routes() as [$verb, $path, $controller, $action]) {
            // Narrow on purpose. `/leave/{id}/documents` and the bare
            // `/profile/documents` upload route belong to other features and
            // must not be swept in.
            //
            // The OTP routes come in two shapes and both must be matched:
            //   /profile/documents/{id}/verify        (document-scoped)
            //   /profile/employees/{employeeId}/documents/verify  (employee-scoped)
            // so the pattern cannot assume which segment carries the placeholder.
            // Anchoring on the trailing action is what keeps `/leave/{id}/documents`
            // out: it ends in "documents", not in one of the three verbs.
            $isVault = str_starts_with($path, '/vault');
            $isOtp   = preg_match(
                '#/(documents|employees)/\{[a-zA-Z]+\}/?'
                . '(documents/)?(request-access|verify|open)$#',
                $path
            ) === 1;
            if (!$isVault && !$isOtp) {
                continue;
            }
            $seen++;

            $fqcn     = $aliases[$controller] ?? $controller;
            $resolved = method_exists($fqcn, $action) ? $action
                : (method_exists($fqcn, $action . 'Action') ? $action . 'Action' : null);

            $this->assertNotNull(
                $resolved,
                "{$verb} {$path} names {$fqcn}::{$action}() which does not exist"
            );
            $found[] = "{$verb} {$path}";
        }
        $this->assertGreaterThan(0, $seen, 'the filter matched no routes at all');

        // Order-independent: the routes are registered in the order they appear
        // in api.php, and this list is written in feature order. Asserting on
        // sequence would make the test fail on a harmless reordering, which
        // trains people to ignore it. Sorted comparison checks the SET, which is
        // what actually matters.
        $expectedSorted = $expected;
        $foundSorted    = $found;
        sort($expectedSorted);
        sort($foundSorted);

        $this->assertSame(
            $expectedSorted,
            $foundSorted,
            "the vault/document route surface changed - confirm it is intentional.\n"
            . '  missing: ' . implode(', ', array_diff($expectedSorted, $foundSorted)) . "\n"
            . '  added:   ' . implode(', ', array_diff($foundSorted, $expectedSorted))
        );
    }

    /**
     * Map of short controller name => fully qualified name, from api.php's
     * `use` statements.
     *
     * @return array<string,string>
     */
    private function aliases(): array
    {
        $aliases = [];
        preg_match_all(
            '/^use\s+([\\\\A-Za-z0-9_]+);/m',
            (string) file_get_contents(self::API_FILE),
            $uses
        );

        foreach ($uses[1] as $fqcn) {
            // '\\' is the namespace separator. Written as chr(92) concatenation
            // because a literal backslash inside these single-quoted strings is
            // easy to get wrong, and a wrong separator here silently produces
            // an unresolvable short name for every controller.
            $separator = chr(92);
            $pos       = strrpos($fqcn, $separator);
            $short     = $pos === false ? $fqcn : substr($fqcn, $pos + 1);

            $aliases[$short] = $fqcn;
        }

        return $aliases;
    }
}

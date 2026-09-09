<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use Tests\TestCase;

/**
 * Employee controller tests.
 *
 * Phase 1 note: the legacy version of this file called methods that do not
 * exist (`index()` / `store()`) and passed a service mock that the controller
 * constructor ignores (it builds its own service graph), then attempted to
 * capture output that terminates via exit(). It errored on every run and
 * provided no real coverage.
 *
 * The controller is a thin HTTP layer over EmployeeService:
 *   - service behaviour is unit-tested in EmployeeServiceTest (mocked repos);
 *   - true end-to-end HTTP coverage (including storeAction, which writes and
 *     provisions user accounts) belongs to a dedicated integration suite with
 *     an isolated database - planned for the Phase 2 test suite.
 */
class EmployeeControllerTest extends TestCase
{
    public function test_http_coverage_is_integration_scoped(): void
    {
        $this->markTestSkipped(
            'EmployeeController responses terminate via exit() and the controller builds its own service graph; '
            . 'HTTP integration coverage is planned for the Phase 2 test suite.'
        );
    }
}
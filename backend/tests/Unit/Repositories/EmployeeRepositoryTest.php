<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Repositories\EmployeeRepository;
use App\Helpers\Database;

/**
 * Employee repository tests (integration-scoped).
 *
 * Phase 1 note: the repository binds to the real mysqli connection in its
 * constructor (no injection seam), so these tests carry the `requires-db`
 * group: they are EXCLUDED from the default CI suite (phpunit.xml.dist) and
 * run against a live schema in development. They are intentionally
 * READ-ONLY - no writes to the shared database.
 *
 * @group requires-db
 */
class EmployeeRepositoryTest extends TestCase
{
    private EmployeeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $conn = Database::getInstance()->getConnection();
            $conn->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Requires a reachable database: ' . $e->getMessage());
        }

        $this->repository = new EmployeeRepository();
    }

    public function test_find_all_returns_an_array(): void
    {
        $this->assertIsArray($this->repository->findAll());
    }

    public function test_find_by_id_returns_null_for_nonexistent_employee(): void
    {
        $this->assertNull($this->repository->findById(99999999));
    }

    public function test_employee_id_exists_is_false_for_unknown_id(): void
    {
        $this->assertFalse($this->repository->employeeIdExists('NO_SUCH_EMPLOYEE_ID_XYZ', null));
    }
}
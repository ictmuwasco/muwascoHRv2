<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\EmployeeService;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use Mockery;

/**
 * Employee service tests aligned with the CURRENT service contract.
 *
 * Phase 1 note: the legacy version of this file targeted an older
 * service/repository API (validator injection, array return values) and
 * errored on every run. These tests exercise the real API:
 *   - getAll()  -> EmployeeRepository::search()
 *   - create()  -> internal validation + EmployeeRepository::create() (int)
 *   - update()  -> existence check + EmployeeRepository::update() (bool)
 *   - delete()  -> existence check + EmployeeRepository::delete() (bool)
 */
class EmployeeServiceTest extends TestCase
{
    private EmployeeService $service;
    private $mockRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepo = Mockery::mock(EmployeeRepositoryInterface::class);
        $this->service = new EmployeeService($this->mockRepo);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function validEmployeeData(): array
    {
        return [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'national_id' => '12345678',
            'employee_type' => 'permanent',
            'employee_status' => 'active',
            'hire_date' => '2024-01-15',
        ];
    }

    public function test_get_all_employees_delegates_to_repository_search(): void
    {
        $employees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith'],
        ];

        $this->mockRepo->shouldReceive('search')->once()->with([], 1, 30)->andReturn($employees);

        $result = $this->service->getAll();

        $this->assertCount(2, $result);
        $this->assertSame('John', $result[0]['first_name']);
    }

    public function test_create_employee_returns_new_id(): void
    {
        $data = $this->validEmployeeData();

        $this->mockRepo->shouldReceive('employeeIdExists')->once()->with('EMP001', null)->andReturn(false);
        $this->mockRepo->shouldReceive('emailExists')->once()->with('john@example.com', null)->andReturn(false);
        $this->mockRepo->shouldReceive('nationalIdExists')->once()->with('12345678', null)->andReturn(false);
        $this->mockRepo->shouldReceive('create')->once()->with(Mockery::on(fn ($d) => $d['employee_id'] === 'EMP001'))->andReturn(7);

        $this->assertSame(7, $this->service->create($data));
    }

    public function test_create_employee_rejects_duplicate_employee_id(): void
    {
        $data = $this->validEmployeeData();

        // validateEmployeeData() evaluates ALL rules before returning, so the
        // other uniqueness checks must also be satisfied.
        $this->mockRepo->shouldReceive('employeeIdExists')->once()->with('EMP001', null)->andReturn(true);
        $this->mockRepo->shouldReceive('emailExists')->once()->with('john@example.com', null)->andReturn(false);
        $this->mockRepo->shouldReceive('nationalIdExists')->once()->with('12345678', null)->andReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Employee ID already exists');

        $this->service->create($data);
    }

    public function test_update_partial_employee_fields_skips_full_validation(): void
    {
        $this->mockRepo->shouldReceive('findById')->once()->with(1)->andReturn(['id' => 1, 'first_name' => 'John']);
        $this->mockRepo->shouldReceive('update')->once()->with(1, Mockery::on(fn ($d) => ($d['next_of_kin'] ?? null) === 'Jane Doe'))->andReturn(true);

        $this->assertTrue($this->service->update(1, ['next_of_kin' => 'Jane Doe']));
    }

    public function test_update_missing_employee_throws(): void
    {
        $this->mockRepo->shouldReceive('findById')->once()->with(99)->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Employee not found');

        $this->service->update(99, ['first_name' => 'X']);
    }

    public function test_delete_existing_employee(): void
    {
        $this->mockRepo->shouldReceive('findById')->once()->with(1)->andReturn(['id' => 1]);
        $this->mockRepo->shouldReceive('delete')->once()->with(1)->andReturn(true);

        $this->assertTrue($this->service->delete(1));
    }
}
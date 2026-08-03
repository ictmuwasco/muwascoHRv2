<?php

namespace Tests\Unit\Repositories;

use Tests\TestCase;
use App\Repositories\EmployeeRepository;
use App\Helpers\Database;
use Mockery;

class EmployeeRepositoryTest extends TestCase
{
    private EmployeeRepository $repository;
    private $mockDb;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockDb = Mockery::mock(Database::class);
        $this->repository = new EmployeeRepository($this->mockDb);
    }

    public function test_can_find_all_employees(): void
    {
        $mockResults = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith']
        ];

        $this->mockDb
            ->shouldReceive('fetchAll')
            ->once()
            ->andReturn($mockResults);

        $result = $this->repository->getAll();

        $this->assertCount(2, $result);
    }

    public function test_can_find_employee_by_id(): void
    {
        $employeeId = 1;
        $mockEmployee = ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'];

        $this->mockDb
            ->shouldReceive('fetchOne')
            ->once()
            ->andReturn($mockEmployee);

        $result = $this->repository->findById($employeeId);

        $this->assertEquals('John', $result['first_name']);
    }

    public function test_can_create_employee(): void
    {
        $employeeData = [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com'
        ];

        $this->mockDb
            ->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $result = $this->repository->create($employeeData);

        $this->assertEquals(1, $result);
    }

    public function test_can_update_employee(): void
    {
        $employeeId = 1;
        $updateData = ['first_name' => 'John Updated'];

        $this->mockDb
            ->shouldReceive('update')
            ->once()
            ->andReturn(true);

        $result = $this->repository->update($employeeId, $updateData);

        $this->assertTrue($result);
    }

    public function test_can_delete_employee(): void
    {
        $employeeId = 1;

        $this->mockDb
            ->shouldReceive('delete')
            ->once()
            ->andReturn(true);

        $result = $this->repository->delete($employeeId);

        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
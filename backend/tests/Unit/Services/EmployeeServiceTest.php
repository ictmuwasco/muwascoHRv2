<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\EmployeeService;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Validators\EmployeeValidator;
use Mockery;

class EmployeeServiceTest extends TestCase
{
    private EmployeeService $service;
    private $mockRepo;
    private $mockValidator;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockRepo = Mockery::mock(EmployeeRepositoryInterface::class);
        $this->mockValidator = Mockery::mock(EmployeeValidator::class);
        
        $this->service = new EmployeeService(
            $this->mockRepo,
            $this->mockValidator
        );
    }

    public function test_can_get_all_employees(): void
    {
        $mockEmployees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith']
        ];

        $this->mockRepo
            ->shouldReceive('getAll')
            ->once()
            ->andReturn($mockEmployees);

        $result = $this->service->getAll();

        $this->assertCount(2, $result);
        $this->assertEquals('John', $result[0]['first_name']);
    }

    public function test_can_create_employee(): void
    {
        $employeeData = [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com'
        ];

        $this->mockValidator
            ->shouldReceive('validate')
            ->once()
            ->andReturn([]);

        $this->mockRepo
            ->shouldReceive('create')
            ->once()
            ->with($employeeData)
            ->andReturn(array_merge($employeeData, ['id' => 1]));

        $result = $this->service->create($employeeData);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('John', $result['first_name']);
    }

    public function test_can_update_employee(): void
    {
        $employeeId = 1;
        $updateData = ['first_name' => 'John Updated'];

        $this->mockRepo
            ->shouldReceive('update')
            ->once()
            ->with($employeeId, $updateData)
            ->andReturn(array_merge($updateData, ['id' => $employeeId]));

        $result = $this->service->update($employeeId, $updateData);

        $this->assertEquals('John Updated', $result['first_name']);
    }

    public function test_can_delete_employee(): void
    {
        $employeeId = 1;

        $this->mockRepo
            ->shouldReceive('delete')
            ->once()
            ->with($employeeId)
            ->andReturn(true);

        $result = $this->service->delete($employeeId);

        $this->assertTrue($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
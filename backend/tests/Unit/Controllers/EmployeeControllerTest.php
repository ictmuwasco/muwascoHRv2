<?php

namespace Tests\Unit\Controllers;

use Tests\TestCase;
use App\Controllers\Employee\EmployeeController;
use App\Services\Contracts\EmployeeServiceInterface;
use App\Responses\JsonResponse;
use Mockery;

class EmployeeControllerTest extends TestCase
{
    private EmployeeController $controller;
    private $mockEmployeeService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockEmployeeService = Mockery::mock(EmployeeServiceInterface::class);
        $this->controller = new EmployeeController($this->mockEmployeeService);
    }

    public function test_can_get_all_employees(): void
    {
        $mockEmployees = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'],
            ['id' => 2, 'first_name' => 'Jane', 'last_name' => 'Smith']
        ];

        $this->mockEmployeeService
            ->shouldReceive('getAll')
            ->once()
            ->andReturn($mockEmployees);

        $_GET['page'] = 1;
        $_GET['per_page'] = 10;

        ob_start();
        $this->controller->index();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['data']);
    }

    public function test_can_create_employee(): void
    {
        $employeeData = [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com'
        ];

        $_POST = $employeeData;

        $this->mockEmployeeService
            ->shouldReceive('create')
            ->once()
            ->andReturn(array_merge($employeeData, ['id' => 1]));

        ob_start();
        $this->controller->store();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['data']['id']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
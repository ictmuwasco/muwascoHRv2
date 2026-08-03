<?php

namespace Tests\Integration\Api;

use Tests\TestCase;
use App\Bootstrap;

class EmployeeApiTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = new Bootstrap();
    }

    public function test_can_get_employees_list(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/employees';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertIsArray($response['data']);
    }

    public function test_can_create_employee_via_api(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/employees';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $_POST = [
            'employee_id' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'department_id' => 1,
            'section_id' => 1,
            'office_id' => 1
        ];

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['data']['id']);
    }

    public function test_can_get_employee_by_id(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/employees/1';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
        $this->assertEquals(1, $response['data']['id']);
    }

    public function test_can_update_employee(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/employees/1';
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        $_PUT = ['first_name' => 'John Updated'];

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }

    public function test_can_delete_employee(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/employees/1';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';

        ob_start();
        $this->app->run();
        $output = ob_get_clean();

        $response = json_decode($output, true);
        $this->assertTrue($response['success']);
    }
}
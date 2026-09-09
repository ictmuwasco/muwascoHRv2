<?php

declare(strict_types=1);

namespace Tests\Unit\Validators;

use PHPUnit\Framework\TestCase;
use App\Validators\LeaveValidator;
use App\Validators\EmployeeValidator;
use App\Validators\UserValidator;
use App\Validators\DepartmentValidator;

/**
 * Request-validation layer (Phase 3, Section 11).
 *
 * Validators own input SHAPE and FORMAT only. Business rules (uniqueness,
 * existence, balances, overlaps) are owned by the service layer and are
 * deliberately absent from these classes — which is what makes these pure
 * unit tests possible with no database dependency.
 */
class RequestValidatorsTest extends TestCase
{
    // ---------------------------------------------------------------- Leave

    private function validLeavePayload(): array
    {
        return [
            'employee_id'   => 1,
            'leave_type_id' => 2,
            'start_date'    => '2026-03-02',
            'end_date'      => '2026-03-06',
            'reason'        => 'Annual leave for a family event.',
        ];
    }

    public function test_leave_passes_with_valid_payload(): void
    {
        $v = new LeaveValidator();
        $this->assertTrue($v->passes($this->validLeavePayload()));
        $this->assertSame([], $v->errors());
    }

    public function test_leave_fails_when_required_fields_missing(): void
    {
        $v = new LeaveValidator();
        $this->assertFalse($v->passes([]));
        $errors = $v->errors();
        foreach (['employee_id', 'leave_type_id', 'start_date', 'end_date'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Missing expected error for {$field}");
        }
    }

    public function test_leave_rejects_end_date_before_start_date(): void
    {
        $payload = $this->validLeavePayload();
        $payload['start_date'] = '2026-03-10';
        $payload['end_date'] = '2026-03-05';

        $v = new LeaveValidator();
        $this->assertFalse($v->passes($payload));
        $this->assertArrayHasKey('end_date', $v->errors());
    }

    public function test_leave_rejects_malformed_dates(): void
    {
        $payload = $this->validLeavePayload();
        $payload['start_date'] = '05/03/2026';

        $v = new LeaveValidator();
        $this->assertFalse($v->passes($payload));
        $this->assertArrayHasKey('start_date', $v->errors());
    }

    // ------------------------------------------------------------- Employee

    public function test_employee_requires_core_identity_fields(): void
    {
        $v = new EmployeeValidator();
        $this->assertFalse($v->passes([]));

        $errors = $v->errors();
        foreach (['employee_id', 'email', 'national_id', 'first_name', 'last_name', 'employee_type', 'employee_status', 'hire_date'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Missing expected error for {$field}");
        }
    }

    public function test_employee_rejects_invalid_email_and_hire_date(): void
    {
        $payload = [
            'employee_id'     => 'EMP-001',
            'email'           => 'not-an-email',
            'national_id'     => '12345678',
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'employee_type'   => 'permanent',
            'employee_status' => 'active',
            'hire_date'       => '15-01-2026', // wrong format; must be Y-m-d
        ];

        $v = new EmployeeValidator();
        $this->assertFalse($v->passes($payload));

        $errors = $v->errors();
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('hire_date', $errors);
    }

    public function test_employee_passes_with_valid_payload(): void
    {
        $payload = [
            'employee_id'     => 'EMP-001',
            'email'           => 'jane.doe@example.com',
            'national_id'     => '12345678',
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'employee_type'   => 'permanent',
            'employee_status' => 'active',
            'hire_date'       => '2026-01-15',
        ];

        $v = new EmployeeValidator();
        $this->assertTrue($v->passes($payload));
    }

    // ----------------------------------------------------------------- User

    public function test_user_requires_email_names_and_role(): void
    {
        $v = new UserValidator();
        $this->assertFalse($v->passes([]));

        $errors = $v->errors();
        foreach (['email', 'first_name', 'last_name', 'role'] as $field) {
            $this->assertArrayHasKey($field, $errors, "Missing expected error for {$field}");
        }
    }

    public function test_user_rejects_invalid_email_and_short_password(): void
    {
        $payload = [
            'email'      => 'nope',
            'first_name' => 'A',
            'last_name'  => 'B',
            'role'       => 'employee',
            'password'   => 'short',
        ];

        $v = new UserValidator();
        $this->assertFalse($v->passes($payload));

        $errors = $v->errors();
        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_user_passes_without_optional_password(): void
    {
        $payload = [
            'email'      => 'user@example.com',
            'first_name' => 'A',
            'last_name'  => 'B',
            'role'       => 'employee',
        ];

        $v = new UserValidator();
        $this->assertTrue($v->passes($payload));
    }

    // ----------------------------------------------------------- Department

    public function test_department_requires_name(): void
    {
        $v = new DepartmentValidator();
        $this->assertFalse($v->passes([]));
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function test_department_rejects_oversized_name(): void
    {
        $v = new DepartmentValidator();
        $this->assertFalse($v->passes(['name' => str_repeat('x', 151)]));
        $this->assertArrayHasKey('name', $v->errors());
    }

    public function test_department_passes_with_valid_name(): void
    {
        $v = new DepartmentValidator();
        $this->assertTrue($v->passes(['name' => 'Human Resources']));
    }
}

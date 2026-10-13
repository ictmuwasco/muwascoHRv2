<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Services\EmployeeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Business rule: an employee must be at least 18 years old.
 *
 * Enforced in EmployeeService::validateEmployeeData(), which runs on both
 * createEmployee() and a full updateEmployee(), and mirrored in the form by
 * the date picker's max attribute (EmployeeForm.tsx, MIN_EMPLOYEE_AGE_YEARS).
 * A future date of birth is a special case of "under 18" and is rejected by
 * the same rule. The field itself stays optional - only a provided value is
 * age-checked.
 */
final class EmployeeServiceAgeValidationTest extends TestCase
{
    /** @var EmployeeRepositoryInterface&MockObject */
    private EmployeeRepositoryInterface $employeeRepository;

    private EmployeeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeRepository = $this->createMock(EmployeeRepositoryInterface::class);
        $this->employeeRepository->method('employeeIdExists')->willReturn(false);
        $this->employeeRepository->method('emailExists')->willReturn(false);
        $this->employeeRepository->method('nationalIdExists')->willReturn(false);

        $this->service = new EmployeeService($this->employeeRepository);
    }

    public function testAnAdultDateOfBirthPasses(): void
    {
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => '1990-01-01'])
        );

        $this->assertSame([], $errors);
    }

    public function testAnOmittedDateOfBirthIsStillAllowed(): void
    {
        $errors = $this->service->validateEmployeeData($this->payload());

        $this->assertSame([], $errors);
    }

    public function testExactlyEighteenYearsAgoTodayPasses(): void
    {
        // Turns 18 today - the earliest acceptable date of birth.
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => date('Y-m-d', strtotime('-18 years'))])
        );

        $this->assertSame([], $errors);
    }

    public function testOneDayShortOfEighteenIsRejected(): void
    {
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => date('Y-m-d', strtotime('-17 years'))])
        );

        $this->assertContains('Employee must be at least 18 years old', $errors);
    }

    public function testATeenagerDateOfBirthIsRejected(): void
    {
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => date('Y-m-d', strtotime('-10 years'))])
        );

        $this->assertContains('Employee must be at least 18 years old', $errors);
    }

    public function testAFutureDateOfBirthIsRejectedByTheSameRule(): void
    {
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => date('Y-m-d', strtotime('+1 day'))])
        );

        $this->assertContains('Employee must be at least 18 years old', $errors);
    }

    public function testAMalformedDateOfBirthIsRejected(): void
    {
        $errors = $this->service->validateEmployeeData(
            $this->payload(['date_of_birth' => 'not-a-date'])
        );

        $this->assertContains('Date of birth must be a valid date', $errors);
    }

    /** Payload that satisfies every OTHER rule in validateEmployeeData(). */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id'     => 'EMPAGE18TEST',
            'first_name'      => 'Tina',
            'last_name'       => 'Rollins',
            'email'           => 'tina.age18@example.invalid',
            'national_id'     => 18000101, // employees.national_id is INT(10)
            'employee_type'   => 'officer',
            'employee_status' => 'active',
            'employment_type' => 'permanent',
            'hire_date'       => date('Y-m-d'),
        ], $overrides);
    }
}

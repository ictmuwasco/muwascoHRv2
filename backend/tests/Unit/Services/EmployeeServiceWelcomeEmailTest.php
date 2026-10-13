<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\UserRepository;
use App\Services\EmployeeService;
use App\Services\PasswordResetService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the POST /employees "phantom 500" incident.
 *
 * WHY THESE EXIST
 *   The welcome-email block inside EmployeeService::createUserForEmployee()
 *   called PasswordResetService::sendWelcomeEmail() with a retired 4-argument
 *   (email, name, employee_id, otp) shape. That method now reads
 *   sendWelcomeEmail(int $userId, string $email, string $name) and lives under
 *   declare(strict_types=1), so every call raised a TypeError - which was then
 *   thrown OUTSIDE catch (\Exception) by a static Logger::error() call inside
 *   the block's own catch. The result: the employee + user rows COMMITTED, and
 *   the request died with a silent 500 afterwards; every retry then bounced off
 *   validateEmployeeData()'s duplicate checks with a 400.
 *
 * WHAT IS PINNED
 *   1. sendWelcomeEmail() is invoked with the NEW users.id, the login email
 *      and the display name - the exact (int, string, string) signature. A
 *      regression to the old call shape throws TypeError before reaching the
 *      mock and fails the once() expectation.
 *   2. A welcome-email failure NEVER fails the hire: createEmployee() returns
 *      the employee id as if nothing happened (mail is post-commit and
 *      non-fatal by design).
 *   3. A users-INSERT failure DOES propagate out of createEmployee() (and no
 *      mail is attempted), which is what lets the surrounding transaction
 *      roll the hire back - the rollback itself is proven in
 *      tests/Integration/Services/EmployeeServiceCreateTransactionTest.
 *
 * The service is exercised through the real createEmployee() entry point with
 * repository mocks; the transaction begin/commit happens on the shared (empty)
 * connection and writes nothing.
 */
final class EmployeeServiceWelcomeEmailTest extends TestCase
{
    /** @var EmployeeRepositoryInterface&MockObject */
    private EmployeeRepositoryInterface $employeeRepository;

    /** @var UserRepository&MockObject */
    private UserRepository $userRepository;

    /** @var PasswordResetService&MockObject */
    private PasswordResetService $passwordResetService;

    private EmployeeService $service;

    /** Drives the users-insert stub so a test can force a failed write. */
    private bool $userCreateSucceeds = true;

    /** users.id the stub hands back - the value the welcome mail must receive. */
    private const NEW_USER_ID = 716;

    /** employees.id the stub hands back. */
    private const EMPLOYEE_ID = 1205;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeRepository = $this->createMock(EmployeeRepositoryInterface::class);
        $this->employeeRepository->method('employeeIdExists')->willReturn(false);
        $this->employeeRepository->method('emailExists')->willReturn(false);
        $this->employeeRepository->method('nationalIdExists')->willReturn(false);
        $this->employeeRepository->method('create')->willReturn(self::EMPLOYEE_ID);

        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('findByEmail')->willReturn(null);
        // Flag-driven (not re-stubbed per test): PHPUnit 9 matchers are
        // unreliable when the same method is configured twice.
        $this->userRepository->method('createUser')->willReturnCallback(
            function (): int {
                if (!$this->userCreateSucceeds) {
                    throw new \RuntimeException('simulated users INSERT failure');
                }
                return self::NEW_USER_ID;
            }
        );

        $this->passwordResetService = $this->createMock(PasswordResetService::class);

        $this->service = new EmployeeService($this->employeeRepository);
        $this->service->setUserRepository($this->userRepository);
        $this->service->setPasswordResetService($this->passwordResetService);
    }

    public function testWelcomeEmailReceivesTheNewUsersIdEmailAndName(): void
    {
        // identicalTo() is type-strict: a string "716", an employee-id 4th
        // argument or swapped parameters all fail here - which is exactly how
        // the retired (email, name, employee_id, otp) call shape dies today.
        $this->passwordResetService
            ->expects($this->once())
            ->method('sendWelcomeEmail')
            ->with(
                $this->identicalTo(self::NEW_USER_ID),
                $this->identicalTo('tina.rollins@example.invalid'),
                $this->identicalTo('Tina')
            )
            ->willReturn(true);

        $employeeId = $this->service->createEmployee($this->payload());

        $this->assertSame(self::EMPLOYEE_ID, $employeeId);
    }

    public function testAFailingWelcomeEmailDoesNotFailTheHire(): void
    {
        $this->passwordResetService
            ->expects($this->once())
            ->method('sendWelcomeEmail')
            ->willThrowException(new \RuntimeException('SMTP down'));

        $employeeId = $this->service->createEmployee($this->payload());

        $this->assertSame(
            self::EMPLOYEE_ID,
            $employeeId,
            'a welcome-email failure must never surface as a failed create'
        );
    }

    public function testUserCreationFailurePropagatesSoTheTransactionCanRollBack(): void
    {
        $this->userCreateSucceeds = false;

        // The hire never committed - no mail may announce it.
        $this->passwordResetService->expects($this->never())->method('sendWelcomeEmail');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('simulated users INSERT failure');

        $this->service->createEmployee($this->payload());
    }

    /** Payload that satisfies every rule in validateEmployeeData(). */
    private function payload(): array
    {
        return [
            'employee_id'     => 'EMP716TEST',
            'first_name'      => 'Tina',
            'last_name'       => 'Rollins',
            'email'           => 'tina.rollins@example.invalid',
            'national_id'     => 'NID716TEST',
            'employee_type'   => 'officer',
            'employee_status' => 'active',
            'employment_type' => 'permanent',
            'hire_date'       => date('Y-m-d'),
            'gender'          => 'Female',
            'designation'     => 'Accounts Officer',
        ];
    }
}

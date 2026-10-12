<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\UserRepository;
use App\Services\EmployeeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the employee -> linked user sync
 * (EmployeeService::syncLinkedUserAccount).
 *
 * WHY THIS EXISTS
 *   users.role is the only value RBAC resolves. It was derived from
 *   employees.employee_type at CREATE time but never on UPDATE, so accounts
 *   edited through the Employees interface kept stale or empty roles - and an
 *   empty role denies every permission, producing the SPA's "Access denied,
 *   required: dashboard:view" on every page. These tests pin the three
 *   guarantees of the fix:
 *     1. shared identity columns are mirrored onto the users row,
 *     2. role follows employee_type changes and empty/junk roles are repaired,
 *     3. super_admin grants stay behind the session-actor escalation guard.
 *
 * The service is exercised through the real updateEmployee() entry point with
 * repository mocks - no database, no session login.
 */
final class EmployeeServiceUserSyncTest extends TestCase
{
    /** @var EmployeeRepositoryInterface&MockObject */
    private EmployeeRepositoryInterface $employeeRepository;

    /** @var UserRepository&MockObject */
    private UserRepository $userRepository;

    private EmployeeService $service;

    /** Drives the employees-update stub so a test can force a failed write. */
    private bool $employeeUpdateSucceeds = true;

    /** Pre-update employees row the service loads via findById(). */
    private const EXISTING_EMPLOYEE = [
        'id'              => 7,
        'employee_id'     => 'EMP001',
        'email'           => 'jane.doe@muwasco.org',
        'first_name'      => 'Jane',
        'last_name'       => 'Doe',
        'employee_type'   => 'officer',
        'employee_status' => 'active',
    ];

    /** Linked users row returned by findByEmployeeId()/findByEmail(). */
    private const LINKED_USER = [
        'id'          => 42,
        'employee_id' => 'EMP001',
        'email'       => 'jane.doe@muwasco.org',
        'role'        => 'officer',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->employeeRepository = $this->createMock(EmployeeRepositoryInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->employeeRepository->method('findById')->willReturn(self::EXISTING_EMPLOYEE);
        $this->employeeRepository->method('employeeIdExists')->willReturn(false);
        $this->employeeRepository->method('emailExists')->willReturn(false);
        $this->employeeRepository->method('nationalIdExists')->willReturn(false);
        // Flag-driven so a test can flip the outcome WITHOUT re-stubbing the
        // same method (PHPUnit 9 matchers are unreliable when configured twice).
        $this->employeeRepository->method('update')
            ->willReturnCallback(fn (): bool => $this->employeeUpdateSucceeds);

        $this->service = new EmployeeService($this->employeeRepository);
        $this->service->setUserRepository($this->userRepository);

        $_SESSION['user_id'] = 1;
        $_SESSION['user_role'] = 'hr_manager';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        parent::tearDown();
    }

    /**
     * A valid full-form payload (service-level validation requires these).
     * Overrides let each test change one dimension at a time.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'employee_id'     => 'EMP001',
            'email'           => 'jane.doe@muwasco.org',
            'national_id'     => '12345678',
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'employee_type'   => 'officer',
            'employee_status' => 'active',
            'hire_date'       => '2020-01-01',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // 1. Shared identity columns are mirrored onto the users row
    // ------------------------------------------------------------------

    public function testSharedColumnsAreMirroredToTheLinkedUser(): void
    {
        $this->userRepository->method('findByEmployeeId')->willReturn(self::LINKED_USER);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = ['id' => $id, 'data' => $data];
                return true;
            });

        $result = $this->service->updateEmployee(7, $this->payload([
            'email'       => 'jane.new@muwasco.org',
            'phone'       => '0712345678',
            'designation' => 'Accountant II',
            'address'     => 'Muranga',
        ]));

        $this->assertTrue($result);
        $this->assertIsArray($captured, 'users row must be updated');
        $this->assertSame(42, $captured['id']);
        $this->assertSame('jane.new@muwasco.org', $captured['data']['email']);
        $this->assertSame('0712345678', $captured['data']['phone']);
        $this->assertSame('Accountant II', $captured['data']['designation']);
        $this->assertSame('Muranga', $captured['data']['address']);

        // Never written from an employee edit: auth state + status.
        $this->assertArrayNotHasKey('password', $captured['data']);
        $this->assertArrayNotHasKey('is_active', $captured['data']);
        $this->assertArrayNotHasKey('session_token', $captured['data']);

        // employee_type unchanged and role already valid -> role untouched.
        $this->assertArrayNotHasKey('role', $captured['data']);
    }

    // ------------------------------------------------------------------
    // 2. Role follows employee_type; empty/junk roles are repaired
    // ------------------------------------------------------------------

    public function testRoleIsUpdatedWhenEmployeeTypeChanges(): void
    {
        $this->userRepository->method('findByEmployeeId')->willReturn(self::LINKED_USER);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        $this->service->updateEmployee(7, $this->payload(['employee_type' => 'dept_head']));

        $this->assertIsArray($captured);
        $this->assertSame('dept_head', $captured['role'] ?? null);
    }

    public function testEmptyRoleIsRepairedFromEmployeeTypeEvenWithoutATypeChange(): void
    {
        // The pre-sync failure mode: account exists but role was never set.
        $broken = array_merge(self::LINKED_USER, ['role' => '']);
        $this->userRepository->method('findByEmployeeId')->willReturn($broken);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        $this->service->updateEmployee(7, $this->payload(['phone' => '0700000000']));

        $this->assertIsArray($captured, 'a broken role must be repaired on any edit');
        $this->assertSame('officer', $captured['role'] ?? null);
        $this->assertSame('0700000000', $captured['phone'] ?? null);
    }

    public function testJunkLegacyRoleIsRepaired(): void
    {
        // 'hr' was the retired mapping target - not a catalog role, so RBAC
        // denies everything for it.
        $junk = array_merge(self::LINKED_USER, ['role' => 'hr']);
        $this->userRepository->method('findByEmployeeId')->willReturn($junk);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        $this->service->updateEmployee(7, $this->payload());

        $this->assertIsArray($captured);
        $this->assertSame('officer', $captured['role'] ?? null);
    }

    public function testOfficerEmployeeTypeMapsToOfficerRoleNotEmployee(): void
    {
        // Regression: officer/manager used to fall through the match() default
        // and silently downgrade the account to 'employee' on every sync.
        $broken = array_merge(self::LINKED_USER, ['role' => '']);
        $this->userRepository->method('findByEmployeeId')->willReturn($broken);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        $this->service->updateEmployee(7, $this->payload(['employee_type' => 'officer']));

        $this->assertSame('officer', $captured['role'] ?? null);
    }

    // ------------------------------------------------------------------
    // 3. Privilege-escalation guard on super_admin
    // ------------------------------------------------------------------

    public function testSuperAdminRoleSyncIsBlockedForANonSuperAdminActor(): void
    {
        $this->userRepository->method('findByEmployeeId')->willReturn(self::LINKED_USER);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        // Acting role comes from the SESSION (hr_manager from setUp).
        $this->service->updateEmployee(7, $this->payload(['employee_type' => 'super_admin']));

        $this->assertIsArray($captured, 'shared-column sync must still complete');
        $this->assertArrayNotHasKey(
            'role',
            $captured,
            'a non-super-admin actor must not be able to write super_admin via an employee edit'
        );
    }

    public function testSuperAdminRoleSyncSucceedsForASuperAdminActor(): void
    {
        $_SESSION['user_role'] = 'super_admin';
        $this->userRepository->method('findByEmployeeId')->willReturn(self::LINKED_USER);

        $captured = null;
        $this->userRepository
            ->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (int $id, array $data) use (&$captured): bool {
                $captured = $data;
                return true;
            });

        $this->service->updateEmployee(7, $this->payload(['employee_type' => 'super_admin']));

        $this->assertIsArray($captured);
        $this->assertSame('super_admin', $captured['role'] ?? null);
    }

    // ------------------------------------------------------------------
    // 4. No linked account / failed employee write
    // ------------------------------------------------------------------

    public function testNoLinkedUserMeansNoUsersWriteAndTheEmployeeUpdateStillSucceeds(): void
    {
        $this->userRepository->method('findByEmployeeId')->willReturn(null);
        $this->userRepository->method('findByEmail')->willReturn(null);

        $this->userRepository->expects($this->never())->method('update');

        $result = $this->service->updateEmployee(7, $this->payload());

        $this->assertTrue($result);
    }

    public function testFailedEmployeeWriteSkipsTheUserSync(): void
    {
        $this->employeeUpdateSucceeds = false;

        $this->userRepository->expects($this->never())->method('update');

        $result = $this->service->updateEmployee(7, $this->payload());

        $this->assertFalse($result);
    }
}

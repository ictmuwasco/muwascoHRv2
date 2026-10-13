<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;
use App\Services\EmployeeService;
use App\Services\PasswordResetService;
use PHPUnit\Framework\TestCase;

/**
 * Employee creation must be all-or-nothing (the transaction half of the
 * POST /employees "phantom 500" fix).
 *
 * WHY A REAL DATABASE
 *   The guarantee under test is "an employees row that existed at the moment
 *   of a later failure is GONE once createEmployee() throws". That is a
 *   property of MySQL transactions - a mock would only prove the mock. The
 *   real EmployeeRepository inserts for real; only the users INSERT is stubbed
 *   to fail deterministically (races and column quirks are not a reliable way
 *   to break a single INSERT on purpose).
 *
 * THE INCIDENT THIS PINS
 *   Before the fix, createEmployee() had no transaction: the employees INSERT
 *   committed immediately, then the welcome-email block blew up - the response
 *   died but the employee (and user) rows survived, so every retry got a 400
 *   "already exists" from validateEmployeeData(). With the transaction, the
 *   forced failure below rolls the hire back and a retry can re-create it.
 *
 * ISOLATION
 *   Runs against the application database (which owns the full production
 *   schema a scratch database would have to duplicate), with a unique
 *   employee_id marker per run; tearDown() defensively discards any open
 *   transaction and deletes the marker row, so nothing leaks even when the
 *   assertions fail.
 */
final class EmployeeServiceCreateTransactionTest extends TestCase
{
    /** Shared application connection - the very one the service transacts on. */
    private ?\mysqli $conn = null;

    private EmployeeService $service;

    /** Unique marker for the fixture row of this run. */
    private string $marker;

    /** employees.id captured FROM INSIDE the transaction, before the failure. */
    private int $insertedPk = 0;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->conn = \db()->getConnection();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No database connection available: ' . $e->getMessage());
        }

        $this->marker = 'TXN' . strtoupper(bin2hex(random_bytes(4)));

        // REAL repository: the employees INSERT must actually run (and be
        // actually rolled back) for this test to mean anything.
        $employeeRepository = new EmployeeRepository();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn(null);
        $userRepository->method('createUser')->willReturnCallback(function (): int {
            // Same session as the open transaction, so the UNCOMMITTED
            // employees row is visible here - proof the INSERT succeeded
            // before this failure, i.e. the rollback below has something
            // real to undo (a vacuous "row absent" assertion is impossible).
            $stmt = $this->conn->prepare('SELECT id FROM employees WHERE employee_id = ?');
            $stmt->bind_param('s', $this->marker);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $this->insertedPk = (int) ($row['id'] ?? 0);

            throw new \RuntimeException('simulated users INSERT failure');
        });

        $this->service = new EmployeeService($employeeRepository);
        $this->service->setUserRepository($userRepository);

        // The welcome mail fires post-commit only - a hire that never commits
        // must never be announced.
        $mailer = $this->createMock(PasswordResetService::class);
        $mailer->expects($this->never())->method('sendWelcomeEmail');
        $this->service->setPasswordResetService($mailer);
    }

    protected function tearDown(): void
    {
        if ($this->conn instanceof \mysqli) {
            // Defensive: a regressed service that stops rolling back would
            // leave an open transaction on this shared connection - close it
            // and sweep the marker row so nothing leaks into other tests.
            try {
                $this->conn->rollback();
            } catch (\Throwable) {
                // Nothing to discard.
            }
            try {
                $stmt = $this->conn->prepare('DELETE FROM employees WHERE employee_id = ?');
                $stmt->bind_param('s', $this->marker);
                $stmt->execute();
                $stmt->close();
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        }

        parent::tearDown();
    }

    public function testAFailureAfterTheEmployeesInsertRollsTheWholeHireBack(): void
    {
        try {
            $this->service->createEmployee($this->payload());
            $this->fail('the simulated users INSERT failure must propagate out of createEmployee()');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated users INSERT failure', $e->getMessage());
        }

        $this->assertGreaterThan(
            0,
            $this->insertedPk,
            'the employees INSERT must have succeeded inside the transaction before the failure'
        );

        // The whole point of the transaction: the row that demonstrably
        // existed moments ago must NOT survive the failure.
        $stmt = $this->conn->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->bind_param('i', $this->insertedPk);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull(
            $row,
            'the employees row must be rolled back when a later step of the same create fails'
        );
    }

    public function testASuccessfulCreateKeepsTheCommittedRowAndSendsTheMail(): void
    {
        // Success-path twin of setUp()'s failure stub: a fresh service pair
        // whose users INSERT succeeds and whose mailer IS expected to fire.
        $employeeRepository = new EmployeeRepository();

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn(null);
        $userRepository->method('createUser')->willReturnCallback(function (): int {
            $stmt = $this->conn->prepare('SELECT id FROM employees WHERE employee_id = ?');
            $stmt->bind_param('s', $this->marker);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $this->insertedPk = (int) ($row['id'] ?? 0);

            return 1; // fake users.id handed to the welcome mail
        });

        $mailer = $this->createMock(PasswordResetService::class);
        $mailer->expects($this->once())
            ->method('sendWelcomeEmail')
            ->with(
                $this->identicalTo(1),
                $this->identicalTo(strtolower($this->marker) . '.example@example.invalid'),
                $this->identicalTo('Tina')
            )
            ->willReturn(true);

        $service = new EmployeeService($employeeRepository);
        $service->setUserRepository($userRepository);
        $service->setPasswordResetService($mailer);

        $employeeId = $service->createEmployee($this->payload());

        $this->assertSame($this->insertedPk, $employeeId, 'createEmployee() returns the committed employees.id');

        $stmt = $this->conn->prepare('SELECT id FROM employees WHERE id = ?');
        $stmt->bind_param('i', $this->insertedPk);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, 'a successful create must commit the employees row');
    }

    /** Satisfies validateEmployeeData() and the employees NOT NULL columns. */
    private function payload(): array
    {
        return [
            'employee_id'     => $this->marker,
            'first_name'      => 'Tina',
            'last_name'       => 'Rollins',
            'email'           => strtolower($this->marker) . '.example@example.invalid',
            'national_id'     => $this->marker . 'NID',
            'employee_type'   => 'officer',
            'employee_status' => 'active',
            'employment_type' => 'permanent',
            'hire_date'       => date('Y-m-d'),
            'gender'          => 'Female',
            'designation'     => 'Accounts Officer',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Integration\Attendance;

use App\Services\Attendance\AttendanceDeviceLockService;
use App\Services\Attendance\DeviceLockedException;
use App\Helpers\Database;
use PHPUnit\Framework\TestCase;

/**
 * Proves the one-device/one-employee rule actually fires against the real
 * schema, through the same query path production uses.
 *
 * SAFETY: every mutation happens inside a transaction that is rolled back in
 * tearDown(). No row written by this test survives, so attendance data is left
 * exactly as found.
 *
 * Window is 24h by default: employee A locks the device, employee B is
 * refused, and the device frees up once A's lock ages out - which is what
 * keeps shared kiosk devices usable between shifts.
 */
final class DeviceLockEnforcementTest extends TestCase
{
    private static ?Database $db = null;
    private static string $schema = 'muwasco';

    /** @var list<int> */
    private array $tempEmployeeIds = [];

    private string $fingerprintA = '';
    private string $fingerprintB = '';

    public static function setUpBeforeClass(): void
    {
        self::$db = Database::getInstance();
        self::$schema = (string) (config('database.connections.mysql.database') ?: 'muwasco');
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::$db = Database::getInstance();
        }

        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '24';
        unset($_SERVER['ATTENDANCE_DEVICE_LOCK_HOURS']);
        putenv('ATTENDANCE_DEVICE_LOCK_HOURS');

        $this->fingerprintA = 'test-fingerprint-a-' . bin2hex(random_bytes(6));
        $this->fingerprintB = 'test-fingerprint-b-' . bin2hex(random_bytes(6));

        self::$db->beginTransaction();
    }

    protected function tearDown(): void
    {
        // Unconditional rollback: fixtures and any test row are discarded.
        try {
            self::$db?->rollback();
        } catch (\Throwable) {
            // Test outcome is already determined; nothing useful to do.
        }
        $this->tempEmployeeIds = [];

        unset($_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'], $_SERVER['ATTENDANCE_DEVICE_LOCK_HOURS']);
        putenv('ATTENDANCE_DEVICE_LOCK_HOURS');
    }

    /**
     * Insert two minimal employee rows (the real table has many NOT NULL
     * columns) so the lock query has two distinct owners to compare.
     *
     * @return array{0:int,1:int} [employeeIdA, employeeIdB]
     */
    private function seedTwoEmployees(): array
    {
        $stamp = bin2hex(random_bytes(4));
        $ids = [];

        for ($i = 0; $i < 2; $i++) {
            // Database::insert() returns the new row id.
            $ids[] = self::$db->insert('employees', [
                'employee_id'     => 'ZZTEST' . $stamp . $i,
                'first_name'      => 'DeviceLock',
                'last_name'       => 'Test' . $i,
                // employees has no `status` column; these four are the real
                // NOT NULL-without-default columns, so a fixture must set them.
                'gender'          => 'other',
                'national_id'     => random_int(100000000, 999999999),
                'employment_type' => 'full_time',
                'employee_type'   => 'staff',
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
        }

        $this->tempEmployeeIds = $ids;

        return [$ids[0], $ids[1]];
    }

    /** Simulate a clock-in that captured this device fingerprint. */
    private function recordClockIn(int $employeeId, string $fingerprint, string $clockInAt): int
    {
        return self::$db->insert('attendance', [
            'employee_id'        => $employeeId,
            'office_id'          => 1,
            'clock_in_office_id' => 1,
            'clock_in'           => $clockInAt,
            'device_fingerprint' => $fingerprint,
            'status'             => 'present',
            'is_late'            => 0,
            'created_at'         => $clockInAt,
            'updated_at'         => $clockInAt,
        ]);
    }
    public function testASecondEmployeeIsBlockedOnTheSameDevice(): void
    {
        [$employeeA, $employeeB] = $this->seedTwoEmployees();
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();

        $this->expectException(DeviceLockedException::class);
        $lock->assertDeviceAvailable($this->fingerprintA, $employeeB);
    }

    public function testTheSameEmployeeMayReuseTheirOwnDevice(): void
    {
        [$employeeA] = $this->seedTwoEmployees();
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();

        // Must NOT throw: clocking in and out repeatedly on your own device is
        // normal and must never be blocked.
        $result = $lock->assertDeviceAvailable($this->fingerprintA, $employeeA);

        $this->assertTrue($result['checked']);
        $this->assertSame($employeeA, $result['owner']);
    }

    public function testDeviceIsFreeForADifferentEmployeeAfterTheWindowExpires(): void
    {
        [$employeeA, $employeeB] = $this->seedTwoEmployees();

        // 25 hours ago: outside the 24h window.
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s', time() - (25 * 3600)));

        $lock = new AttendanceDeviceLockService();
        $result = $lock->assertDeviceAvailable($this->fingerprintA, $employeeB);

        // Shared-kiosk case: the previous shift's lock has aged out.
        $this->assertNull($result['owner']);
    }

    public function testDeviceIsStillLockedJustInsideTheWindow(): void
    {
        [$employeeA, $employeeB] = $this->seedTwoEmployees();

        // 23 hours ago: still inside the 24h window.
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s', time() - (23 * 3600)));

        $lock = new AttendanceDeviceLockService();

        $this->expectException(DeviceLockedException::class);
        $lock->assertDeviceAvailable($this->fingerprintA, $employeeB);
    }

    public function testDifferentDevicesDoNotConflict(): void
    {
        [, $employeeB] = $this->seedTwoEmployees();
        $this->recordClockIn($this->tempEmployeeIds[0], $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();
        $result = $lock->assertDeviceAvailable($this->fingerprintB, $employeeB);

        $this->assertNull($result['owner']);
    }

    public function testLockIsSkippedWhenTheFingerprintIsEmpty(): void
    {
        [, $employeeB] = $this->seedTwoEmployees();

        $lock = new AttendanceDeviceLockService();
        $result = $lock->assertDeviceAvailable('', $employeeB);

        $this->assertFalse($result['checked'], 'no fingerprint means the lock cannot apply');
    }

    public function testDisablingTheWindowAllowsSharedDevices(): void
    {
        [$employeeA, $employeeB] = $this->seedTwoEmployees();
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s'));

        // Operator escape hatch: ATTENDANCE_DEVICE_LOCK_HOURS=0.
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '0';

        $lock = new AttendanceDeviceLockService();

        $this->assertFalse($lock->isEnabled());
        // Must not throw even though A owns the device right now.
        $lock->assertDeviceAvailable($this->fingerprintA, $employeeB);
        $this->addToAssertionCount(1);
    }

    public function testAnUnknownFingerprintMatchesNothing(): void
    {
        // REGRESSION: the lock query originally bound its parameters as "iii",
        // which cast the device fingerprint to the INTEGER 0. MySQL then
        // compared device_fingerprint = 0 and happily returned somebody else's
        // row, so employees with no fingerprint history were denied clock-in
        // over a device they had never used. The fingerprint must be compared
        // as a string and must match nothing when absent.
        $this->seedTwoEmployees();
        $this->recordClockIn($this->tempEmployeeIds[0], $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();
        $result = $lock->assertDeviceAvailable('no-such-device-anywhere', $this->tempEmployeeIds[1]);

        $this->assertNull($result['owner']);
    }

    public function testTheLockIsScopedToTheExactFingerprint(): void
    {
        // Guards against a substring/prefix match, which would let a device
        // id that merely starts with an existing one inherit its lock.
        [$employeeA, $employeeB] = $this->seedTwoEmployees();
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();

        $this->assertNull($lock->assertDeviceAvailable($this->fingerprintA . 'x', $employeeB)['owner']);
    }

    public function testDenialCarriesClientSafeContext(): void
    {
        [$employeeA, $employeeB] = $this->seedTwoEmployees();
        $this->recordClockIn($employeeA, $this->fingerprintA, date('Y-m-d H:i:s'));

        $lock = new AttendanceDeviceLockService();

        try {
            $lock->assertDeviceAvailable($this->fingerprintA, $employeeB);
            $this->fail('Expected DeviceLockedException was not thrown.');
        } catch (DeviceLockedException $e) {
            $context = $e->context();

            $this->assertSame('DEVICE_LOCKED', $context['code']);
            $this->assertSame(24, $context['window_hours']);
            $this->assertNotEmpty($context['unlocks_at']);

            // Privacy: never leak the device identifier to the client.
            $this->assertStringNotContainsString($this->fingerprintA, $e->getMessage());
            $this->assertStringNotContainsString($this->fingerprintA, (string) json_encode($context));
        }
    }
}


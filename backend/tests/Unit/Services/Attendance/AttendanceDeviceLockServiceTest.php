<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attendance;

use App\Services\Attendance\AttendanceDeviceLockService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the pure, database-free parts of the device lock:
 * window parsing/clamping and privacy-safe fingerprinting.
 *
 * The authoritative DB behaviour (who owns a device) is covered by
 * DeviceLockEnforcementTest, which runs against a real scratch table.
 */
final class AttendanceDeviceLockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // env() reads $_ENV/$_SERVER/getenv; each test sets and restores it.
        unset($_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'], $_SERVER['ATTENDANCE_DEVICE_LOCK_HOURS']);
        putenv('ATTENDANCE_DEVICE_LOCK_HOURS');
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        putenv('APP_KEY');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'], $_SERVER['ATTENDANCE_DEVICE_LOCK_HOURS']);
        putenv('ATTENDANCE_DEVICE_LOCK_HOURS');
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        putenv('APP_KEY');
    }

    private function service(): AttendanceDeviceLockService
    {
        return new AttendanceDeviceLockService();
    }

    public function testDefaultWindowIsTwentyFourHours(): void
    {
        // The agreed policy: shared kiosks work between shifts, one employee
        // per device within a day.
        $this->assertSame(24, $this->service()->windowHours());
        $this->assertTrue($this->service()->isEnabled());
    }

    public function testWindowIsConfigurable(): void
    {
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '8';
        $this->assertSame(8, $this->service()->windowHours());
    }

    public function testZeroHoursDisablesTheLock(): void
    {
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '0';
        $this->assertSame(0, $this->service()->windowHours());
        $this->assertFalse($this->service()->isEnabled());
    }

    public function testNegativeWindowDisablesRatherThanLockingForever(): void
    {
        // A negative window would otherwise lock every device permanently.
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '-5';
        $this->assertSame(0, $this->service()->windowHours());
    }

    public function testAbsurdWindowIsClampedToThirtyDays(): void
    {
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = '999999';
        $this->assertSame(720, $this->service()->windowHours());
    }

    public function testNonNumericWindowFallsBackToDefault(): void
    {
        $_ENV['ATTENDANCE_DEVICE_LOCK_HOURS'] = 'soon';
        $this->assertSame(24, $this->service()->windowHours());
    }

    public function testFingerprintIsStableForTheSameInput(): void
    {
        $_ENV['APP_KEY'] = 'test-key';
        $svc = $this->service();

        $this->assertSame($svc->fingerprint('device-abc'), $svc->fingerprint('device-abc'));
    }

    public function testDifferentDevicesProduceDifferentFingerprints(): void
    {
        $_ENV['APP_KEY'] = 'test-key';
        $svc = $this->service();

        $this->assertNotSame($svc->fingerprint('device-abc'), $svc->fingerprint('device-xyz'));
    }

    public function testFingerprintDoesNotLeakTheRawIdentifier(): void
    {
        $_ENV['APP_KEY'] = 'test-key';
        $raw = 'super-secret-device-identifier';

        $fingerprint = $this->service()->fingerprint($raw);

        $this->assertIsString($fingerprint);
        $this->assertNotSame($raw, $fingerprint);
        $this->assertStringNotContainsString($raw, $fingerprint);
        $this->assertSame(64, strlen($fingerprint), 'expected a hex sha256 digest');
    }

    public function testFingerprintIsKeyedByAppKey(): void
    {
        // Two installs sharing a device must not produce matching digests,
        // otherwise dumps from both could be correlated.
        $_ENV['APP_KEY'] = 'key-one';
        $first = $this->service()->fingerprint('device-abc');

        $_ENV['APP_KEY'] = 'key-two';
        $second = $this->service()->fingerprint('device-abc');

        $this->assertNotSame($first, $second);
    }

    public function testBlankDeviceIdYieldsNullSoTheLockIsSkipped(): void
    {
        $_ENV['APP_KEY'] = 'test-key';
        $svc = $this->service();

        // Must NOT hash empty input into a shared bucket: every client without
        // a device id would be locked to whoever clocked in first.
        $this->assertNull($svc->fingerprint(null));
        $this->assertNull($svc->fingerprint(''));
        $this->assertNull($svc->fingerprint('   '));
    }

    public function testOversizedDeviceIdIsRejected(): void
    {
        $_ENV['APP_KEY'] = 'test-key';

        $this->assertNull($this->service()->fingerprint(str_repeat('a', 256)));
    }

    public function testFingerprintWorksWithoutAppKeyConfigured(): void
    {
        // Must not fatal on a misconfigured install; degrades to a plain digest.
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        putenv('APP_KEY');

        $fingerprint = $this->service()->fingerprint('device-abc');

        $this->assertIsString($fingerprint);
        $this->assertSame(64, strlen($fingerprint));
    }
}

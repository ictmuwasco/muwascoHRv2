<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attendance;

use PHPUnit\Framework\TestCase;
use App\Services\Attendance\AttendanceClockService;
use App\Services\Attendance\InvalidClockRequestException;

/**
 * Unit tests for the pure attendance clock rules extracted in Phase 5
 * (Services\Attendance\AttendanceClockService). No database required.
 *
 * @covers \App\Services\Attendance\AttendanceClockService
 */
final class AttendanceClockRulesTest extends TestCase
{
    private AttendanceClockService $service;

    protected function setUp(): void
    {
        $this->service = new AttendanceClockService();
    }

    // ---------------------------------------------------- request validation

    public function testMissingOfficeIsRejected(): void
    {
        $this->assertSame('Office is required', $this->service->validateClockRequest([]));
    }

    public function testZeroOfficeIsRejected(): void
    {
        $this->assertSame(
            'Office is required',
            $this->service->validateClockRequest(['office_id' => 0, 'latitude' => -1.0, 'longitude' => 37.0])
        );
    }

    public function testCoordinatesAreRequiredByDefault(): void
    {
        $error = $this->service->validateClockRequest(['office_id' => 1, 'location_status' => 'gps']);
        $this->assertSame('Location is required. Please enable location services.', $error);
    }

    public function testInvalidCoordinatesAreRejected(): void
    {
        $error = $this->service->validateClockRequest([
            'office_id' => 1,
            'latitude' => 999.0,
            'longitude' => 37.0,
        ]);
        $this->assertSame('Invalid GPS coordinates received', $error);
    }

    public function testNullIslandIsRejected(): void
    {
        $error = $this->service->validateClockRequest([
            'office_id' => 1,
            'latitude' => '0',
            'longitude' => '0',
        ]);
        $this->assertSame('Could not determine your location. Please check your GPS settings.', $error);
    }

    public function testValidRequestPasses(): void
    {
        $error = $this->service->validateClockRequest([
            'office_id' => 1,
            'latitude' => -0.72809798,
            'longitude' => 37.15159988,
        ]);
        $this->assertNull($error);
    }

    // -------------------------------------------------------- accuracy cap

    public function testAccuracyAboveCapThrows(): void
    {
        $this->expectException(InvalidClockRequestException::class);
        $this->expectExceptionMessage('GPS accuracy too low');
        $this->service->assertUsableAccuracy(6000.0);
    }

    public function testAccuracyWithinCapPasses(): void
    {
        $this->service->assertUsableAccuracy(25.0);
        $this->service->assertUsableAccuracy((float) AttendanceClockService::MAX_ACCURACY_METERS);
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------ geofence

    public function testNoFixDeclarationPassesGeofence(): void
    {
        $office = ['latitude' => -0.72809798, 'longitude' => 37.15159988, 'geo_fence_radius' => 100];
        $geo = $this->service->evaluateGeofence($office, [
            'has_coordinates' => false, 'latitude' => null, 'longitude' => null, 'accuracy' => null,
        ]);

        $this->assertTrue($geo['within']);
        $this->assertFalse($geo['has_coordinates']);
        $this->assertNull($geo['distance']);
    }

    public function testInsideRadiusPassesGeofence(): void
    {
        $office = ['latitude' => -0.72809798, 'longitude' => 37.15159988, 'geo_fence_radius' => 100];
        // ~55 m north of the office
        $geoInput = $this->service->geolocationInput([
            'latitude' => -0.72809798 + 0.0005,
            'longitude' => 37.15159988,
            'accuracy' => 10.0,
        ]);
        $geo = $this->service->evaluateGeofence($office, $geoInput);

        $this->assertTrue($geo['within'], 'Distance was: ' . var_export($geo['distance'], true));
        $this->assertSame(100.0, $geo['allowed_radius']);
        $this->assertTrue($geo['has_coordinates']);
    }

    public function testOutsideRadiusFailsGeofence(): void
    {
        $office = ['latitude' => -0.72809798, 'longitude' => 37.15159988, 'geo_fence_radius' => 100];
        // ~556 m north of the office
        $geoInput = $this->service->geolocationInput([
            'latitude' => -0.72809798 + 0.005,
            'longitude' => 37.15159988,
            'accuracy' => 10.0,
        ]);
        $geo = $this->service->evaluateGeofence($office, $geoInput);

        $this->assertFalse($geo['within']);
        $this->assertGreaterThan(100.0, (float) $geo['distance']);
    }

    public function testRadiusFallsBackToDefaultWhenOfficeHasNone(): void
    {
        $office = ['latitude' => -0.72809798, 'longitude' => 37.15159988];
        $geoInput = $this->service->geolocationInput([
            'latitude' => -0.72809798,
            'longitude' => 37.15159988,
            'accuracy' => 5.0,
        ]);
        $geo = $this->service->evaluateGeofence($office, $geoInput);

        $this->assertSame((float) AttendanceClockService::DEFAULT_RADIUS_METERS, $geo['allowed_radius']);
    }

    // --------------------------------------------------------- late status

    public function testExactlyAtCutoffIsNotLate(): void
    {
        $result = $this->service->resolveLateStatus('2026-09-01 08:30:00');
        $this->assertFalse($result['is_late']);
        $this->assertSame('clocked_in', $result['status']);
    }

    public function testOneMinuteAfterCutoffIsLate(): void
    {
        $result = $this->service->resolveLateStatus('2026-09-01 08:31:00');
        $this->assertTrue($result['is_late']);
        $this->assertSame('late', $result['status']);
    }

    public function testEarlyArrivalIsNotLate(): void
    {
        $result = $this->service->resolveLateStatus('2026-09-01 07:15:00');
        $this->assertFalse($result['is_late']);
    }

    public function testWellAfterCutoffIsLate(): void
    {
        $result = $this->service->resolveLateStatus('2026-09-01 09:00:00');
        $this->assertTrue($result['is_late']);
    }

    // ------------------------------------------------- approved-leave switch

    public function testClockInOnLeaveIsBlockedByDefault(): void
    {
        $this->assertTrue($this->service->blockClockInOnLeave());
    }
}

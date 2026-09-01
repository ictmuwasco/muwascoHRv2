<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Helpers\GeoLocation;
use App\Repositories\Contracts\OfficeRepositoryInterface;
use App\Repositories\OfficeRepository;
use App\Services\AuditService;
use App\Services\CalendarContextService;

/**
 * AttendanceClockService — the authoritative clock-in / clock-out business
 * logic for the attendance domain (Phase 5 §9-§11).
 *
 * Extracted verbatim from AttendanceController: the HTTP layer now only
 * resolves the authenticated employee, shapes the request context and maps
 * outcomes/exceptions to responses. Every rule below is enforced server-side
 * and never trusts frontend-supplied times, statuses or roles:
 *
 *  - Request shape validation (office required; coordinates required unless
 *    an explicit no-fix declaration is allowed by configuration)
 *  - GPS accuracy cap (MAX_ACCURACY_METERS)
 *  - Office resolution + configured geofence radius (Haversine via GeoLocation)
 *  - Duplicate clock-in prevention: pre-check SELECT for sequential retries +
 *    the unique key uk_attendance_employee_date (migration 020) as the
 *    authoritative guard for concurrent inserts — both answered idempotently
 *  - Clock-out only against today's record; double clock-out is idempotent;
 *    clocking out without an open session is rejected (NOT_CLOCKED_IN)
 *  - Late determination by configured cutoff (ATTENDANCE_LATE_CUTOFF, 08:30
 *    Africa/Nairobi default, preserved from the original implementation)
 *  - Optional hard block on clocking in during approved leave
 *    (ATTENDANCE_BLOCK_CLOCKIN_ON_LEAVE, default enabled — Phase 5 §10)
 *  - Audit trail entries for every outcome (success / denied / failed / retry)
 *
 * All timestamps are server-authoritative (DB session runs at Africa/Nairobi).
 * Frontend-sent times are never stored.
 */
class AttendanceClockService
{
    /** Max accepted GPS accuracy in meters — above this the fix is unusable. */
    public const MAX_ACCURACY_METERS = 5000;

    /** Office geofence fallback radius (meters) when the office row has none. */
    public const DEFAULT_RADIUS_METERS = 100;

    /** Default late cutoff (Africa/Nairobi). */
    public const LATE_CUTOFF_DEFAULT = '08:30';

    private OfficeRepositoryInterface $officeRepository;
    private CalendarContextService $calendar;

    public function __construct(
        ?OfficeRepositoryInterface $officeRepository = null,
        ?CalendarContextService $calendar = null
    ) {
        $this->officeRepository = $officeRepository ?? new OfficeRepository();
        $this->calendar = $calendar ?? new CalendarContextService();
    }

    // =====================================================================
    // Clock-in
    // =====================================================================

    /**
     * Record a clock-in for the given employee.
     *
     * @param int   $employeeDbId employees.id (PK), resolved from the
     *                            authenticated session — never from the body.
     * @param array $request     {office_id:int, latitude:?float, longitude:?float,
     *                            accuracy:?float, location_status:string,
     *                            ip_address:?string, channel:string}
     * @return array{outcome:'created'|'idempotent', payload:array<string,mixed>}
     *
     * @throws InvalidClockRequestException request shape / location unusable (HTTP 400)
     * @throws InvalidOfficeException       unknown office or unusable office coordinates (HTTP 400)
     * @throws OutsideGeofenceException     fix outside the office radius (HTTP 403, OUTSIDE_RADIUS)
     * @throws OnApprovedLeaveException     blocking enabled + approved leave today (HTTP 409, ON_APPROVED_LEAVE)
     * @throws \RuntimeException            persistence failure (HTTP 500)
     */
    public function clockIn(int $employeeDbId, array $request): array
    {
        $validationError = $this->validateClockRequest($request);
        if ($validationError !== null) {
            throw new InvalidClockRequestException($validationError);
        }

        $geoInput = $this->geolocationInput($request);
        if ($geoInput['has_coordinates']) {
            $this->assertUsableAccuracy((float) $geoInput['accuracy']);
        }

        $office = $this->resolveOffice((int) $request['office_id']);
        $geo = $this->evaluateGeofence($office, $geoInput);
        if (!$geo['within']) {
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_IN,
                AuditService::STATUS_DENIED,
                'Clock-in denied: outside geofence radius',
                ['metadata' => ['distance' => $geo['distance']], 'target_type' => 'Attendance']
            );
            throw new OutsideGeofenceException(
                'You are about ' . GeoLocation::formatDistanceMeters((float) $geo['distance'])
                . ' from the office. You must be within '
                . GeoLocation::formatDistanceMeters($geo['allowed_radius'])
                . ' of the office to clock in. Please move closer and try again.',
                ['distance' => $geo['distance'], 'allowed_radius' => $geo['allowed_radius']]
            );
        }

        $today = date('Y-m-d');

        // Idempotency: a pre-check SELECT handles sequential double-clicks;
        // the unique key uk_attendance_employee_date is the authoritative
        // guard against true concurrent inserts (migration 020).
        $existing = $this->findTodayRecord($employeeDbId, $today);
        if ($existing) {
            return [
                'outcome' => 'idempotent',
                'payload' => [
                    'success' => true,
                    'message' => 'You have already clocked in today.',
                    'clock_in' => $existing['clock_in'] ?? null,
                    'is_late' => (bool) ($existing['is_late'] ?? 0),
                    'distance' => $geo['distance'],
                    'record' => $existing,
                    'idempotent' => true,
                ],
            ];
        }

        // Phase 5 §10: employees on approved leave must not clock in.
        if ($this->blockClockInOnLeave()) {
            $leaveType = $this->approvedLeaveTypeFor($employeeDbId, $today);
            if ($leaveType !== null) {
                $this->audit(
                    $request,
                    $office,
                    $geo,
                    AuditService::ACTION_CLOCK_IN,
                    AuditService::STATUS_DENIED,
                    'Clock-in denied: employee on approved leave today',
                    [
                        'target_type' => 'Attendance',
                        'metadata' => ['code' => 'ON_APPROVED_LEAVE', 'leave_type' => $leaveType],
                    ]
                );
                throw new OnApprovedLeaveException(
                    'You are on approved leave today (' . $leaveType . '). Clock-in is not allowed while on leave.',
                    ['code' => 'ON_APPROVED_LEAVE', 'leave_type' => $leaveType]
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        $late = $this->resolveLateStatus($now);

        $inTransaction = false;
        try {
            $db = \db();
            $db->beginTransaction();
            $inTransaction = true;

            $record = [
                'employee_id' => $employeeDbId,
                'office_id' => (int) $request['office_id'],
                'clock_in_office_id' => (int) $request['office_id'],
                'clock_in' => $now,
                'ip_address' => $request['ip_address'] ?? null,
                'status' => $late['status'],
                'is_late' => $late['is_late'] ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($geo['has_coordinates']) {
                $record['lat'] = $geoInput['latitude'];
                $record['lng'] = $geoInput['longitude'];
                $record['accuracy'] = $geoInput['accuracy'];
            }

            $attendanceId = $this->insertClockIn($record);
            $db->commit();
            $inTransaction = false;

            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_IN,
                AuditService::STATUS_SUCCESS,
                'Employee clock-in recorded',
                ['target_type' => 'Attendance', 'target_id' => (int) $attendanceId]
            );

            return [
                'outcome' => 'created',
                'payload' => [
                    'success' => true,
                    'message' => ($late['is_late'] ? 'Clocked in (LATE ARRIVAL)' : 'Clock In successful.')
                        . ($geo['has_coordinates'] ? '' : ' (location not verified)'),
                    'is_late' => $late['is_late'],
                    'clock_in' => $now,
                    'clock_in_id' => $attendanceId,
                    'distance' => $geo['distance'],
                ],
            ];
        } catch (\mysqli_sql_exception $e) {
            if ($inTransaction) {
                $this->safeRollback();
            }
            // 1062 / SQLSTATE 23000 = duplicate key -> a concurrent request won the race.
            if ((int) ($e->getCode() ?? 0) === 1062 || (string) $e->sqlstate === '23000') {
                $ref = $this->findTodayRecordWithOffice($employeeDbId, $today);
                $this->audit(
                    $request,
                    $office,
                    $geo,
                    AuditService::ACTION_CLOCK_IN,
                    AuditService::STATUS_SUCCESS,
                    'Clock-in idempotency retry (already clocked in today)',
                    [
                        'target_type' => 'Attendance',
                        'target_id' => isset($ref['id']) ? (int) $ref['id'] : null,
                        'metadata' => ['idempotent' => true],
                    ]
                );
                return [
                    'outcome' => 'idempotent',
                    'payload' => [
                        'success' => true,
                        'message' => 'You have already clocked in today.',
                        'clock_in' => $ref['clock_in'] ?? $now,
                        'is_late' => (bool) ($ref['is_late'] ?? ($late['is_late'] ? 1 : 0)),
                        'distance' => $geo['distance'],
                        'record' => $ref,
                        'idempotent' => true,
                    ],
                ];
            }
            \logger()->error('Clock-in failed', [
                'employee_id' => $employeeDbId,
                'error' => $e->getMessage(),
            ]);
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_IN,
                AuditService::STATUS_FAILED,
                'Clock-in failed: ' . $e->getMessage(),
                ['target_type' => 'Attendance', 'metadata' => ['error' => $e->getMessage()]]
            );
            throw new \RuntimeException('Failed to record your clock-in. Please try again.');
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->safeRollback();
            }
            \logger()->error('Clock-in failed', [
                'employee_id' => $employeeDbId,
                'error' => $e->getMessage(),
            ]);
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_IN,
                AuditService::STATUS_FAILED,
                'Clock-in failed: ' . $e->getMessage(),
                ['target_type' => 'Attendance', 'metadata' => ['error' => $e->getMessage()]]
            );
            throw new \RuntimeException('Failed to record your clock-in. Please try again.');
        }
    }

    // =====================================================================
    // Clock-out
    // =====================================================================

    /**
     * Record a clock-out for the given employee (today's open session only).
     *
     * @param int   $employeeDbId employees.id (PK) from the authenticated session.
     * @param array $request     same shape as clockIn().
     * @return array{outcome:'created'|'idempotent', payload:array<string,mixed>}
     *
     * @throws InvalidClockRequestException request shape / location unusable (HTTP 400)
     * @throws InvalidOfficeException       unknown office or unusable office coordinates (HTTP 400)
     * @throws OutsideGeofenceException     fix outside the office radius (HTTP 403, OUTSIDE_RADIUS)
     * @throws NoOpenSessionException       no clock-in today (HTTP 400, NOT_CLOCKED_IN)
     * @throws \RuntimeException            persistence failure (HTTP 500)
     */
    public function clockOut(int $employeeDbId, array $request): array
    {
        $validationError = $this->validateClockRequest($request);
        if ($validationError !== null) {
            throw new InvalidClockRequestException($validationError);
        }

        $geoInput = $this->geolocationInput($request);
        if ($geoInput['has_coordinates']) {
            $this->assertUsableAccuracy((float) $geoInput['accuracy']);
        }

        $office = $this->resolveOffice((int) $request['office_id']);
        $geo = $this->evaluateGeofence($office, $geoInput);
        if (!$geo['within']) {
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_OUT,
                AuditService::STATUS_DENIED,
                'Clock-out denied: outside geofence radius',
                ['metadata' => ['distance' => $geo['distance']], 'target_type' => 'Attendance']
            );
            throw new OutsideGeofenceException(
                'You are about ' . GeoLocation::formatDistanceMeters((float) $geo['distance'])
                . ' from the office. You must be within '
                . GeoLocation::formatDistanceMeters($geo['allowed_radius'])
                . ' of the office to clock out. Please move closer and try again.',
                ['distance' => $geo['distance'], 'allowed_radius' => $geo['allowed_radius']]
            );
        }

        // ---- Get today's open session (clock out is valid for today only) ----
        $today = date('Y-m-d');
        $session = $this->findTodaySession($employeeDbId, $today);

        if (!$session) {
            // No clock-in today (either absent, or yesterday's session was
            // reconciled overnight) -> cannot clock out.
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_OUT,
                AuditService::STATUS_DENIED,
                'No active clock-in found for today. Please clock in first.',
                ['target_type' => 'Attendance', 'metadata' => ['code' => 'NOT_CLOCKED_IN']]
            );
            throw new NoOpenSessionException(
                'No active clock-in found for today. Please clock in first.',
                ['code' => 'NOT_CLOCKED_IN']
            );
        }

        // Idempotency: already clocked out earlier today (e.g. double-click).
        if (!empty($session['clock_out']) || (string) $session['status'] === 'clocked_out') {
            $this->audit(
                $request,
                $office,
                $geo,
                AuditService::ACTION_CLOCK_OUT,
                AuditService::STATUS_SUCCESS,
                'Clock-out idempotent retry (already clocked out today)',
                [
                    'target_type' => 'Attendance',
                    'target_id' => (int) $session['id'],
                    'metadata' => ['idempotent' => true],
                ]
            );
            return [
                'outcome' => 'idempotent',
                'payload' => [
                    'success' => true,
                    'message' => 'You have already clocked out today.',
                    'clock_out' => $session['clock_out'],
                    'distance' => $geo['distance'],
                    'idempotent' => true,
                ],
            ];
        }

        // ---- Update with clock out (ONLY after validation passes) ----
        $now = date('Y-m-d H:i:s');
        $this->applyClockOut(
            (int) $session['id'],
            $now,
            (int) $request['office_id'],
            $request['ip_address'] ?? null
        );

        $this->audit(
            $request,
            $office,
            $geo,
            AuditService::ACTION_CLOCK_OUT,
            AuditService::STATUS_SUCCESS,
            'Employee clock-out recorded',
            ['target_type' => 'Attendance', 'target_id' => (int) $session['id']]
        );

        return [
            'outcome' => 'created',
            'payload' => [
                'success' => true,
                'message' => 'Clock Out successful.' . ($geo['has_coordinates'] ? '' : ' (location not verified)'),
                'clock_out' => $now,
                'distance' => $geo['distance'],
            ],
        ];
    }

    // =====================================================================
    // Shared business rules (pure, unit-testable)
    // =====================================================================

    /**
     * Validate clock-in/clock-out request shape.
     *
     * @param array $data Request payload.
     * @return string|null Error message, or null if valid.
     */
    public function validateClockRequest(array $data): ?string
    {
        if (empty($data['office_id']) || (int) $data['office_id'] <= 0) {
            return 'Office is required';
        }

        // A device may declare it has no fix at all (desktop PC without
        // GPS/Wi-Fi). When the organisation allows it, coordinates become
        // optional and the record is stored unverified (lat/lng NULL).
        $unverifiedAllowed = (($data['location_status'] ?? '') === 'unavailable')
            && env('ATTENDANCE_ALLOW_UNVERIFIED_LOCATION', false);
        if ($unverifiedAllowed) {
            return null;
        }

        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            return 'Location is required. Please enable location services.';
        }

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        if (!GeoLocation::isValidCoordinate($latitude, $longitude)) {
            return 'Invalid GPS coordinates received';
        }

        if ($latitude === 0.0 && $longitude === 0.0) {
            return 'Could not determine your location. Please check your GPS settings.';
        }

        return null;
    }

    /**
     * Normalise the location part of the request.
     *
     * @return array{has_coordinates:bool, latitude:?float, longitude:?float, accuracy:?float}
     */
    public function geolocationInput(array $request): array
    {
        $hasCoordinates = isset($request['latitude'], $request['longitude'])
            && $request['latitude'] !== ''
            && $request['longitude'] !== '';

        return [
            'has_coordinates' => $hasCoordinates,
            'latitude' => $hasCoordinates ? (float) $request['latitude'] : null,
            'longitude' => $hasCoordinates ? (float) $request['longitude'] : null,
            'accuracy' => $hasCoordinates ? (float) ($request['accuracy'] ?? 0) : null,
        ];
    }

    /**
     * Reject unusable GPS accuracy before any office resolution happens.
     */
    public function assertUsableAccuracy(float $accuracy): void
    {
        if ($accuracy > self::MAX_ACCURACY_METERS) {
            throw new InvalidClockRequestException(
                "GPS accuracy too low ({$accuracy}m). Please move to an open area and try again."
            );
        }
    }

    /**
     * Resolve and validate the selected office (must exist and carry usable
     * coordinates for geofence checks).
     *
     * @return array<string,mixed> The office row.
     * @throws InvalidOfficeException
     */
    public function resolveOffice(int $officeId): array
    {
        $office = $this->officeRepository->findById($officeId);
        if (!$office) {
            throw new InvalidOfficeException('Invalid office selected');
        }

        if (!GeoLocation::isValidCoordinate((float) $office['latitude'], (float) $office['longitude'])) {
            throw new InvalidOfficeException('Selected office has no valid GPS coordinates configured');
        }

        return $office;
    }

    /**
     * Evaluate the geofence for a resolved office + normalised location input.
     * Declared no-fix requests always pass (verified=false is recorded).
     *
     * @param array<string,mixed> $office
     * @param array{has_coordinates:bool, latitude:?float, longitude:?float, accuracy:?float} $geoInput
     * @return array{within:bool, distance:float|null, allowed_radius:float, has_coordinates:bool}
     */
    public function evaluateGeofence(array $office, array $geoInput): array
    {
        $allowedRadius = (float) ($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS);

        if (!$geoInput['has_coordinates']) {
            return ['within' => true, 'distance' => null, 'allowed_radius' => $allowedRadius, 'has_coordinates' => false];
        }

        $radiusCheck = GeoLocation::isWithinRadius(
            (float) $geoInput['latitude'],
            (float) $geoInput['longitude'],
            (float) $office['latitude'],
            (float) $office['longitude'],
            $allowedRadius,
            (float) $geoInput['accuracy']
        );

        return [
            'within' => (bool) $radiusCheck['within'],
            'distance' => $radiusCheck['distance'],
            'allowed_radius' => $allowedRadius,
            'has_coordinates' => true,
        ];
    }

    /**
     * Determine the late flag + clock-in status for "now" against the
     * configured cutoff (default 08:30 Africa/Nairobi — original rule).
     *
     * @return array{is_late:bool, status:'late'|'clocked_in'}
     */
    public function resolveLateStatus(?string $now = null): array
    {
        $cutoffRaw = (string) env('ATTENDANCE_LATE_CUTOFF', self::LATE_CUTOFF_DEFAULT);
        $cutoff = \DateTime::createFromFormat('H:i', $cutoffRaw)
            ?: \DateTime::createFromFormat('H:i', self::LATE_CUTOFF_DEFAULT);

        $nowTime = $now !== null
            ? (\DateTime::createFromFormat('Y-m-d H:i:s', $now) ?: new \DateTime('now'))
            : new \DateTime('now');

        $isLate = ((int) $nowTime->format('H') > (int) $cutoff->format('H'))
            || ((int) $nowTime->format('H') === (int) $cutoff->format('H')
                && (int) $nowTime->format('i') > (int) $cutoff->format('i'));

        return ['is_late' => $isLate, 'status' => $isLate ? 'late' : 'clocked_in'];
    }

    /**
     * Phase 5 §10 switch: block clock-in while on approved leave.
     * Default ENABLED; set ATTENDANCE_BLOCK_CLOCKIN_ON_LEAVE=false to revert
     * to the pre-Phase-5 behaviour (clock-in allowed, dashboard still shows
     * the employee as ON_LEAVE).
     */
    public function blockClockInOnLeave(): bool
    {
        return (bool) env('ATTENDANCE_BLOCK_CLOCKIN_ON_LEAVE', true);
    }

    /**
     * The approved leave type covering the employee on the given date, or null.
     * Delegates to the single shared calendar implementation
     * (CalendarContextService) — attendance never re-implements leave rules.
     */
    protected function approvedLeaveTypeFor(int $employeeDbId, string $date): ?string
    {
        $leaves = $this->calendar->getApprovedLeavesOnDate($date);
        return $leaves[$employeeDbId] ?? null;
    }

    /**
     * Attendance-scoped audit entry via the central AuditService. Mirrors the
     * context the controller used to capture (actor resolved by AuditService
     * from the session; IP/channel/office/location supplied by the request).
     */
    private function audit(
        array $request,
        array $office,
        array $geo,
        string $action,
        string $status,
        string $description,
        array $extra = []
    ): void {
        AuditService::getInstance()->log(
            AuditService::MODULE_ATTENDANCE,
            $action,
            $description,
            array_merge([
                'status' => $status,
                'employee_id' => $request['employee_db_id'] ?? null,
                'office_id' => (int) ($request['office_id'] ?? 0) ?: null,
                'office_name' => $office['name'] ?? null,
                'latitude' => $geo['has_coordinates'] ? $geo['latitude'] : null,
                'longitude' => $geo['has_coordinates'] ? $geo['longitude'] : null,
                'location_source' => $geo['has_coordinates'] ? 'GPS' : 'UNVERIFIED',
                'ip_address' => $request['ip_address'] ?? null,
                'channel' => $request['channel'] ?? 'WEB',
            ], $extra)
        );
    }

    private function safeRollback(): void
    {
        try {
            \db()->rollback();
        } catch (\Throwable $ignored) {
            // Best effort — rollback failures must not mask the original error.
        }
    }

    // =====================================================================
    // Data-access seams (protected so tests can stub without a database)
    // =====================================================================

    /**
     * Today's attendance record for the employee (idempotency pre-check).
     *
     * @return array<string,mixed>|null
     */
    protected function findTodayRecord(int $employeeDbId, string $today): ?array
    {
        return \db()->fetchOne(
            "SELECT a.id, a.clock_in, a.clock_out, a.is_late, a.status,
                    o.name AS office_name
             FROM attendance a
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE a.employee_id = ? AND DATE(a.clock_in) = ?
             ORDER BY a.clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );
    }

    /**
     * Alias kept for the concurrent-retry branch (same shape as pre-check).
     */
    protected function findTodayRecordWithOffice(int $employeeDbId, string $today): ?array
    {
        return $this->findTodayRecord($employeeDbId, $today);
    }

    /**
     * Today's open-or-latest session for the employee (clock-out target).
     *
     * @return array<string,mixed>|null
     */
    protected function findTodaySession(int $employeeDbId, string $today): ?array
    {
        return \db()->fetchOne(
            "SELECT id, clock_in, clock_out, status
             FROM attendance
             WHERE employee_id = ? AND DATE(clock_in) = ?
             ORDER BY clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );
    }

    /**
     * Insert the clock-in record inside the caller's transaction.
     *
     * @param array<string,mixed> $record
     */
    protected function insertClockIn(array $record): int
    {
        return \db()->insert('attendance', $record);
    }

    /**
     * Apply the authoritative clock-out update. The timestamp is always
     * server-generated; the employee scoping is an extra ownership guard.
     */
    protected function applyClockOut(int $sessionId, string $now, int $officeId, ?string $ipAddress): void
    {
        \db()->update('attendance', [
            'clock_out' => $now,
            'clock_out_office_id' => $officeId,
            'ip_address' => $ipAddress,
            'status' => 'clocked_out',
            'updated_at' => $now,
        ], 'id = ?', 'i', [$sessionId]);
    }
}

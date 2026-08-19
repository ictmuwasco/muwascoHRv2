<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use App\Helpers\AuthorizationService;

/**
 * Meeting Controller - REST API for meeting management.
 *
 * Rewritten to work with the custom framework (BaseController + MySQLi).
 * The original version used Laravel-style code (Illuminate\Http\Request,
 * response()->json(), Eloquent ORM) which is not available in this framework.
 */
class MeetingController extends BaseController
{
    private $auditService;
    private $authService;

    public function __construct()
    {
        $this->auditService = AuditService::getInstance();
        $this->authService = AuthorizationService::getInstance();
    }

    /**
     * GET /api/meetings - List meetings with filters and pagination
     */
    public function indexAction(): void
    {
        $this->requirePermission('meetings', 'view');

        $db = \db();

        $status = $_GET['status'] ?? null;
        $organizer = $_GET['organizer'] ?? null;
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        $search = $_GET['search'] ?? null;

        $where = [];
        $types = '';
        $params = [];

        if ($status) {
            $where[] = "m.status = ?";
            $types .= 's';
            $params[] = $status;
        }

        if ($organizer) {
            $where[] = "m.created_by = ?";
            $types .= 'i';
            $params[] = (int)$organizer;
        }

        if ($dateFrom) {
            $where[] = "m.meeting_date >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where[] = "m.meeting_date <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }

        if ($search) {
            $where[] = "(m.title LIKE ? OR m.location LIKE ?)";
            $types .= 'ss';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM meetings m {$whereClause}";
        $total = (int)$db->fetchValue($countSql, $types, $params);

        // Fetch data
        $dataSql = "
            SELECT m.*, u.first_name as org_first_name, u.last_name as org_last_name
            FROM meetings m
            LEFT JOIN users u ON m.created_by = u.id
            {$whereClause}
            ORDER BY m.meeting_date DESC
            LIMIT ? OFFSET ?
        ";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);
        $meetings = $db->fetchAll($dataSql, $dataTypes, $dataParams);

        $this->json([
            'data' => $meetings,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int)ceil($total / $perPage)),
        ]);
    }

    /**
     * GET /api/meetings/eligible-employees - Get active employees for meeting invitations.
     * 
     * This endpoint is used by the Create Meeting form to populate the
     * attendee selector. It requires only the `meetings.create` permission
     * (not the `employees.view` permission), so users who can create
     * meetings but don't have full employee directory access can still
     * invite attendees.
     */
    public function eligibleEmployeesAction(): void
    {
        $this->requirePermission('meetings', 'create');

        $db = \db();

        try {
            $employees = $db->fetchAll(
                "SELECT e.id, e.first_name, e.last_name, e.employee_id, e.designation, e.email
                 FROM employees e
                 WHERE e.employee_status = 'active'
                 ORDER BY e.first_name, e.last_name"
            );

            $this->json([
                'success' => true,
                'data' => $employees,
            ]);
        } catch (\Throwable $e) {
            error_log('Error loading eligible employees for meetings: ' . $e->getMessage());
            $this->error('Failed to load employees. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings - Create a new meeting
     */
    public function storeAction(): void
    {
        $this->requirePermission('meetings', 'create');

        $data = $this->getJsonBody();

        // Validate required fields
        $missing = $this->validateRequired($data, ['title', 'meeting_date', 'start_time', 'end_time', 'location', 'employee_ids']);
        if ($missing !== null) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 422);
        }

        $title = trim($data['title']);
        $description = $data['description'] ?? null;
        $agenda = $data['agenda'] ?? null;
        $meetingDate = $data['meeting_date'];
        $startTime = $data['start_time'];
        $endTime = $data['end_time'];
        $location = trim($data['location']);
        $employeeIds = $data['employee_ids'];
        $userId = $this->getAuthUserId();

        if (!is_array($employeeIds) || empty($employeeIds)) {
            $this->error('At least one employee must be selected', 422);
        }

        $db = \db();
        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();

            // Create meeting
            $meetingId = $db->insert('meetings', [
                'title' => $title,
                'description' => $description,
                'agenda' => $agenda,
                'meeting_date' => $meetingDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'location' => $location,
                'status' => 'scheduled',
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Create invitations
            foreach ($employeeIds as $employeeId) {
                $db->insert('meeting_invitations', [
                    'meeting_id' => $meetingId,
                    'employee_id' => (int)$employeeId,
                    'invited_by' => $userId,
                    'invited_at' => $now,
                    'response_status' => 'pending',
                    'attendance_status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $db->commit();

            // Audit
            $this->auditService->log(
                AuditService::MODULE_MEETINGS,
                AuditService::ACTION_CREATE,
                "Meeting created: '{$title}' by user #{$userId}",
                [
                    'target_type' => 'Meeting',
                    'target_id' => $meetingId,
                    'target_name' => $title,
                    'metadata' => [
                        'employee_count' => count($employeeIds),
                    ],
                ]
            );

            $this->json([
                'message' => 'Meeting created and invitations sent',
                'meeting' => [
                    'id' => $meetingId,
                    'title' => $title,
                    'description' => $description,
                    'agenda' => $agenda,
                    'meeting_date' => $meetingDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'location' => $location,
                    'status' => 'scheduled',
                    'created_by' => $userId,
                ],
            ], 201);
        } catch (\Throwable $e) {
            $db->rollback();
            error_log("Meeting create error: " . $e->getMessage());
            $this->error('Failed to create meeting: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/meetings/{id} - Get meeting details
     */
    public function showAction(int $id): void
    {
        $db = \db();

        $meeting = $db->fetchOne(
            "SELECT m.*, u.first_name as org_first_name, u.last_name as org_last_name
             FROM meetings m
             LEFT JOIN users u ON m.created_by = u.id
             WHERE m.id = ?",
            'i',
            [$id]
        );

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        // Get invitations with employee details
        $invitations = $db->fetchAll(
            "SELECT mi.*, e.first_name, e.last_name, e.employee_id
             FROM meeting_invitations mi
             LEFT JOIN employees e ON mi.employee_id = e.id
             WHERE mi.meeting_id = ?
             ORDER BY mi.created_at DESC",
            'i',
            [$id]
        );

        $meeting['invitations'] = $invitations;

        $this->json(['data' => $meeting]);
    }

    /**
     * PUT /api/meetings/{id} - Edit meeting
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('meetings', 'edit');

        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        if (in_array($meeting['status'], ['completed', 'cancelled'])) {
            $this->error('Cannot edit completed or cancelled meeting', 400);
        }

        $data = $this->getJsonBody();

        $updateData = [];
        if (isset($data['title'])) $updateData['title'] = trim($data['title']);
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['agenda'])) $updateData['agenda'] = $data['agenda'];
        if (isset($data['meeting_date'])) $updateData['meeting_date'] = $data['meeting_date'];
        if (isset($data['start_time'])) $updateData['start_time'] = $data['start_time'];
        if (isset($data['end_time'])) $updateData['end_time'] = $data['end_time'];
        if (isset($data['location'])) $updateData['location'] = trim($data['location']);

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            $db->update('meetings', $updateData, 'id = ?', 'i', [$id]);
        }

        $updated = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);
        $this->json(['data' => $updated]);
    }

    /**
     * POST /api/meetings/{id}/cancel - Cancel a meeting
     */
    public function cancelAction(int $id): void
    {
        $this->requirePermission('meetings', 'manage');

        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        if (in_array($meeting['status'], ['completed', 'cancelled'])) {
            $this->error('Meeting already cancelled or completed', 400);
        }

        $db->update('meetings', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', 'i', [$id]);

        $userId = $this->getAuthUserId();

        // Audit
        $this->auditService->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_CANCEL_MEETING,
            "Meeting cancelled: '{$meeting['title']}' by user #{$userId}",
            [
                'target_type' => 'Meeting',
                'target_id' => $id,
                'target_name' => $meeting['title'],
            ]
        );

        $this->json(['message' => 'Meeting cancelled successfully']);
    }

    /**
     * GET /api/meetings/{id}/participants - Get meeting participants
     */
    public function participantsAction(int $id): void
    {
        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        $participants = $db->fetchAll(
            "SELECT mi.*, e.first_name, e.last_name, e.employee_id, e.designation
             FROM meeting_invitations mi
             LEFT JOIN employees e ON mi.employee_id = e.id
             WHERE mi.meeting_id = ?
             ORDER BY mi.created_at DESC",
            'i',
            [$id]
        );

        $this->json(['data' => $participants]);
    }

    /**
     * POST /api/meetings/{id}/participants - Add participant
     */
    public function addParticipantAction(int $id): void
    {
        $this->requirePermission('meetings', 'manage');

        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        $data = $this->getJsonBody();

        $missing = $this->validateRequired($data, ['employee_id']);
        if ($missing !== null) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 422);
        }

        $employeeId = (int)$data['employee_id'];

        // Check if already invited
        $existing = $db->fetchOne(
            "SELECT id FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?",
            'ii',
            [$id, $employeeId]
        );

        if ($existing) {
            $this->error('Employee already invited to this meeting', 400);
        }

        $now = date('Y-m-d H:i:s');
        $db->insert('meeting_invitations', [
            'meeting_id' => $id,
            'employee_id' => $employeeId,
            'invited_by' => $this->getAuthUserId(),
            'invited_at' => $now,
            'response_status' => 'pending',
            'attendance_status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->json(['message' => 'Employee added to meeting invitations']);
    }

    /**
     * DELETE /api/meetings/{id}/participants/{employeeId} - Remove participant
     */
    public function removeParticipantAction(int $id, int $employeeId): void
    {
        $this->requirePermission('meetings', 'manage');

        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        $invitation = $db->fetchOne(
            "SELECT id FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?",
            'ii',
            [$id, $employeeId]
        );

        if (!$invitation) {
            $this->notFound('Invitation not found');
        }

        $db->delete('meeting_invitations', 'id = ?', 'i', [(int)$invitation['id']]);

        $this->json(['message' => 'Participant removed']);
    }

    /**
     * POST /api/meetings/{id}/confirm - Confirm attendance
     */
    public function confirmAction(int $id): void
    {
        $this->requirePermission('meetings', 'confirm');

        $db = \db();
        $userId = $this->getAuthUserId();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        // Get the employee ID for this user
        $employeeId = $this->getUserEmployeeId($userId);

        if (!$employeeId) {
            $this->error('Employee profile not found', 404);
        }

        // Verify user has invitation for this meeting
        $invitation = $db->fetchOne(
            "SELECT * FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?",
            'ii',
            [$id, $employeeId]
        );

        if (!$invitation) {
            $this->error('You do not have an invitation for this meeting', 403);
        }

        if ($meeting['status'] === 'cancelled') {
            $this->error('Meeting is cancelled', 400);
        }

        $now = date('Y-m-d H:i:s');
        $db->update('meeting_invitations', [
            'response_status' => 'accepted',
            'responded_at' => $now,
            'updated_at' => $now,
        ], 'id = ?', 'i', [(int)$invitation['id']]);

        // Audit
        $this->auditService->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_CONFIRM,
            "Employee confirmed attendance for meeting '{$meeting['title']}': user #{$userId}",
            [
                'target_type' => 'MeetingInvitation',
                'target_id' => (int)$invitation['id'],
                'metadata' => [
                    'attendance_status' => 'pending -> present',
                ],
            ]
        );

        $this->json(['message' => 'Attendance confirmed']);
    }

    /**
     * POST /api/meetings/{id}/decline - Decline invitation
     */
    public function declineAction(int $id): void
    {
        $this->requirePermission('meetings', 'confirm');

        $db = \db();
        $userId = $this->getAuthUserId();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        // Get the employee ID for this user
        $employeeId = $this->getUserEmployeeId($userId);

        if (!$employeeId) {
            $this->error('Employee profile not found', 404);
        }

        // Verify user has invitation for this meeting
        $invitation = $db->fetchOne(
            "SELECT * FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?",
            'ii',
            [$id, $employeeId]
        );

        if (!$invitation) {
            $this->error('You do not have an invitation for this meeting', 403);
        }

        if ($meeting['status'] === 'cancelled') {
            $this->error('Meeting is cancelled', 400);
        }

        $now = date('Y-m-d H:i:s');
        $db->update('meeting_invitations', [
            'response_status' => 'declined',
            'responded_at' => $now,
            'updated_at' => $now,
        ], 'id = ?', 'i', [(int)$invitation['id']]);

        // Audit
        $this->auditService->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_DECLINE,
            "Employee declined invitation for meeting '{$meeting['title']}': user #{$userId}",
            [
                'target_type' => 'MeetingInvitation',
                'target_id' => (int)$invitation['id'],
            ]
        );

        $this->json(['message' => 'Invitation declined']);
    }

    /**
     * GET /api/my-meetings - Get meetings for the authenticated employee
     */
    public function myMeetingsAction(): void
    {
        $userId = $this->getAuthUserId();

        if ($userId <= 0) {
            $this->unauthorized('Authentication required');
        }

        $db = \db();

        // Find the employee record for this user
        $employee = $db->fetchOne(
            "SELECT e.id, e.first_name, e.last_name, e.employee_id
             FROM employees e
             JOIN users u ON u.email = e.email
             WHERE u.id = ?
             LIMIT 1",
            'i',
            [$userId]
        );

        if (!$employee) {
            $this->json(['data' => []]);
            return;
        }

        $employeeId = (int)$employee['id'];

        // Get meetings where this employee has an invitation
        $meetings = $db->fetchAll(
            "SELECT m.*, u.first_name as org_first_name, u.last_name as org_last_name,
                    mi.response_status, mi.attendance_status, mi.responded_at
             FROM meetings m
             JOIN meeting_invitations mi ON m.id = mi.meeting_id
             LEFT JOIN users u ON m.created_by = u.id
             WHERE mi.employee_id = ?
             ORDER BY m.meeting_date DESC",
            'i',
            [$employeeId]
        );

        // Format the response with invitation status
        $formatted = array_map(function ($meeting) {
            return [
                'id' => (int)$meeting['id'],
                'title' => $meeting['title'],
                'description' => $meeting['description'],
                'agenda' => $meeting['agenda'],
                'meeting_date' => $meeting['meeting_date'],
                'start_time' => $meeting['start_time'],
                'end_time' => $meeting['end_time'],
                'location' => $meeting['location'],
                'status' => $meeting['status'],
                'created_by' => (int)$meeting['created_by'],
                'organizer' => $meeting['org_first_name'] ? [
                    'first_name' => $meeting['org_first_name'],
                    'last_name' => $meeting['org_last_name'],
                ] : null,
                'organizer_name' => $meeting['org_first_name']
                    ? trim($meeting['org_first_name'] . ' ' . $meeting['org_last_name'])
                    : null,
                'invitation' => [
                    'response_status' => $meeting['response_status'],
                    'attendance_status' => $meeting['attendance_status'],
                    'responded_at' => $meeting['responded_at'],
                ],
                'attendee_count' => 0,
                'total_invited' => 0,
            ];
        }, $meetings);

        // Get attendee counts for each meeting
        foreach ($formatted as &$meeting) {
            $counts = $db->fetchOne(
                "SELECT
                    COUNT(*) as total_invited,
                    SUM(CASE WHEN response_status = 'accepted' THEN 1 ELSE 0 END) as attendee_count
                 FROM meeting_invitations
                 WHERE meeting_id = ?",
                'i',
                [$meeting['id']]
            );
            $meeting['attendee_count'] = (int)($counts['attendee_count'] ?? 0);
            $meeting['total_invited'] = (int)($counts['total_invited'] ?? 0);
        }

        $this->json(['data' => $formatted]);
    }

    /**
     * POST /api/meetings/{id}/attendance - Mark attendance
     */
    public function markAttendanceAction(int $id): void
    {
        $this->requirePermission('meetings', 'view_attendance');

        $db = \db();

        $meeting = $db->fetchOne("SELECT * FROM meetings WHERE id = ?", 'i', [$id]);

        if (!$meeting) {
            $this->notFound('Meeting not found');
        }

        $data = $this->getJsonBody();

        $missing = $this->validateRequired($data, ['employee_id', 'status']);
        if ($missing !== null) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 422);
        }

        $employeeId = (int)$data['employee_id'];
        $status = $data['status'];

        if (!in_array($status, ['present', 'absent', 'excused'])) {
            $this->error('Invalid attendance status', 400);
        }

        $invitation = $db->fetchOne(
            "SELECT id FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?",
            'ii',
            [$id, $employeeId]
        );

        if (!$invitation) {
            $this->error('Employee not invited to this meeting', 400);
        }

        $now = date('Y-m-d H:i:s');
        $userId = $this->getAuthUserId();

        $db->update('meeting_invitations', [
            'attendance_status' => $status,
            'attendance_marked_at' => $now,
            'attendance_marked_by' => $userId,
            'updated_at' => $now,
        ], 'id = ?', 'i', [(int)$invitation['id']]);

        // Audit
        $this->auditService->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_STATUS_CHANGE,
            "HR marked attendance for meeting '{$meeting['title']}': employee #{$employeeId} as {$status} by user #{$userId}",
            [
                'target_type' => 'MeetingInvitation',
                'target_id' => (int)$invitation['id'],
                'metadata' => [
                    'attendance_status' => $status,
                ],
            ]
        );

        $this->json(['message' => 'Attendance marked successfully']);
    }

    /**
     * Helper: Get the employee ID for the current user.
     */
    private function getUserEmployeeId(int $userId): ?int
    {
        // Check session first
        if (isset($_SESSION['employee_id']) && $_SESSION['employee_id'] > 0) {
            return (int)$_SESSION['employee_id'];
        }

        // Fallback: look up by user email
        $db = \db();
        $employee = $db->fetchOne(
            "SELECT e.id FROM employees e
             JOIN users u ON u.email = e.email
             WHERE u.id = ?
             LIMIT 1",
            'i',
            [$userId]
        );

        return $employee ? (int)$employee['id'] : null;
    }
}

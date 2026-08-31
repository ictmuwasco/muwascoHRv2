<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MeetingRepository;
use App\Repositories\MeetingMinutesRepository;
use App\Helpers\Auth;
use InvalidArgumentException;

/**
 * Meeting Service
 *
 * Contains business logic for meeting management.
 * Orchestrates repository operations and enforces business rules.
 */
class MeetingService
{
    private MeetingRepository $meetingRepository;

        private ?MeetingMinutesRepository $minutesRepository = null;

    public function __construct()
    {
        $this->meetingRepository = new MeetingRepository();
        $this->minutesRepository = new MeetingMinutesRepository();
    }

    /**
     * Get paginated list of meetings with optional filters.
     * Each item is decorated with minutes flags for UI gating.
     */
    public function getAllMeetings(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $result = $this->meetingRepository->paginate($page, $perPage, $filters);
        $result['data'] = $this->decorateList($result['data']);
        return $result;
    }

    /**
     * Decorate meeting list items with minutes access flags.
     *
     * Performance: the permission check is user-level so it runs ONCE, and
     * minutes + invitation lookups are batched into two IN() queries —
     * never per-meeting queries (no N+1 on the list endpoint).
     */
    private function decorateList(array $meetings): array
    {
        if (empty($meetings)) {
            return $meetings;
        }

        $auth = \App\Helpers\Auth::getInstance();
        $userId = $auth->id();
        $employeeId = $userId ? $this->meetingRepository->getEmployeeIdByUserId($userId) : null;

        $canManage = false;
        if ($userId) {
            $canManage = $auth->hasAnyPermission(array(
                array('meetings' => 'minutes.create'),
                array('meetings' => 'minutes.update'),
                array('meetings' => 'minutes.publish'),
                array('meetings' => 'minutes.amend'),
            ));
        }

        $meetingIds = array();
        foreach ($meetings as $m) {
            $id = (int) ($m['id'] ?? 0);
            if ($id > 0) {
                $meetingIds[] = $id;
            }
        }

        $minutesMap = $this->minutesRepository->getMinutesStatusByMeetingIds($meetingIds);
        $invitationMap = ($employeeId !== null && $meetingIds !== array())
            ? $this->minutesRepository->getInvitationByMeetingIds($meetingIds, $employeeId)
            : array();

        foreach ($meetings as $index => $m) {
            $meetingId = (int) ($m['id'] ?? 0);
            $minutes = $minutesMap[$meetingId] ?? null;

            $canViewPublished = false;
            if ($minutes !== null && ($minutes['status'] ?? '') === 'published' && $employeeId !== null) {
                $invitation = $invitationMap[$meetingId] ?? null;
                $canViewPublished = $invitation !== null
                    && ($invitation['response_status'] ?? '') === 'accepted';
            }

            $meetings[$index]['minutes'] = array(
                'exists'             => $minutes !== null,
                'status'             => $minutes['status'] ?? null,
                'version'            => $minutes['version'] ?? null,
                'reference_number'   => $minutes['reference_number'] ?? null,
                'can_manage'         => $canManage,
                'can_view_published' => $canViewPublished,
            );
        }

        return $meetings;
    }

    /**
     * Get a single meeting by ID with invitations.
     */
    public function getMeetingById(int $id): ?array
    {
        return $this->meetingRepository->findByIdWithInvitations($id);
    }

    /**
     * Get all eligible employees for inviting to a meeting.
     */
    public function getEligibleEmployees(): array
    {
        return $this->meetingRepository->getEligibleEmployees();
    }

    /**
     * Get dashboard summary statistics for meetings.
     */
    public function getDashboardStats(): array
    {
        return $this->meetingRepository->getDashboardStats();
    }

    /**
     * Get meetings for the currently authenticated employee.
     */
    public function getMyMeetings(): array
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            return [];
        }

        $employeeId = $this->meetingRepository->getEmployeeIdByUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $meetings = $this->meetingRepository->getMeetingsByEmployee($employeeId, $userId);

        // Map to the format expected by the frontend
        $mapped = [];
        foreach ($meetings as $meeting) {
                        $organizerName = $this->meetingRepository->getOrganizerName((int)$meeting['created_by']);

            $mapped[] = [
                'id'               => (int)$meeting['id'],
                'title'            => $meeting['title'] ?? '',
                'description'      => $meeting['description'] ?? '',
                'agenda'           => $meeting['agenda'] ?? '',
                'meeting_date'     => $meeting['meeting_date'] ?? '',
                'start_time'       => $meeting['start_time'] ?? '',
                'end_time'         => $meeting['end_time'] ?? '',
                'location'         => $meeting['location'] ?? '',
                'status'           => $meeting['status'] ?? 'scheduled',
                'created_by'       => (int)($meeting['created_by'] ?? 0),
                'org_first_name'   => $meeting['org_first_name'] ?? null,
                'org_last_name'    => $meeting['org_last_name'] ?? null,
                'organizer_name'   => $organizerName,
                'invitation'       => [
                    // Meetings the user organized without a self-invitation
                    // are treated as pending (the creator has not explicitly
                    // responded to an invitation).
                    'response_status'   => $meeting['invitation_id'] === null
                        ? 'pending'
                        : ($meeting['response_status'] ?? 'pending'),
                    'attendance_status' => $meeting['attendance_status'] ?? 'not_marked',
                    'responded_at'      => $meeting['responded_at'] ?? null,
                ],
                'attendee_count'   => 0,
                'total_invited'    => 0,
                'minutes'          => $this->minutesRepository->getMeetingMinutesInfo(
                    (int)$meeting['id'], $userId, $employeeId
                ),
            ];
        }

        return $mapped;
    }

    /**
     * Create a new meeting with invitations.
     *
     * @param array $data  Meeting data including employee_ids
     * @return int  Meeting ID
     * @throws InvalidArgumentException
     */
    public function createMeeting(array $data): int
    {
        $errors = $this->validateMeetingData($data);
        if (!empty($errors)) {
            throw new InvalidArgumentException(implode(', ', $errors));
        }

        $userId = Auth::getInstance()->id();
        if (!$userId) {
            throw new InvalidArgumentException('Authentication required');
        }

        $meetingData = [
            'title'        => trim(strip_tags((string)($data['title'] ?? ''))),
            'description'  => trim(strip_tags((string)($data['description'] ?? ''))),
            'agenda'       => trim(strip_tags((string)($data['agenda'] ?? ''))),
            'meeting_date' => $data['meeting_date'] ?? '',
            'start_time'   => $data['start_time'] ?? '',
            'end_time'     => $data['end_time'] ?? '',
            'location'     => trim(strip_tags((string)($data['location'] ?? ''))),
            'status'       => 'scheduled',
            'created_by'   => $userId,
        ];

        $meetingId = $this->meetingRepository->create($meetingData);

        // Create invitations for selected employees
        $employeeIds = $data['employee_ids'] ?? [];
        if (!empty($employeeIds) && is_array($employeeIds)) {
            $this->meetingRepository->createInvitations($meetingId, $employeeIds, $userId);
        }

        return $meetingId;
    }

    /**
     * Update an existing meeting.
     *
     * @param int   $id
     * @param array $data
     * @return bool
     * @throws InvalidArgumentException
     */
    public function updateMeeting(int $id, array $data): bool
    {
        $meeting = $this->meetingRepository->findById($id);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $updateData = [];
        if (isset($data['title'])) {
            $updateData['title'] = trim(strip_tags((string)$data['title']));
        }
        if (isset($data['description'])) {
            $updateData['description'] = trim(strip_tags((string)$data['description']));
        }
        if (isset($data['agenda'])) {
            $updateData['agenda'] = trim(strip_tags((string)$data['agenda']));
        }
        if (isset($data['meeting_date'])) {
            $updateData['meeting_date'] = $data['meeting_date'];
        }
        if (isset($data['start_time'])) {
            $updateData['start_time'] = $data['start_time'];
        }
        if (isset($data['end_time'])) {
            $updateData['end_time'] = $data['end_time'];
        }
        if (isset($data['location'])) {
            $updateData['location'] = trim(strip_tags((string)$data['location']));
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        if (empty($updateData)) {
            return true;
        }

        return $this->meetingRepository->update($id, $updateData);
    }

    /**
     * Delete a meeting.
     */
    public function deleteMeeting(int $id): bool
    {
        if (!$this->meetingRepository->exists($id)) {
            throw new InvalidArgumentException('Meeting not found');
        }

        return $this->meetingRepository->delete($id);
    }

    /**
     * Cancel a meeting (set status to 'cancelled').
     */
    public function cancelMeeting(int $id): bool
    {
        $meeting = $this->meetingRepository->findById($id);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        return $this->meetingRepository->updateStatus($id, 'cancelled');
    }

    /**
     * Confirm attendance for the current employee on a meeting.
     *
     * @param int $meetingId
     * @return array|null  The updated invitation row from the database
     * @throws InvalidArgumentException
     */
    public function confirmAttendance(int $meetingId): ?array
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            throw new InvalidArgumentException('Authentication required');
        }

        $employeeId = $this->meetingRepository->getEmployeeIdByUserId($userId);
        if (!$employeeId) {
            throw new InvalidArgumentException('Employee record not found');
        }

        if (!$this->meetingRepository->exists($meetingId)) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $invitedBy = Auth::getInstance()->id();
        return $this->meetingRepository->updateInvitationResponse($meetingId, $employeeId, 'accepted', $invitedBy);
    }

    /**
     * Decline attendance for the current employee on a meeting.
     *
     * @param int $meetingId
     * @return array|null  The updated invitation row from the database
     * @throws InvalidArgumentException
     */
    public function declineAttendance(int $meetingId): ?array
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            throw new InvalidArgumentException('Authentication required');
        }

        $employeeId = $this->meetingRepository->getEmployeeIdByUserId($userId);
        if (!$employeeId) {
            throw new InvalidArgumentException('Employee record not found');
        }

        if (!$this->meetingRepository->exists($meetingId)) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $invitedBy = Auth::getInstance()->id();
        return $this->meetingRepository->updateInvitationResponse($meetingId, $employeeId, 'declined', $invitedBy);
    }

    /**
     * Get participants for a meeting.
     */
    public function getParticipants(int $meetingId): array
    {
        $meeting = $this->meetingRepository->findByIdWithInvitations($meetingId);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        return $meeting['invitations'] ?? [];
    }

    /**
     * Add a participant to a meeting.
     */
    public function addParticipant(int $meetingId, int $employeeId): bool
    {
        $meeting = $this->meetingRepository->findById($meetingId);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $userId = Auth::getInstance()->id();
        if (!$userId) {
            throw new InvalidArgumentException('Authentication required');
        }

        $this->meetingRepository->createInvitations($meetingId, [$employeeId], $userId);
        return true;
    }

    /**
     * Remove a participant from a meeting.
     */
    public function removeParticipant(int $meetingId, int $employeeId): bool
    {
        $meeting = $this->meetingRepository->findById($meetingId);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM meeting_invitations WHERE meeting_id = ? AND employee_id = ?");
        $stmt->bind_param('ii', $meetingId, $employeeId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Mark attendance for a meeting participant.
     */
    public function markAttendance(int $meetingId, int $employeeId, string $status): bool
    {
        $meeting = $this->meetingRepository->findById($meetingId);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found');
        }

        $now = date('Y-m-d H:i:s');
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            UPDATE meeting_invitations
            SET attendance_status = ?, attendance_marked_at = ?
            WHERE meeting_id = ? AND employee_id = ?
        ");
        $stmt->bind_param('ssii', $status, $now, $meetingId, $employeeId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Validate meeting data.
     */
    private function validateMeetingData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($data['title']) || trim((string)$data['title']) === '') {
            $errors[] = 'Meeting title is required';
        }

        if (empty($data['meeting_date'])) {
            $errors[] = 'Meeting date is required';
        }

        if (empty($data['start_time'])) {
            $errors[] = 'Start time is required';
        }

        if (empty($data['end_time'])) {
            $errors[] = 'End time is required';
        }

        if (empty($data['location']) || trim((string)$data['location']) === '') {
            $errors[] = 'Location is required';
        }

        return $errors;
    }
}

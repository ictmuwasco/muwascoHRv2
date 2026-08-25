<?php

declare(strict_types=1);

namespace App\Controllers\Meeting;

use App\Controllers\BaseController;

use App\Services\MeetingService;
use App\Helpers\Auth;

/**
 * Meeting Controller - REST API for meeting management.
 *
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to MeetingService.
 */
class MeetingController extends BaseController
{
    private MeetingService $meetingService;

    public function __construct()
    {
        $this->meetingService = new MeetingService();
    }

    /**
     * Require authentication. Returns the user ID or sends a 401 response.
     */
    private function requireAuth(): int
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->unauthorized('Authentication required');
        }
        return $userId;
    }

    /**
     * GET /api/my-meetings
     * Get meetings for the currently authenticated employee.
     */
    public function myMeetingsAction(): void
    {
        try {
            $this->requireAuth();
            $meetings = $this->meetingService->getMyMeetings();
            $this->success($meetings);
        } catch (\Exception $e) {
            \logger()->error('My meetings error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve meetings. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings
     * List all meetings with pagination and filters.
     */
    public function indexAction(): void
    {
        try {
            $this->requireAuth();

            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));

            $filters = [];
            if (!empty($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (!empty($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (!empty($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }
            if (!empty($_GET['search'])) {
                $filters['search'] = $_GET['search'];
            }
            if (!empty($_GET['organizer'])) {
                $filters['organizer'] = $_GET['organizer'];
            }
            if (!empty($_GET['location'])) {
                $filters['location'] = $_GET['location'];
            }

            $result = $this->meetingService->getAllMeetings($page, $perPage, $filters);

            // Build response with pagination metadata at the top level
            // (frontend expects response.data.data for the array and
            //  response.data.total / per_page / current_page / last_page)
            $this->json([
                'success'      => true,
                'message'      => 'Success',
                'data'         => $result['data'],
                'total'        => $result['total'],
                'per_page'     => $result['per_page'],
                'current_page' => $result['current_page'],
                'last_page'    => $result['last_page'],
            ]);
        } catch (\Exception $e) {
            \logger()->error('Meetings listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve meetings. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings/stats
     * Get dashboard summary statistics for meetings.
     */
    public function statsAction(): void
    {
        try {
            $this->requireAuth();
            $stats = $this->meetingService->getDashboardStats();
            $this->success($stats);
        } catch (\Exception $e) {
            \logger()->error('Meeting stats error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve meeting statistics. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings/export
     * Export meetings to CSV with optional filters.
     */
    public function exportAction(): void
    {
        try {
            $this->requireAuth();

            $filters = [];
            if (!empty($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (!empty($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (!empty($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }
            if (!empty($_GET['search'])) {
                $filters['search'] = $_GET['search'];
            }

            // Fetch all meetings (no pagination) for export
            $result = $this->meetingService->getAllMeetings(1, 100000, $filters);
            $meetings = $result['data'] ?? [];

            // Build CSV
            $csv = "ID,Title,Description,Agenda,Date,Start Time,End Time,Location,Status,Organizer\n";
            foreach ($meetings as $m) {
                $organizer = trim(($m['org_first_name'] ?? '') . ' ' . ($m['org_last_name'] ?? ''));
                $csv .= '"' . str_replace('"', '""', (string)($m['id'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['title'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['description'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['agenda'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['meeting_date'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['start_time'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['end_time'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['location'] ?? '')) . '","'
                     . str_replace('"', '""', (string)($m['status'] ?? '')) . '","'
                     . str_replace('"', '""', $organizer) . "\"\n";
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="meetings-export-' . date('Y-m-d') . '.csv"');
            echo $csv;
            exit;
        } catch (\Exception $e) {
            \logger()->error('Meeting export error', ['error' => $e->getMessage()]);
            $this->error('Failed to export meetings. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings/eligible-employees
     * Get all eligible employees for inviting to a meeting.
     */
    public function eligibleEmployeesAction(): void
    {
        try {
            $this->requireAuth();
            $employees = $this->meetingService->getEligibleEmployees();
            $this->success($employees);
        } catch (\Exception $e) {
            \logger()->error('Eligible employees error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve eligible employees. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings
     * Create a new meeting with invitations.
     */
    public function storeAction(): void
    {
        $this->requireAuth();

        $data = $this->getJsonBody();

        try {
            $meetingId = $this->meetingService->createMeeting($data);
            $this->success(['id' => $meetingId], 'Meeting created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Meeting creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create meeting. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings/{id}
     * Get a single meeting with its invitations.
     */
    public function showAction(int $id): void
    {
        try {
            $this->requireAuth();
            $meeting = $this->meetingService->getMeetingById($id);
            if (!$meeting) {
                $this->notFound('Meeting not found');
            }
            $this->success($meeting);
        } catch (\Exception $e) {
            \logger()->error('Meeting retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve meeting. Please try again.', 500);
        }
    }

    /**
     * PUT /api/meetings/{id}
     * Update an existing meeting.
     */
    public function updateAction(int $id): void
    {
        $this->requireAuth();

        $data = $this->getJsonBody();

        try {
            $result = $this->meetingService->updateMeeting($id, $data);
            $this->success($result, 'Meeting updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Meeting update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update meeting. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/meetings/{id}
     * Delete a meeting.
     */
    public function destroyAction(int $id): void
    {
        $this->requireAuth();

        try {
            $result = $this->meetingService->deleteMeeting($id);
            $this->success($result, 'Meeting deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Meeting deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete meeting. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings/{id}/cancel
     * Cancel a meeting.
     */
    public function cancelAction(int $id): void
    {
        $this->requireAuth();

        try {
            $result = $this->meetingService->cancelMeeting($id);
            $this->success($result, 'Meeting cancelled successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Meeting cancellation error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to cancel meeting. Please try again.', 500);
        }
    }

    /**
     * GET /api/meetings/{id}/participants
     * Get participants for a meeting.
     */
    public function participantsAction(int $id): void
    {
        try {
            $this->requireAuth();
            $participants = $this->meetingService->getParticipants($id);
            $this->success($participants);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Participants retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve participants. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings/{id}/participants
     * Add a participant to a meeting.
     */
    public function addParticipantAction(int $id): void
    {
        $this->requireAuth();

        $data = $this->getJsonBody();
        $employeeId = (int)($data['employee_id'] ?? 0);

        if ($employeeId <= 0) {
            $this->error('Employee ID is required', 400);
            return;
        }

        try {
            $this->meetingService->addParticipant($id, $employeeId);
            $this->success(null, 'Participant added successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Add participant error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to add participant. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/meetings/{id}/participants/{employeeId}
     * Remove a participant from a meeting.
     */
    public function removeParticipantAction(int $id, int $employeeId): void
    {
        $this->requireAuth();

        try {
            $result = $this->meetingService->removeParticipant($id, $employeeId);
            $this->success($result, 'Participant removed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Remove participant error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to remove participant. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings/{id}/confirm
     * Confirm attendance for the current employee on a meeting.
     */
    public function confirmAction(int $id): void
    {
        $this->requireAuth();

        try {
            $invitation = $this->meetingService->confirmAttendance($id);
            if (!$invitation) {
                $this->error('Unable to accept meeting. Please try again.', 500);
                return;
            }
            $this->success($invitation, 'Attendance confirmed');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Confirm attendance error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to confirm attendance. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings/{id}/decline
     * Decline attendance for the current employee on a meeting.
     */
    public function declineAction(int $id): void
    {
        $this->requireAuth();

        try {
            $invitation = $this->meetingService->declineAttendance($id);
            if (!$invitation) {
                $this->error('Unable to decline meeting. Please try again.', 500);
                return;
            }
            $this->success($invitation, 'Attendance declined');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Decline attendance error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to decline attendance. Please try again.', 500);
        }
    }

    /**
     * POST /api/meetings/{id}/attendance
     * Mark attendance for a meeting participant.
     */
    public function markAttendanceAction(int $id): void
    {
        $this->requireAuth();

        $data = $this->getJsonBody();
        $employeeId = (int)($data['employee_id'] ?? 0);
        $status = $data['status'] ?? 'present';

        if ($employeeId <= 0) {
            $this->error('Employee ID is required', 400);
            return;
        }

        try {
            $result = $this->meetingService->markAttendance($id, $employeeId, $status);
            $this->success($result, 'Attendance marked successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Mark attendance error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to mark attendance. Please try again.', 500);
        }
    }
}



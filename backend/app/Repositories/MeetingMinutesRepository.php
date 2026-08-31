<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Database;

/**
 * MeetingMinutesRepository
 *
 * Handles all database operations for meeting minutes and their child
 * records (agenda items, decisions, action items, AOB items). Uses raw
 * prepared statements exactly like MeetingRepository, and reads the
 * invitation/attendance data from the EXISTING meeting_invitations table
 * (never duplicates attendance).
 */
class MeetingMinutesRepository
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    /**
     * Find the minutes for a meeting, joined with all lookup names so the
     * UI/viewer never needs a second round-trip.
     */
    public function findByMeetingId(int $meetingId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT mm.*,
                   m.title AS meeting_title,
                   m.meeting_date AS meeting_original_date,
                   m.start_time AS meeting_original_start,
                   m.end_time AS meeting_original_end,
                   m.location AS meeting_original_location,
                   m.status AS meeting_status,
                   m.created_by AS meeting_created_by,
                   u_prep.first_name AS prepared_first_name,
                   u_prep.last_name  AS prepared_last_name,
                   u_pub.first_name  AS published_first_name,
                   u_pub.last_name   AS published_last_name,
                   u_rev.first_name  AS reviewed_first_name,
                   u_rev.last_name   AS reviewed_last_name,
                   u_app.first_name  AS approved_first_name,
                   u_app.last_name   AS approved_last_name,
                   chair.first_name  AS chairperson_first_name,
                   chair.last_name   AS chairperson_last_name,
                   sec.first_name    AS secretary_first_name,
                   sec.last_name     AS secretary_last_name
            FROM meeting_minutes mm
            JOIN meetings m ON mm.meeting_id = m.id
            LEFT JOIN users u_prep ON mm.prepared_by = u_prep.id
            LEFT JOIN users u_pub  ON mm.published_by = u_pub.id
            LEFT JOIN users u_rev  ON mm.reviewed_by = u_rev.id
            LEFT JOIN users u_app  ON mm.approved_by = u_app.id
            LEFT JOIN employees chair ON mm.chairperson_id = chair.id
            LEFT JOIN employees sec   ON mm.secretary_id = sec.id
            WHERE mm.meeting_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $meetingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /** All agenda items for a minutes set, ordered by position. */
    public function getAgendaItems(int $minutesId): array
    {
        return $this->listChild("
            SELECT id, position, agenda_number, title, presenter_id, discussion, decision
            FROM meeting_minutes_agenda_items
            WHERE minutes_id = ?
            ORDER BY position ASC, id ASC
        ", $minutesId);
    }

    /** All decisions for a minutes set. */
    public function getDecisions(int $minutesId): array
    {
        return $this->listChild("
            SELECT id, decision_number, resolution, responsible_id, department_id, due_date, status
            FROM meeting_minutes_decisions
            WHERE minutes_id = ?
            ORDER BY id ASC
        ", $minutesId);
    }

    /** All action items for a minutes set. */
    public function getActionItems(int $minutesId): array
    {
        return $this->listChild("
            SELECT id, action, assigned_to, department_id, due_date, priority, status, remarks
            FROM meeting_minutes_action_items
            WHERE minutes_id = ?
            ORDER BY id ASC
        ", $minutesId);
    }

    /** All AOB items for a minutes set. */
    public function getAobItems(int $minutesId): array
    {
        return $this->listChild("
            SELECT id, item, discussion, decision, action, responsible_id
            FROM meeting_minutes_aob_items
            WHERE minutes_id = ?
            ORDER BY id ASC
        ", $minutesId);
    }

    /** Generic child-list helper (prepared statement, no N+1). */
    private function listChild(string $sql, int $minutesId): array
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $minutesId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    // =========================================================
    // Persistence (called inside a transaction by the service)
    // =========================================================

    /** Insert the minutes header. Returns the new id. */
    public function insertMinutes(array $m): int
    {
        $sql = "INSERT INTO meeting_minutes
                (meeting_id, reference_number, meeting_date, start_time, end_time, venue,
                 chairperson_id, secretary_id, status, version, amendment_reason, aob,
                 next_meeting_date, next_meeting_time, next_meeting_venue, next_meeting_notes,
                 prepared_by, prepared_at, published_by, published_at)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?, ?,?,?,?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            'isssssisssisssssiss',
            $m['meeting_id'], $m['reference_number'], $m['meeting_date'], $m['start_time'], $m['end_time'], $m['venue'],
            $m['chairperson_id'], $m['secretary_id'], $m['status'], $m['version'], $m['amendment_reason'], $m['aob'],
            $m['next_meeting_date'], $m['next_meeting_time'], $m['next_meeting_venue'], $m['next_meeting_notes'],
            $m['prepared_by'], $m['prepared_at'], $m['published_by'], $m['published_at']
        );
        $stmt->execute();
        $id = (int) $this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** Update the minutes header (draft / reopen flow). */
    public function updateMinutes(int $id, array $m): bool
    {
        $sql = "UPDATE meeting_minutes SET
                meeting_date = ?, start_time = ?, end_time = ?, venue = ?,
                chairperson_id = ?, secretary_id = ?, status = ?, version = ?,
                amendment_reason = ?, aob = ?,
                next_meeting_date = ?, next_meeting_time = ?, next_meeting_venue = ?, next_meeting_notes = ?,
                prepared_by = ?, prepared_at = ?, reviewed_by = ?, reviewed_at = ?,
                approved_by = ?, approved_at = ?, published_by = ?, published_at = ?
                WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            'sssssssisssssssiiiiiiii',
            $m['meeting_date'], $m['start_time'], $m['end_time'], $m['venue'],
            $m['chairperson_id'], $m['secretary_id'], $m['status'], $m['version'],
            $m['amendment_reason'], $m['aob'],
            $m['next_meeting_date'], $m['next_meeting_time'], $m['next_meeting_venue'], $m['next_meeting_notes'],
            $m['prepared_by'], $m['prepared_at'], $m['reviewed_by'], $m['reviewed_at'],
            $m['approved_by'], $m['approved_at'], $m['published_by'], $m['published_at'],
            $id
        );
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /** Delete ALL child records for a minutes set (used on replace). */
    public function deleteChildren(int $minutesId): void
    {
        $ids = $minutesId;
        $this->execId("DELETE FROM meeting_minutes_aob_items      WHERE minutes_id = ?", $ids);
        $this->execId("DELETE FROM meeting_minutes_action_items   WHERE minutes_id = ?", $ids);
        $this->execId("DELETE FROM meeting_minutes_decisions      WHERE minutes_id = ?", $ids);
        $this->execId("DELETE FROM meeting_minutes_agenda_items   WHERE minutes_id = ?", $ids);
    }

    // ---- Child inserts (all inside the same transaction) ----

    public function insertAgendaItem(int $minutesId, array $a): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO meeting_minutes_agenda_items (minutes_id, position, agenda_number, title, presenter_id, discussion, decision)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iississ', $minutesId, $a['position'], $a['agenda_number'], $a['title'], $a['presenter_id'], $a['discussion'], $a['decision']);
        $stmt->execute();
        $stmt->close();
    }

    public function insertDecision(int $minutesId, array $d): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO meeting_minutes_decisions (minutes_id, decision_number, resolution, responsible_id, department_id, due_date, status)
            VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iississ', $minutesId, $d['decision_number'], $d['resolution'], $d['responsible_id'], $d['department_id'], $d['due_date'], $d['status']);
        $stmt->execute();
        $stmt->close();
    }

    public function insertActionItem(int $minutesId, array $a): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO meeting_minutes_action_items (minutes_id, action, assigned_to, department_id, due_date, priority, status, remarks)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isiiisss', $minutesId, $a['action'], $a['assigned_to'], $a['department_id'], $a['due_date'], $a['priority'], $a['status'], $a['remarks']);
        $stmt->execute();
        $stmt->close();
    }

    public function insertAobItem(int $minutesId, array $a): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO meeting_minutes_aob_items (minutes_id, item, discussion, decision, action, responsible_id)
            VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('issssi', $minutesId, $a['item'], $a['discussion'], $a['decision'], $a['action'], $a['responsible_id']);
        $stmt->execute();
        $stmt->close();
    }

    private function execId(string $sql, int $minutesId): void
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $minutesId);
        $stmt->execute();
        $stmt->close();
    }

    // =========================================================
    // Attendance / authorization data (from existing tables)
    // =========================================================

    /**
     * Participants for the minutes Attendance tab, categorised by the EXISTING
     * meeting_invitations.response_status + attendance_status + invitation_type.
     */
    public function getParticipantsWithAttendance(int $meetingId): array
    {
        $stmt = $this->conn->prepare("
            SELECT mi.id AS invitation_id,
                   mi.employee_id,
                   mi.response_status,
                   mi.attendance_status,
                   mi.invitation_type,
                   mi.notes,
                   COALESCE(e.first_name, 'Walk-in') AS first_name,
                   COALESCE(e.last_name, 'Attendee') AS last_name,
                   e.designation,
                   e.employee_id AS emp_no
            FROM meeting_invitations mi
            LEFT JOIN employees e ON mi.employee_id = e.id
            WHERE mi.meeting_id = ?
            ORDER BY mi.invitation_type, mi.response_status, e.first_name
        ");
        $stmt->bind_param('i', $meetingId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Resolve the authenticated user's linked employee id (null when none). */
    public function getEmployeeIdByUserId(int $userId): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT e.id
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    /** The authenticated employee's invitation row for a meeting (null = not invited). */
    public function getInvitation(int $meetingId, int $employeeId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT id, response_status, attendance_status, invitation_type
            FROM meeting_invitations
            WHERE meeting_id = ? AND employee_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $meetingId, $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Eligible employees (reused by the minutes editor's people picker). */
    public function getEligibleEmployees(): array
    {
        $result = $this->conn->query("
            SELECT e.id, e.first_name, e.last_name, e.employee_id AS emp_no, e.designation
            FROM employees e
            WHERE e.employee_status = 'active'
            ORDER BY e.first_name, e.last_name
        ");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** All departments for the department dropdowns. */
    public function getDepartments(): array
    {
        $result = $this->conn->query("SELECT id, name FROM departments ORDER BY name ASC");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Map of meeting_id => {status, version} for a batch of meetings (no N+1). */
    public function getMinutesStatusByMeetingIds(array $meetingIds): array
    {
        if ($meetingIds === []) {
            return [];
        }
        $ids = array_map('intval', $meetingIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = $this->conn->prepare("
            SELECT meeting_id, status, version, reference_number
            FROM meeting_minutes
            WHERE meeting_id IN ({$placeholders})
        ");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['meeting_id']] = [
                'status'           => (string) $row['status'],
                'version'          => (int) $row['version'],
                'reference_number' => (string) $row['reference_number'],
            ];
        }
        return $map;
    }

    /** Map of meeting_id => invitation row for one employee, for a batch of meetings. */
    public function getInvitationByMeetingIds(array $meetingIds, int $employeeId): array
    {
        if ($meetingIds === []) {
            return [];
        }
        $ids = array_map('intval', $meetingIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)) . 'i';

        $stmt = $this->conn->prepare("
            SELECT meeting_id, response_status
            FROM meeting_invitations
            WHERE meeting_id IN ({$placeholders}) AND employee_id = ?
        ");
        $params = array_merge($ids, [$employeeId]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

                $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['meeting_id']] = $row;
        }
        return $map;
    }

    /**
     * Get a lightweight minutes info block for list-decoration.
     *
     * Returns whether minutes exist, their status, and whether the current
     * user can manage (edit/draft) or view (published) them. Uses the
     * server-side Auth/permission context — the caller must not trust
     * any client-supplied identity.
          */
    public function getMeetingMinutesInfo(int $meetingId, ?int $userId, ?int $employeeId): array
    {
        // Is the caller authorized to manage minutes for this meeting?
        // Uses the existing RBAC helper (hybrid RBAC + user overrides) for
        // consistency with the rest of the codebase — no frontend role logic.
        $canManage = false;
        if ($userId) {
            $auth = \App\Helpers\Auth::getInstance();
            $canManage = $auth->hasAnyPermission([
                ['meetings' => 'minutes.create'],
                ['meetings' => 'minutes.update'],
                ['meetings' => 'minutes.publish'],
                ['meetings' => 'minutes.amend'],
            ]);
        }

        // Fetch minutes status (if any) for this meeting.
        $stmt = $this->conn->prepare(
            "SELECT mm.status, mm.version
             FROM meeting_minutes mm
             WHERE mm.meeting_id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $meetingId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return [
                'exists'             => false,
                'status'             => null,
                'can_manage'         => $canManage,
                'can_view_published' => false,
            ];
        }

        // For published minutes: confirmed invitees may view.
        $canViewPublished = false;
        if ($row['status'] === 'published' && $employeeId) {
            $ivStmt = $this->conn->prepare(
                "SELECT response_status FROM meeting_invitations
                 WHERE meeting_id = ? AND employee_id = ?
                 LIMIT 1"
            );
            $ivStmt->bind_param('ii', $meetingId, $employeeId);
            $ivStmt->execute();
            $ivRow = $ivStmt->get_result()->fetch_assoc();
            $ivStmt->close();
            $canViewPublished = $ivRow && $ivRow['response_status'] === 'accepted';
        }

        return [
            'exists'             => true,
            'status'             => $row['status'],
            'version'            => (int)$row['version'],
            'can_manage'         => $canManage,
            'can_view_published' => $canViewPublished,
        ];
    }

    /**
     * Get the role name for a given user id (delegates to users/roles tables).
     */
    private function getUserRole(int $userId): ?string
    {
        $stmt = $this->conn->prepare(
            "SELECT r.name AS role_name
             FROM users u
             LEFT JOIN roles r ON u.role_id = r.id
             WHERE u.id = ?
             LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row['role_name'] : null;
    }

    /**
     * Check whether a role has a given permission (matches the schema
     * used by role_permissions: module + action columns).
     */
    private function hasPermission(?string $role, string $module, string $action): bool
    {
        if (!$role) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM role_permissions
             WHERE role = ? AND module = ? AND action = ?
             LIMIT 1"
        );
        $stmt->bind_param('sss', $role, $module, $action);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result->fetch_assoc() !== null;
    }
}
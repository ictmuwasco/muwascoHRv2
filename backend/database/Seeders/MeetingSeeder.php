<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;
use App\Database\DatabaseConnection;

/**
 * Meeting Seeder
 *
 * Seeds realistic demo data for the Meetings module:
 *   - meetings               (7 meetings across every lifecycle status)
 *   - meeting_invitations    (RSVP + attendance mix, incl. the admin user's
 *                             own employee record so /my-meetings has data)
 *   - meeting_minutes + the 4 child tables (one PUBLISHED minutes set for
 *     the completed Q1 review, so minutes read flows have data)
 *
 * Idempotent: skips when meetings already contain rows. Use fresh mode
 * (php backend/database/seed.php MeetingSeeder --fresh) to wipe meetings
 * first — FK ON DELETE CASCADE removes invitations + minutes with them.
 */
class MeetingSeeder extends Seeder
{
    private bool $fresh = false;

    /** Resolved after run(): seeded meeting ids keyed by short name. */
    private array $meetingIds = [];

    public function fresh(bool $fresh = true): self
    {
        $this->fresh = $fresh;
        return $this;
    }

    public function run(): void
    {
        $conn = DatabaseConnection::getInstance()->getConnection();

        if ($this->fresh) {
            // FK ON DELETE CASCADE wipes meeting_invitations and
            // meeting_minutes (+ its 4 child tables) together with meetings.
            $conn->executeStatement('DELETE FROM meetings');
            echo "  - wiped existing meeting data (fresh mode)\n";
        }

        if (!$this->isEmpty('meetings')) {
            echo "  - meetings table already seeded; skipping (use --fresh to reseed)\n";
            return;
        }

        [$adminUserId, $adminEmployeeId, $participants] = $this->resolvePeople($conn);

        $this->seedMeetings($adminUserId);
        $this->seedInvitations($adminEmployeeId, $participants);
        $this->seedPublishedMinutes($adminEmployeeId, $participants);

        echo "  - seeded " . count($this->meetingIds) . " meetings"
            . " (+ invitations, one published minutes set)\n";
    }

    /**
     * Resolve seed actors: the admin user (users.id=1), their employee
     * record (for /my-meetings) and a pool of real active employees.
     *
     * @return array{0:int,1:int,2:list<int>} [admin user id, admin employee id, participant employee ids]
     */
    private function resolvePeople(object $conn): array
    {
        $adminUserId = (int) ($conn->executeQuery(
            'SELECT id FROM users ORDER BY id ASC LIMIT 1'
        )->fetchOne() ?? 1);

        // Admin's own employee record — matched through users.employee_id so
        // /auth/user, /my-meetings and invitation flows all line up.
        $adminEmployeeId = (int) ($conn->executeQuery(
            'SELECT e.id FROM employees e JOIN users u ON u.employee_id = e.employee_id WHERE u.id = ? LIMIT 1',
            [$adminUserId]
        )->fetchOne() ?: 1);

        // A stable pool of active employees for invitations (never the admin
        // record itself — it is added explicitly where needed).
        $participants = array_map(
            'intval',
            $conn->executeQuery(
                "SELECT id FROM employees
                 WHERE employee_status = 'active' AND id <> ?
                 ORDER BY id ASC LIMIT 8",
                [$adminEmployeeId]
            )->fetchFirstColumn()
        );

        return [$adminUserId, $adminEmployeeId, $participants];
    }

    /** Insert the demo meetings (all four lifecycle statuses represented). */
    private function seedMeetings(int $adminUserId): void
    {
        $token = static fn (): string => bin2hex(random_bytes(32));

        $meetings = [
            [
                'key'          => 'q1_review',
                'title'        => 'Q1 Performance Appraisal Panel',
                'description'  => 'Quarterly appraisal panel reviewing departmental KPI performance and contract renewals.',
                'agenda'       => "1.0 KPI performance summary\n2.0 Appraisal outcomes\n3.0 Contract renewals",
                'meeting_date' => date('Y-m-d', strtotime('-14 days')),
                'start_time'   => '10:00:00',
                'end_time'     => '13:00:00',
                'location'     => 'Boardroom, HQ',
                'status'       => 'completed',
                'created_at'   => date('Y-m-d H:i:s', strtotime('-21 days')),
            ],
            [
                'key'          => 'weekly_ops',
                'title'        => 'Weekly Operations Briefing',
                'description'  => 'Standing weekly briefing on production volumes, non-revenue water and crew rotation.',
                'agenda'       => "1.0 Production update\n2.0 NRW status\n3.0 Crew allocation",
                'meeting_date' => date('Y-m-d'),
                'start_time'   => '14:00:00',
                'end_time'     => '15:30:00',
                'location'     => 'Conference Room A',
                'status'       => 'ongoing',
                'created_at'   => date('Y-m-d H:i:s', strtotime('-2 days')),
            ],
            [
                'key'          => 'mgmt_review',
                'title'        => 'Quarterly Management Review',
                'description'  => 'Management review of strategic plan progress, budget execution and audit observations.',
                'agenda'       => "1.0 Strategic plan progress\n2.0 Budget execution\n3.0 Audit matters",
                'meeting_date' => date('Y-m-d', strtotime('+7 days')),
                'start_time'   => '09:00:00',
                'end_time'     => '12:00:00',
                'location'     => 'Boardroom, HQ',
                'status'       => 'scheduled',
                'created_at'   => date('Y-m-d H:i:s', strtotime('-1 day')),
            ],
            [
                'key'          => 'safety_brief',
                'title'        => 'Safety and Compliance Briefing',
                'description'  => 'Occupational safety refresher and water-quality compliance update for field crews.',
                'agenda'       => "1.0 Safety incidents review\n2.0 Compliance calendar",
                'meeting_date' => date('Y-m-d', strtotime('+3 days')),
                'start_time'   => '08:30:00',
                'end_time'     => '10:00:00',
                'location'     => 'Training Hall',
                'status'       => 'scheduled',
                'created_at'   => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insertMeetings($meetings, $adminUserId, $token);
        $this->seedRemainingMeetings($adminUserId, $token);
    }

    /** Remaining three meetings (completed / cancelled / scheduled tomorrow). */
    private function seedRemainingMeetings(int $adminUserId, callable $token): void
    {
        $meetings = [
            [
                'key'          => 'outage_response',
                'title'        => 'Emergency Water Outage Response',
                'description'  => 'Coordination meeting for the planned maintenance outage on the Kabati line.',
                'agenda'       => "1.0 Outage scope\n2.0 Public communication\n3.0 Crew deployment",
                'meeting_date' => date('Y-m-d', strtotime('+1 day')),
                'start_time'   => '07:30:00',
                'end_time'     => '09:00:00',
                'location'     => 'Command Centre',
                'status'       => 'scheduled',
                'created_at'   => date('Y-m-d H:i:s'),
            ],
            [
                'key'          => 'vendor_negotiation',
                'title'        => 'Vendor Contract Negotiation',
                'description'  => 'Pipeline chemicals supply contract negotiation with the shortlisted vendor.',
                'agenda'       => "1.0 Commercial terms\n2.0 Delivery schedule",
                'meeting_date' => date('Y-m-d', strtotime('-3 days')),
                'start_time'   => '11:00:00',
                'end_time'     => '13:00:00',
                'location'     => 'Procurement Office',
                'status'       => 'cancelled',
                'created_at'   => date('Y-m-d H:i:s', strtotime('-10 days')),
            ],
            [
                'key'          => 'agm_briefing',
                'title'        => 'Annual General Staff Briefing',
                'description'  => 'Annual all-staff briefing covering performance, welfare and the year ahead.',
                'agenda'       => "1.0 Managing Director address\n2.0 HR updates\n3.0 Q&A",
                'meeting_date' => date('Y-m-d', strtotime('-30 days')),
                'start_time'   => '09:00:00',
                'end_time'     => '11:00:00',
                'location'     => 'Main Hall, HQ',
                'status'       => 'completed',
                'created_at'   => date('Y-m-d H:i:s', strtotime('-45 days')),
            ],
        ];

        $this->insertMeetings($meetings, $adminUserId, $token);
    }

    /** Shared insert loop — one row per meeting, ids captured by key. */
    private function insertMeetings(array $meetings, int $adminUserId, callable $token): void
    {
        $conn = DatabaseConnection::getInstance()->getConnection();

        foreach ($meetings as $m) {
            $key = $m['key'];
            unset($m['key']);
            $conn->executeStatement(
                'INSERT INTO meetings (title, description, agenda, meeting_date, start_time, end_time, location, status, created_by, attendance_token, notification_sent_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $m['title'], $m['description'], $m['agenda'],
                    $m['meeting_date'], $m['start_time'], $m['end_time'],
                    $m['location'], $m['status'], $adminUserId,
                    $token(), null, $m['created_at'],
                ]
            );
            $this->meetingIds[$key] = (int) $conn->lastInsertId();
        }
    }

    /**
     * Seed meeting_invitations: RSVP mix (accepted/pending/declined/tentative)
     * and attendance mix (present/absent/not_marked). The admin's own employee
     * record is invited to most meetings so /my-meetings returns data.
     *
     * @param list<int> $participants active employee ids (seed pool)
     */
    private function seedInvitations(int $adminEmployeeId, array $participants): void
    {
        $conn = DatabaseConnection::getInstance()->getConnection();
        $pick = static fn (int $i) => $participants[$i] ?? null;
        $p = [$pick(0), $pick(1), $pick(2), $pick(3), $pick(4), $pick(5), $pick(6), $pick(7)];
        $now = date('Y-m-d H:i:s');

        // [meeting key, employee id, response_status, attendance_status, invited days ago]
        $rows = [
            ['q1_review',        $adminEmployeeId, 'accepted', 'present',     20],
            ['q1_review',        $p[0],            'accepted', 'present',     20],
            ['q1_review',        $p[1],            'declined', 'absent',      20],
            ['q1_review',        $p[2],            'accepted', 'present',     20],
            ['weekly_ops',       $adminEmployeeId, 'accepted', 'present',      2],
            ['weekly_ops',       $p[3],            'accepted', 'present',      2],
            ['weekly_ops',       $p[4],            'accepted', 'present',      2],
            ['weekly_ops',       $p[5],            'pending',  'not_marked',   2],
            ['mgmt_review',      $adminEmployeeId, 'accepted', 'not_marked',   1],
            ['mgmt_review',      $p[0],            'accepted', 'not_marked',   1],
            ['mgmt_review',      $p[1],            'pending',  'not_marked',   1],
            ['mgmt_review',      $p[2],            'tentative','not_marked',   1],
            ['safety_brief',     $adminEmployeeId, 'pending',  'not_marked',   0],
            ['safety_brief',     $p[6],            'accepted', 'not_marked',   0],
            ['outage_response',  $adminEmployeeId, 'accepted', 'not_marked',   0],
            ['outage_response',  $p[3],            'pending',  'not_marked',   0],
            ['outage_response',  $p[4],            'pending',  'not_marked',   0],
            ['outage_response',  $p[5],            'pending',  'not_marked',   0],
            ['vendor_negotiation', $p[0],          'accepted', 'not_marked',  10],
            ['vendor_negotiation', $p[1],          'declined', 'not_marked',  10],
            ['vendor_negotiation', $p[2],          'pending',  'not_marked',  10],
            ['agm_briefing',     $adminEmployeeId, 'accepted', 'present',     44],
            ['agm_briefing',     $p[6],            'accepted', 'present',     44],
            ['agm_briefing',     $p[7],            'accepted', 'absent',      44],
            ['agm_briefing',     $p[5],            'tentative','not_marked',  44],
        ];

        $inserted = 0;
        foreach ($rows as [$key, $employeeId, $response, $attendance, $daysAgo]) {
            if (!$employeeId) {
                continue; // participant pool smaller than expected on this DB
            }
            $invitedAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
            $respondedAt = in_array($response, ['accepted', 'declined', 'tentative'], true)
                ? date('Y-m-d H:i:s', strtotime("-{$daysAgo} days +2 hours"))
                : null;
            $markedAt = in_array($attendance, ['present', 'absent'], true)
                ? $now
                : null;

            $conn->executeStatement(
                'INSERT INTO meeting_invitations
                    (meeting_id, employee_id, invited_by, invited_at, invitation_type,
                     response_status, responded_at, attendance_status, attendance_marked_at, attendance_marked_by, notes, created_at)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $this->meetingIds[$key], $employeeId, $invitedAt, 'hr_invited',
                    $response, $respondedAt, $attendance, $markedAt,
                    $markedAt !== null ? 1 : null, null, $invitedAt,
                ]
            );
            $inserted++;
        }

        echo "  - seeded {$inserted} meeting invitations\n";
    }

    /**
     * Seed a PUBLISHED minutes set (with all 4 child tables) for the completed
     * Q1 review, so minutes read/amend flows have data. Other meetings are
     * left WITHOUT minutes so the create/update/publish API flow can be tested.
     */
    private function seedPublishedMinutes(int $adminEmployeeId, array $participants): void
    {
        $conn = DatabaseConnection::getInstance()->getConnection();
        $meetingId = $this->meetingIds['q1_review'] ?? 0;
        if (!$meetingId) {
            return;
        }

        $chairperson = $adminEmployeeId;
        $secretary   = $participants[0] ?? null;
        $now = date('Y-m-d H:i:s', strtotime('-13 days'));
        $reference = 'MMS-' . $meetingId . '-' . date('Y', strtotime('-14 days'));

        $conn->executeStatement(
            'INSERT INTO meeting_minutes
                (meeting_id, reference_number, meeting_date, start_time, end_time, venue,
                 chairperson_id, secretary_id, status, version, amendment_reason, aob,
                 next_meeting_date, next_meeting_time, next_meeting_venue, next_meeting_notes,
                 prepared_by, prepared_at, reviewed_by, reviewed_at, approved_by, approved_at,
                 published_by, published_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $meetingId, $reference,
                date('Y-m-d', strtotime('-14 days')), '10:00:00', '13:00:00', 'Boardroom, HQ',
                $chairperson, $secretary, 'published', 1, null,
                'Staff welfare concerns raised; referred to the welfare committee.',
                date('Y-m-d', strtotime('+16 days')), '10:00:00', 'Boardroom, HQ',
                'Follow-up appraisal panel to review contract renewals.',
                1, $now, null, null, null, null,
                1, $now, $now,
            ]
        );
        $minutesId = (int) $conn->lastInsertId();

        echo "  - seeded published minutes '{$reference}' (id {$minutesId}) with child records\n";

        $this->seedMinutesChildren($conn, $minutesId, $participants);
    }

    /** Seed the 4 minutes child tables for the published minutes set. */
    private function seedMinutesChildren(object $conn, int $minutesId, array $participants): void
    {
        $emp = static fn (int $i) => $participants[$i] ?? null;

        // --- Agenda items -------------------------------------------------
        $agenda = [
            [1, '1.0', 'KPI performance summary', $emp(0)],
            [2, '2.0', 'Appraisal outcomes by department', $emp(1)],
            [3, '3.0', 'Contract renewals and pending confirmations', $emp(2)],
        ];
        foreach ($agenda as [$position, $number, $title, $presenter]) {
            $conn->executeStatement(
                'INSERT INTO meeting_minutes_agenda_items (minutes_id, position, agenda_number, title, presenter_id, discussion, decision)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $minutesId, $position, $number, $title, $presenter,
                    'Summary presented and discussed; clarifications sought on scoring variance.',
                    'Performance summary adopted as presented.',
                ]
            );
        }

        // --- Decisions ----------------------------------------------------
        $decisions = [
            ['D-01', 'Adopt the Q1 KPI performance summary as a true record of departmental performance.', $emp(0), 3, 'completed'],
            ['D-02', 'Fast-track contract renewal review for officers flagged in the appraisal report.', $emp(1), 4, 'in_progress'],
            ['D-03', 'Schedule a follow-up panel to resolve pending appraisal disputes.', $emp(2), 5, 'pending'],
        ];
        foreach ($decisions as [$number, $resolution, $responsible, $dueInDays, $status]) {
            $conn->executeStatement(
                'INSERT INTO meeting_minutes_decisions (minutes_id, decision_number, resolution, responsible_id, department_id, due_date, status)
                 VALUES (?, ?, ?, ?, 3, ?, ?)',
                [$minutesId, $number, $resolution, $responsible, date('Y-m-d', strtotime("+{$dueInDays} days")), $status]
            );
        }

        // --- Action items -------------------------------------------------
        $actions = [
            ['Circulate the Q1 appraisal summary to all department heads.', $emp(1), 'high', 'completed'],
            ['Prepare renewal letters for the flagged contract officers.', $emp(2), 'critical', 'in_progress'],
            ['Compile the appraisal dispute register for the follow-up panel.', $emp(3), 'medium', 'pending'],
        ];
        foreach ($actions as [$action, $assignedTo, $priority, $status]) {
            $conn->executeStatement(
                'INSERT INTO meeting_minutes_action_items (minutes_id, action, assigned_to, department_id, due_date, priority, status, remarks)
                 VALUES (?, ?, ?, 3, ?, ?, ?, ?)',
                [$minutesId, $action, $assignedTo, date('Y-m-d', strtotime('+7 days')), $priority, $status, null]
            );
        }

        // --- AOB items ----------------------------------------------------
        $aob = [
            ['Staff welfare', 'Welfare committee funding gap raised.', 'Referred to the welfare committee for a proposal.', $emp(4)],
            ['Office space', 'Additional workspace requested for the records section.', 'Action deferred pending budget review.', $emp(5)],
        ];
        foreach ($aob as [$item, $discussion, $decision, $responsible]) {
            $conn->executeStatement(
                'INSERT INTO meeting_minutes_aob_items (minutes_id, item, discussion, decision, action, responsible_id)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$minutesId, $item, $discussion, $decision, 'Follow up within 14 days.', $responsible]
            );
        }
    }
}

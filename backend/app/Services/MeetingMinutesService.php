<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Repositories\MeetingMinutesRepository;
use App\Repositories\MeetingRepository;
use App\Services\AuditService;
use InvalidArgumentException;

/**
 * MeetingMinutesService
 *
 * Core business logic for the Meeting Minutes module. Enforces:
 *  - Hybrid RBAC permissions (meetings.minutes.*)
 *  - The strict view-access rule: published minutes are visible ONLY to
 *    confirmed invitees (response_status = 'accepted') or to users with the
 *    meetings.minutes.view permission. Declined / pending / never-invited
 *    users and unauthenticated callers are DENIED at the service layer.
 *  - Transactional persistence of the minutes header + child records.
 *  - Draft lifecycle (draft -> published, published immutable until reopen).
 *
 * Attendance is derived from the EXISTING meeting_invitations table,
 * never duplicated.
 */
class MeetingMinutesService
{
    private MeetingMinutesRepository $repo;
    private MeetingRepository $meetingRepository;

    public function __construct(?MeetingMinutesRepository $repo = null)
    {
        $this->repo = $repo ?? new MeetingMinutesRepository();
        $this->meetingRepository = new MeetingRepository();
    }

    /** Reference format: MMS-{meeting_id}-{year}. */
    private function referenceFor(int $meetingId): string
    {
        return 'MMS-' . $meetingId . '-' . date('Y');
    }

    /**
     * Editor options: eligible employees + departments for the pickers.
     */
    public function options(int $meetingId): array
    {
        $this->assertMeetingExists($meetingId);
        return [
            'employees'    => $this->repo->getEligibleEmployees(),
            'departments'  => $this->repo->getDepartments(),
        ];
    }

    /**
     * Resolve what the CURRENT user may do with this meeting's minutes.
     * Mirrors controller authorization so the UI only renders permitted
     * actions (defense-in-depth; the backend still enforces everywhere).
     */
    public function status(int $meetingId, ?int $userId = null): array
    {
        $userId ??= Auth::getInstance()->id();
        $this->assertMeetingExists($meetingId);

        $minutes = $this->repo->findByMeetingId($meetingId);
        $canManage = $this->canManageMinutes();
        $status = $minutes ? $minutes['status'] : null;

        return [
            'exists'            => $minutes !== null,
            'status'            => $status,
            'version'           => $minutes ? (int) $minutes['version'] : 1,
            'reference_number'  => $minutes['reference_number'] ?? $this->referenceFor($meetingId),
            'can_create'        => !$minutes && $canManage,
            'can_edit_draft'    => $canManage && $minutes !== null && $status === 'draft',
            'can_publish'       => $canManage && $minutes !== null && $status === 'draft',
            'can_reopen'        => $canManage && $minutes !== null && $status === 'published',
            'can_view'          => $canManage
                ? $minutes !== null
                : ($minutes !== null && $status === 'published' && $this->isConfirmedInvitee($meetingId, $userId)),
        ];
    }

    /**
     * Create minutes as a DRAFT (or published when publish=true).
     * Wraps header + all child records in one transaction.
     *
     * @return int minutes id
     */
    public function create(int $meetingId, array $raw, int $userId): int
    {
        if (!$this->canManageMinutes()) {
            throw new InvalidArgumentException('You do not have permission to manage meeting minutes.', 403);
        }

        if ($this->repo->findByMeetingId($meetingId) !== null) {
            throw new InvalidArgumentException('Minutes already exist for this meeting. Edit the existing minutes instead.', 409);
        }

        $meeting = $this->ensureMeeting($meetingId);
        $payload = $this->normalizePayload($raw);
        $this->validateStructure($payload);

        $now = date('Y-m-d H:i:s');
        $publish = !empty($raw['publish']) || ($payload['status'] ?? '') === 'published';

        $header = $this->buildHeader($meeting, $meetingId, $payload, $userId, $now);
        $header['status']       = $publish ? 'published' : 'draft';
        $header['version']      = 1;
        $header['prepared_by']  = $userId;
        $header['prepared_at']  = $now;
        $header['published_by'] = $publish ? $userId : null;
        $header['published_at'] = $publish ? $now : null;

        $conn = $this->dbConn();
        $conn->begin_transaction();
        try {
            $id = $this->repo->insertMinutes($header);
            $this->replaceChildren($id, $payload);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_MINUTES_CREATED,
            'Meeting minutes created for meeting #' . $meetingId,
            ['target_type' => 'meeting_minutes', 'target_id' => $id, 'target_name' => $header['reference_number']]
        );
        if ($publish) {
            $this->auditPublished($meetingId, $id, $header['reference_number'], $userId);
        }

        return $id;
    }

    /**
     * Update DRAFT minutes (or a reopened draft) - replaces all child records
     * transactionally. Published minutes are immutable; use reopen() first.
     */
    public function update(int $meetingId, array $raw, int $userId): bool
    {
        if (!$this->canManageMinutes()) {
            throw new InvalidArgumentException('You do not have permission to manage meeting minutes.', 403);
        }

        $minutes = $this->repo->findByMeetingId($meetingId);
        if (!$minutes) {
            throw new InvalidArgumentException('Minutes not found for this meeting. Create them first.', 404);
        }
        $minutesId = (int) $minutes['id'];

        if (($minutes['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Published minutes are immutable. Reopen the minutes to amend them.', 409);
        }

        $meeting = $this->ensureMeeting($meetingId);
        $payload = $this->normalizePayload($raw);
        $this->validateStructure($payload);

        $now = date('Y-m-d H:i:s');
        $header = $this->buildHeader($meeting, $meetingId, $payload, $userId, $now);
        $header['status']      = 'draft';
        $header['version']     = (int) $minutes['version'];
        $header['prepared_by'] = $userId;
        $header['prepared_at'] = $now;

        $conn = $this->dbConn();
        $conn->begin_transaction();
        try {
            $this->repo->updateMinutes($minutesId, $header);
            $this->replaceChildren($minutesId, $payload);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_MINUTES_UPDATED,
            'Meeting minutes draft updated for meeting #' . $meetingId,
            ['target_type' => 'meeting_minutes', 'target_id' => $minutesId, 'target_name' => $header['reference_number']]
        );

        return true;
    }

    /**
     * Publish draft minutes. Requires meetings.minutes.publish permission,
     * validates the structure, marks status 'published', records the author
     * + timestamp, and writes an audit event.
     */
    public function publish(int $meetingId, array $raw, int $userId): bool
    {
        if (!$this->hasPermission('meetings.minutes.publish')) {
            throw new InvalidArgumentException('You do not have permission to publish meeting minutes.', 403);
        }

        $minutes = $this->repo->findByMeetingId($meetingId);
        if (!$minutes) {
            throw new InvalidArgumentException('Minutes not found for this meeting. Create them first.', 404);
        }
        $minutesId = (int) $minutes['id'];

        if (($minutes['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Minutes are already published.', 409);
        }

        // Publishing saves the current form state + finalizes the record.
        $meeting = $this->ensureMeeting($meetingId);
        $payload = $this->normalizePayload($raw);
        $this->validateStructure($payload, true);

        $now = date('Y-m-d H:i:s');
        $header = $this->buildHeader($meeting, $meetingId, $payload, $userId, $now);
        $header['status']       = 'published';
        $header['version']      = (int) $minutes['version'];
        $header['prepared_by']  = $userId;
        $header['prepared_at']  = $now;
        $header['published_by'] = $userId;
        $header['published_at'] = $now;

        $conn = $this->dbConn();
        $conn->begin_transaction();
        try {
            $this->repo->updateMinutes($minutesId, $header);
            $this->replaceChildren($minutesId, $payload);
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        $this->auditPublished($meetingId, $minutesId, $header['reference_number'], $userId);
        return true;
    }

    /**
     * Reopen published minutes for amendment (meetings.minutes.amend).
     * Bumps the version, resets status to draft, records the reason.
     */
    public function reopen(int $meetingId, string $reason, int $userId): bool
    {
        if (!$this->hasPermission('meetings.minutes.amend')) {
            throw new InvalidArgumentException('You do not have permission to amend meeting minutes.', 403);
        }

        $minutes = $this->repo->findByMeetingId($meetingId);
        if (!$minutes) {
            throw new InvalidArgumentException('Minutes not found for this meeting.', 404);
        }
        if (($minutes['status'] ?? '') !== 'published') {
            throw new InvalidArgumentException('Only published minutes can be reopened for amendment.', 409);
        }

        if (trim((string) $reason) === '') {
            throw new InvalidArgumentException('An amendment reason is required to reopen minutes.', 422);
        }

        $minutesId   = (int) $minutes['id'];
        $newVersion  = (int) $minutes['version'] + 1;
        $conn = $this->dbConn();
        $stmt = $conn->prepare("
            UPDATE meeting_minutes
            SET status = 'draft', version = ?, amendment_reason = ?, published_by = NULL, published_at = NULL
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $newVersion, $reason, $minutesId);
        $stmt->execute();
        $stmt->close();

        AuditService::getInstance()->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_MINUTES_REOPENED,
            'Meeting minutes reopened for amendment (v' . $newVersion . ') for meeting #' . $meetingId,
            [
                'target_type' => 'meeting_minutes',
                'target_id'   => $minutesId,
                'target_name' => $minutes['reference_number'] ?? null,
                'metadata'    => ['reason' => $reason, 'new_version' => $newVersion],
            ]
        );

        return true;
    }

    /**
     * View minutes with full authorization.
     *
     *  - minutes.view permission holders can always view.
     *  - Otherwise ONLY published minutes AND the caller is a confirmed
     *    invitee (response_status = 'accepted').
     *  - Declined / pending / never-invited / unauthenticated -> 403.
     *
     * @return array{minutes:?array, participants:array, authorized:bool}
     */
    public function view(int $meetingId, ?int $userId = null): array
    {
        $userId ??= Auth::getInstance()->id();
        if ($userId <= 0) {
            throw new InvalidArgumentException('Authentication required', 401);
        }

        $minutes = $this->repo->findByMeetingId($meetingId);
        if (!$minutes) {
            // Distinguish "no minutes yet" from "forbidden to view".
            $this->ensureMeeting($meetingId);
            return ['minutes' => null, 'participants' => [], 'authorized' => $this->canManageMinutes()];
        }

        $canManage = $this->canManageMinutes();
        $isPublished = ($minutes['status'] ?? '') === 'published';

        if (!$canManage) {
            if (!$isPublished || !$this->isConfirmedInvitee($meetingId, $userId)) {
                throw new InvalidArgumentException('You are not authorised to view these meeting minutes.', 403);
            }
        }

        $minutesId = (int) $minutes['id'];
        $result = $this->hydrate($minutes, $meetingId);
        $participants = $this->repo->getParticipantsWithAttendance($meetingId);

        // Audit a VIEW by any non-owner/manager as an official read.
        if (!$canManage) {
            AuditService::getInstance()->log(
                AuditService::MODULE_MEETINGS,
                AuditService::ACTION_MINUTES_VIEWED,
                'Meeting minutes viewed: ' . ($minutes['reference_number'] ?? ''),
                ['target_type' => 'meeting_minutes', 'target_id' => $minutesId]
            );
        }

        return ['minutes' => $result, 'participants' => $participants, 'authorized' => true];
    }

    // =========================================================
    // Internal helpers
    // =========================================================

    private function hydrate(array $minutes, int $meetingId): array
    {
        $minutesId = (int) $minutes['id'];
        return array_merge($minutes, [
            'agenda_items'  => $this->repo->getAgendaItems($minutesId),
            'decisions'     => $this->repo->getDecisions($minutesId),
            'action_items'  => $this->repo->getActionItems($minutesId),
            'aob_items'     => $this->repo->getAobItems($minutesId),
        ]);
    }

    private function dbConn(): \mysqli
    {
        return Database::getInstance()->getConnection();
    }

    private function buildHeader(array $meeting, int $meetingId, array $p, int $userId, string $now): array
    {
        return [
            'meeting_id'           => $meetingId,
            'reference_number'     => $p['reference_number'] ?: $this->referenceFor($meetingId),
            'meeting_date'         => $p['meeting_date'] ?: ($meeting['meeting_date'] ?? null),
            'start_time'           => $p['start_time'] ?: ($meeting['start_time'] ?? null),
            'end_time'             => $p['end_time'] ?: ($meeting['end_time'] ?? null),
            'venue'                => $p['venue'] ?: ($meeting['location'] ?? null),
            'chairperson_id'       => $this->nullInt($p['chairperson_id']),
            'secretary_id'         => $this->nullInt($p['secretary_id']),
            'amendment_reason'     => $this->nullString($p['amendment_reason']),
            'aob'                  => $this->nullString($p['aob']),
            'next_meeting_date'    => $this->nullString($p['next_meeting_date']),
            'next_meeting_time'    => $this->nullString($p['next_meeting_time']),
            'next_meeting_venue'   => $this->nullString($p['next_meeting_venue']),
            'next_meeting_notes'   => $this->nullString($p['next_meeting_notes']),
            'reviewed_by'          => $this->nullInt($p['reviewed_by']),
            'reviewed_at'          => $this->nullString($p['reviewed_at']),
            'approved_by'          => $this->nullInt($p['approved_by']),
            'approved_at'          => $this->nullString($p['approved_at']),
        ];
    }

    /** Whitelist payload fields into a clean structure with child arrays. */
    private function normalizePayload(array $raw): array
    {
        return [
            'reference_number'   => $this->nullString($raw['reference_number'] ?? null),
            'meeting_date'       => $this->nullString($raw['meeting_date'] ?? null),
            'start_time'         => $this->nullString($raw['start_time'] ?? null),
            'end_time'           => $this->nullString($raw['end_time'] ?? null),
            'venue'              => $this->nullString($raw['venue'] ?? null),
            'chairperson_id'     => $this->nullInt($raw['chairperson_id'] ?? null),
            'secretary_id'       => $this->nullInt($raw['secretary_id'] ?? null),
            'aob'                => $this->nullString($raw['aob'] ?? null),
            'status'             => $raw['status'] ?? '',
            'next_meeting_date'  => $this->nullString($raw['next_meeting_date'] ?? null),
            'next_meeting_time'  => $this->nullString($raw['next_meeting_time'] ?? null),
            'next_meeting_venue' => $this->nullString($raw['next_meeting_venue'] ?? null),
            'next_meeting_notes' => $this->nullString($raw['next_meeting_notes'] ?? null),
            'amendment_reason'   => $this->nullString($raw['amendment_reason'] ?? null),
            'reviewed_by'        => $this->nullInt($raw['reviewed_by'] ?? null),
            'reviewed_at'        => $this->nullString($raw['reviewed_at'] ?? null),
            'approved_by'        => $this->nullInt($raw['approved_by'] ?? null),
            'approved_at'        => $this->nullString($raw['approved_at'] ?? null),
            'agenda_items'       => $this->normalizeAgenda($raw['agenda_items'] ?? []),
            'decisions'          => $this->normalizeDecisions($raw['decisions'] ?? []),
            'action_items'       => $this->normalizeActions($raw['action_items'] ?? []),
            'aob_items'          => $this->normalizeAob($raw['aob_items'] ?? []),
        ];
    }

    private function normalizeAgenda(array $items): array
    {
        $out = [];
        $i = 0;
        foreach ($items as $item) {
            $i++;
            $out[] = [
                'position'      => (int) ($item['position'] ?? $i),
                'agenda_number' => $this->nullString($item['agenda_number'] ?? null),
                'title'         => trim((string) ($item['title'] ?? '')),
                'presenter_id'  => $this->nullInt($item['presenter_id'] ?? null),
                'discussion'    => $this->nullString($item['discussion'] ?? null),
                'decision'      => $this->nullString($item['decision'] ?? null),
            ];
        }
        return $out;
    }

    private function normalizeDecisions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'decision_number' => $this->nullString($item['decision_number'] ?? null),
                'resolution'      => trim((string) ($item['resolution'] ?? '')),
                'responsible_id'  => $this->nullInt($item['responsible_id'] ?? null),
                'department_id'   => $this->nullInt($item['department_id'] ?? null),
                'due_date'        => $this->nullString($item['due_date'] ?? null),
                'status'          => in_array(($item['status'] ?? ''), ['pending', 'in_progress', 'completed', 'deferred', 'cancelled'], true)
                    ? (string) $item['status'] : 'pending',
            ];
        }
        return $out;
    }

    private function normalizeActions(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'action'        => trim((string) ($item['action'] ?? '')),
                'assigned_to'   => $this->nullInt($item['assigned_to'] ?? null),
                'department_id' => $this->nullInt($item['department_id'] ?? null),
                'due_date'      => $this->nullString($item['due_date'] ?? null),
                'priority'      => in_array(($item['priority'] ?? ''), ['low', 'medium', 'high', 'critical'], true)
                    ? (string) $item['priority'] : 'medium',
                'status'        => in_array(($item['status'] ?? ''), ['pending', 'in_progress', 'completed', 'overdue', 'deferred', 'cancelled'], true)
                    ? (string) $item['status'] : 'pending',
                'remarks'       => $this->nullString($item['remarks'] ?? null),
            ];
        }
        return $out;
    }

    private function normalizeAob(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = [
                'item'           => trim((string) ($item['item'] ?? '')),
                'discussion'     => $this->nullString($item['discussion'] ?? null),
                'decision'       => $this->nullString($item['decision'] ?? null),
                'action'         => $this->nullString($item['action'] ?? null),
                'responsible_id' => $this->nullInt($item['responsible_id'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Validate required structure. On publish ($strict) require a non-empty
     * reference and at least one agenda item + decision (a professional
     * minutes document is not an empty shell). Drafts are allowed to be
     * partial but still require the header snapshots.
     */
    private function validateStructure(array $p, bool $strict = false): void
    {
        if ($strict) {
            if (trim((string) $p['reference_number']) === '') {
                throw new InvalidArgumentException('Minutes reference number is required before publishing.', 422);
            }
            if (count($p['agenda_items']) === 0) {
                throw new InvalidArgumentException('At least one agenda item is required before publishing.', 422);
            }
            if (count($p['decisions']) === 0) {
                throw new InvalidArgumentException('At least one decision is required before publishing.', 422);
            }
        }
        foreach ($p['agenda_items'] as $a) {
            if ($a['title'] === '') {
                throw new InvalidArgumentException('Every agenda item needs a title.', 422);
            }
        }
        foreach ($p['decisions'] as $d) {
            if ($d['resolution'] === '') {
                throw new InvalidArgumentException('Every decision needs a resolution.', 422);
            }
        }
        foreach ($p['action_items'] as $a) {
            if ($a['action'] === '') {
                throw new InvalidArgumentException('Every action item needs a description.', 422);
            }
        }
        foreach ($p['aob_items'] as $a) {
            if ($a['item'] === '') {
                throw new InvalidArgumentException('Every AOB item needs a description.', 422);
            }
        }
    }

    private function replaceChildren(int $minutesId, array $p): void
    {
        // Replace strategy: delete all children, then re-insert the provided
        // set within the same transaction. Simple, consistent, avoids partial
        // diff complexity, and is safe because we are already inside a tx.
        $this->repo->deleteChildren($minutesId);

        foreach ($p['agenda_items'] as $a) {
            $this->repo->insertAgendaItem($minutesId, $a);
        }
        foreach ($p['decisions'] as $d) {
            $this->repo->insertDecision($minutesId, $d);
        }
        foreach ($p['action_items'] as $a) {
            $this->repo->insertActionItem($minutesId, $a);
        }
        foreach ($p['aob_items'] as $a) {
            $this->repo->insertAobItem($minutesId, $a);
        }
    }

    // ---- Permissions / access ----

    private function canManageMinutes(): bool
    {
        return $this->hasPermission('meetings.minutes.update')
            || $this->hasPermission('meetings.minutes.create')
            || $this->hasPermission('meetings.minutes.publish');
    }

    private function hasPermission(string $moduleAction): bool
    {
        // Accepts 'module.action' strings.
        $parts = explode('.', $moduleAction, 2);
        if (count($parts) !== 2) {
            return false;
        }
        return Auth::getInstance()->hasPermission($parts[0], $parts[1]);
    }

    private function isConfirmedInvitee(int $meetingId, int $userId): bool
    {
        $employeeId = $this->repo->getEmployeeIdByUserId($userId);
        if ($employeeId === null) {
            return false;
        }
        $inv = $this->repo->getInvitation($meetingId, $employeeId);
        return $inv !== null && ($inv['response_status'] ?? '') === 'accepted';
    }

    private function ensureMeeting(int $meetingId): array
    {
        $meeting = $this->meetingRepository->findById($meetingId);
        if (!$meeting) {
            throw new InvalidArgumentException('Meeting not found', 404);
        }
        return $meeting;
    }

    private function assertMeetingExists(int $meetingId): void
    {
        $this->ensureMeeting($meetingId);
    }

    private function auditPublished(int $meetingId, int $minutesId, string $reference, int $userId): void
    {
        AuditService::getInstance()->log(
            AuditService::MODULE_MEETINGS,
            AuditService::ACTION_MINUTES_PUBLISHED,
            'Meeting minutes published: ' . $reference,
            ['target_type' => 'meeting_minutes', 'target_id' => $minutesId, 'target_name' => $reference]
        );
    }

    // ---- null coercions ----

    private function nullInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }
        return (int) $value;
    }

    private function nullString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return trim((string) $value);
    }
}
<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Services\LeaveProfileService;

/**
 * LeaveTypePolicy — the single authoritative source for leave-type
 * business rules (Phase 5 end-to-end audit §21/§22).
 *
 * Every rule that keys off a leave type ID (backdating, overlap-block
 * exemption, document requirements, deduction behaviour) MUST live here.
 * The canonical leave-type ID constants come from LeaveProfileService so
 * the IDs are declared exactly once in the codebase.
 *
 * The frontend does NOT own these rules: GET /leave/types exposes the
 * flags below (LeaveTypePolicy::flags()) so the React form can mirror
 * them for UX only. The backend remains the authority.
 */
final class LeaveTypePolicy
{
    // Canonical leave type IDs (seed data — declared once in LeaveProfileService).
    public const TYPE_ANNUAL      = LeaveProfileService::LEAVE_TYPE_ANNUAL;      // 1
    public const TYPE_SICK        = LeaveProfileService::LEAVE_TYPE_SICK;        // 2
    public const TYPE_STUDY       = LeaveProfileService::LEAVE_TYPE_STUDY;       // 5
    public const TYPE_SHORT       = LeaveProfileService::LEAVE_TYPE_SHORT;       // 6
    public const TYPE_ABSENCE     = LeaveProfileService::LEAVE_TYPE_ABSENCE;     // 8
    public const TYPE_CLAIM_A_DAY = LeaveProfileService::LEAVE_TYPE_CLAIM_A_DAY; // 9

    /**
     * Leave types whose start date may lie before today (retrospective
     * applications):
     *   - Sick Leave: illness is inherently retrospective and must always
     *     be reportable after the fact.
     *   - Study Leave: training is often attended before the paperwork is
     *     filed.
     *   - Claim a Day: by definition credits annual leave for a day already
     *     worked — it can only ever refer to past/current dates.
     */
    private const BACKDATE_ALLOWED = [
        self::TYPE_SICK,
        self::TYPE_STUDY,
        self::TYPE_CLAIM_A_DAY,
    ];

    /**
     * Leave types exempt from the "cannot apply while on leave / pending
     * application" block. Sick leave is exempt because illness can occur
     * regardless of existing leave or pending applications — the employee
     * must always be able to report an illness.
     *
     * IMPORTANT: exemption applies ONLY to that block. The date-overlap
     * check still applies to these types so approved/pending periods can
     * never be double-booked.
     */
    private const OVERLAP_BLOCK_EXEMPT = [
        self::TYPE_SICK,
    ];

    /** Leave types requiring a supporting document at submission time. */
    private const DOCUMENT_REQUIREMENTS = [
        self::TYPE_SICK  => 'medical_certificate',
        self::TYPE_STUDY => 'study_document',
    ];

    /** Human-readable requirement messages, keyed like DOCUMENT_REQUIREMENTS. */
    private const DOCUMENT_MESSAGES = [
        self::TYPE_SICK  => 'A supporting medical document is required for Sick Leave.',
        self::TYPE_STUDY => 'A supporting document such as a timetable is required for Study Leave.',
    ];

    public static function allowsBackdate(int $leaveTypeId): bool
    {
        return in_array($leaveTypeId, self::BACKDATE_ALLOWED, true);
    }

    public static function exemptFromOverlapBlock(int $leaveTypeId): bool
    {
        return in_array($leaveTypeId, self::OVERLAP_BLOCK_EXEMPT, true);
    }

    public static function requiresDocument(int $leaveTypeId): bool
    {
        return isset(self::DOCUMENT_REQUIREMENTS[$leaveTypeId]);
    }

    public static function documentType(int $leaveTypeId): ?string
    {
        return self::DOCUMENT_REQUIREMENTS[$leaveTypeId] ?? null;
    }

    public static function documentMessage(int $leaveTypeId): string
    {
        return self::DOCUMENT_MESSAGES[$leaveTypeId] ?? 'Supporting document required.';
    }

    /**
     * Per-type policy flags exposed through the API (GET /leave/types) so
     * the frontend can reflect the rules without duplicating them.
     */
    public static function flags(int $leaveTypeId): array
    {
        return [
            'allows_backdate'           => self::allowsBackdate($leaveTypeId),
            'exempt_from_overlap_block' => self::exemptFromOverlapBlock($leaveTypeId),
            'requires_document'         => self::requiresDocument($leaveTypeId),
            'required_document_type'    => self::documentType($leaveTypeId),
        ];
    }
}
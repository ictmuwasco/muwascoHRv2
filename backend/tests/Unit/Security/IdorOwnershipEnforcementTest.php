<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Tests\TestCase;

/**
 * Phase 7 regression tests — IDOR / object-level authorization (P7-8).
 *
 * The mandate: knowing an ID must never grant access. Every authenticated
 * {id} endpoint must additionally enforce ownership/scope server-side.
 * This suite pins the enforcement at its actual location in the codebase:
 *
 *   - notifications:  markAsRead() scopes its UPDATE by AND user_id = ?
 *   - leave cancel:   LeaveApprovalService::cancel() requires the applicant
 *   - profile docs:   viewProfileDocumentAction() owner-or-HR gate
 *   - leave docs:     viewDocumentAction() delegates to getDocument($appId,$userId)
 *
 * These are source assertions (same convention as PrivilegeEscalationTest) —
 * if a future refactor drops the scoping clause, the build fails.
 */
class IdorOwnershipEnforcementTest extends TestCase
{
    public function test_notification_read_is_scoped_to_the_owning_user(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/NotificationService.php');

        // The UPDATE must carry the notification owner in the WHERE clause.
        $this->assertStringContainsString(
            'id = ? AND user_id = ?',
            $service,
            'markAsRead() must scope updates to the notification owner'
        );
    }

    public function test_leave_cancel_requires_the_applicant(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/LeaveApprovalService.php');

        $this->assertStringContainsString(
            'Only the applicant can cancel',
            $service,
            'Leave cancellation must be restricted to the applicant'
        );
    }

    public function test_leave_cancel_owned_employee_id_comparison(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../../app/Services/LeaveApprovalService.php');

        // The applicant's employee id must be derived from the authenticated
        // user (never from a client-supplied field) and compared against the
        // application's employee_id.
        $this->assertStringContainsString(
            'getEmployeeIdFromUserId($userId)',
            $service
        );
        $this->assertStringContainsString(
            '$app[\'employee_id\']',
            $service
        );
    }

    public function test_profile_document_view_is_owner_or_hr_only(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../../app/Controllers/Employee/EmployeeController.php');

        // isOwner OR hasHrPerm gate.
        $this->assertStringContainsString('$isOwner', $controller);
        $this->assertStringContainsString('$hasHrPerm', $controller);
        $this->assertStringContainsString('!$isOwner && !$hasHrPerm', $controller);
    }

    public function test_leave_document_view_is_scoped_to_an_application(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../../../app/Controllers/Leave/LeaveController.php');

        // getDocument() must receive BOTH the application id and the
        // authenticated user id — a bare "{documentId}" lookup would be IDOR.
        $this->assertStringContainsString(
            'getDocument($documentId, $userId)',
            $controller
        );
    }

    public function test_self_service_routes_carry_an_ownership_flag_in_the_router(): void
    {
        // Clock-in/out, notification reads and profile document views are
        // allowlisted WITHOUT a permission — the router comment mandates the
        // controller/service ownership enforcement (defense in depth).
        $allowlist = (string) file_get_contents(__DIR__ . '/../../../config/authz_allowlist.php');

        $this->assertStringContainsString(
            'ownership in controller',
            $allowlist,
            'Self-service allowlist must keep its ownership-enforcement note'
        );
    }
}
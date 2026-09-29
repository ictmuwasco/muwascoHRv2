<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * DelegationService — Temporary Delegation / Acting Authority System
 *
 * Implements an explicit, time-bound, scope-aware transfer of a supervisor's
 * approval authority to a delegate WITHOUT changing the delegate's permanent
 * role:
 *
 *     Permanent Role + Explicit Temporary Delegation + Delegated Permissions
 *     + Delegated Organizational Scope + Valid Time Period
 *     = Temporary Effective Authority
 *
 * Architectural invariants (delegation spec §3/§13/§23/§32):
 *  - The delegate's users.role is NEVER modified. Effective permissions are
 *    resolved by AuthorizationService as a NEW priority step between role
 *    permissions and the default deny; an explicit user 'deny' override and
 *    the super_admin policy always outrank delegation.
 *  - Authority is EXPLICIT: only the permission strings snapshotted on the
 *    delegation row are granted. Non-delegatable modules (settings, permission
 *    administration, ...) are rejected at creation AND re-checked at
 *    resolution time (defence in depth).
 *  - Authority is TIME-BOUND: active only while status is approved/active AND
 *    CURDATE() BETWEEN start_date AND end_date. Expiry is automatic (§36).
 *  - Authority is SCOPE-AWARE: the delegation snapshots the DELEGATOR'S
 *    organizational unit; leave-approval integration enforces that scope.
 *
 * Place: backend/app/Services/DelegationService.php
 */
class DelegationService
{
    /**
     * Modules whose permissions may never be delegated (§22/§23/§32). Enforced
     * at creation time (validation) and at resolution time (defence in depth
     * against hand-edited rows).
     */
    public const NON_DELEGATABLE_MODULES = [
        'settings', 'permission_overrides', 'notifications', 'audit',
        'users', 'admin', 'system_errors', 'delegations',
    ];

    /**
     * Phase 1 delegatable set: ONLY the leave module. Other modules become
     * delegatable by adding them here once their per-module scope checks are
     * taught to honour the delegated scope (see remaining-issues notes).
     */
    public const DELEGATABLE_MODULES = ['leave'];

    /**
     * The leave ACTIONS a duty-cover delegate may receive, on top of every
     * module's page permission. This is the authority the cover exists for:
     * without approve/manage the delegate could see the queue but never
     * decide anything in it.
     *
     * Still intersected with the delegator's own role grants at grant time,
     * so this is a CEILING, never a grant by itself.
     */
    public const DELEGATABLE_ACTIONS = ['view', 'apply', 'approve', 'reject', 'manage', 'roster'];

    /**
     * Modules whose PAGE-level ('view') permission may be auto-granted to the
     * duty-cover delegate when a leave application reaches 'approved'.
     *
     * Scope of this list is deliberately DAY-TO-DAY pages only — the modules a
     * supervisor opens while covering an absent colleague. The grant is always
     * intersected with what the DELEGATOR'S OWN ROLE actually holds, so a
     * delegation can never manufacture authority the absent person did not
     * have (the whole point of the module is to substitute for them, not to
     * promote the delegate).
     *
     * Every entry is additionally filtered through NON_DELEGATABLE_MODULES at
     * both grant time and resolution time, so adding an administrative module
     * here can never leak settings / users / audit authority.
     */
    public const AUTO_DELEGATABLE_PAGE_MODULES = [
        'dashboard', 'employees', 'departments', 'attendance',
        'leave', 'meetings', 'reports', 'profile',
    ];

    /**
     * `delegations.source` — where the row came from. 'manual' rows are the
     * pre-existing explicit "New Delegation" flow; 'leave_application' rows
     * are minted automatically by createFromApprovedLeave().
     */
    public const SOURCE_MANUAL           = 'manual';
    public const SOURCE_LEAVE_APPLICATION = 'leave_application';

    /**
     * Roles whose holders may CREATE a delegation (§5). Officers/employees
     * hold no supervisory authority they could transfer.
     */
    public const DELEGATOR_ROLES = [
        'sub_section_head', 'section_head', 'dept_head', 'manager',
        'hr_manager', 'managing_director', 'super_admin',
    ];

    /**
     * Org-wide authority roles. Delegations created by these roles snapshot
     * scope_type='organization', and ONLY a super_admin may approve them
     * (an HR manager can never approve their own org-wide delegation).
     */
    public const ORG_WIDE_ROLES = ['hr_manager', 'managing_director', 'super_admin'];

    /**
     * Leave workflow stages → the role whose authority is required to decide
     * at that stage. Mirrors LeaveApprovalService::isAuthorisedApprover().
     */
    public const STAGE_ROLES = [
        'pending_subsection_head'   => 'sub_section_head',
        'pending_section_head'      => 'section_head',
        'pending_dept_head'         => 'dept_head',
        'pending_managing_director' => 'managing_director',
        'pending_hr'                => 'hr_manager',
        'pending_hr_manager'        => 'hr_manager',
        'pending_bod_chair'         => 'bod_chairman',
        'pending_manager'           => 'manager',
    ];

    private \mysqli $db;
    private static ?DelegationService $instance = null;

    /** Per-request cache of active delegations keyed by delegate user id. */
    private array $activeCache = [];

    /** Per-request flag so the lazy lifecycle sweep runs at most once. */
    private bool $swept = false;


    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Lifecycle: create
    // ────────────────────────────────────────────────────────────────────

    /**
     * Create a delegation request (status = 'pending'). The authenticated
     * delegator explicitly chooses delegate, window, authority and reason.
     *
     * @param int   $actorUserId Authenticated user (the delegator)
     * @param array $input       delegate_user_id, start_date, end_date,
     *                           permissions[], reason
     * @return array{success: bool, message: string, data?: array}
     */
    public function create(int $actorUserId, array $input): array
    {
        $actor = $this->userById($actorUserId);
        if (!$actor) {
            return ['success' => false, 'message' => 'Authenticated user not found'];
        }

        $role = (string) ($actor['role'] ?? '');

        // §5/§6 — only supervisory roles may delegate; an officer can never
        // create a delegation (scenario 6).
        if (!in_array($role, self::DELEGATOR_ROLES, true)) {
            return ['success' => false, 'message' => 'Your role is not authorized to create delegations'];
        }

        $delegateUserId = (int) ($input['delegate_user_id'] ?? 0);
        $startDate      = trim((string) ($input['start_date'] ?? ''));
        $endDate        = trim((string) ($input['end_date'] ?? ''));
        $reason         = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 500);
        $permissions    = $input['permissions'] ?? [];

        if ($delegateUserId <= 0 || $startDate === '' || $endDate === '') {
            return ['success' => false, 'message' => 'Delegate, start date and end date are required'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return ['success' => false, 'message' => 'Invalid date format (expected YYYY-MM-DD)'];
        }
        if ($startDate > $endDate) {
            return ['success' => false, 'message' => 'Start date must not be after the end date'];
        }
        if ($endDate < date('Y-m-d')) {
            return ['success' => false, 'message' => 'End date must not be in the past'];
        }

        // The delegate must exist, be an active account, and not be the
        // delegator themselves (§6).
        $delegate = $this->userById($delegateUserId);
        if (!$delegate || (int) ($delegate['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'Delegate must be an active user'];
        }
        if ($delegateUserId === $actorUserId) {
            return ['success' => false, 'message' => 'You cannot delegate authority to yourself'];
        }

        $scope = $this->resolveDelegatorScope($actorUserId, $role);
        if ($scope === null) {
            return ['success' => false, 'message' => 'Your organizational unit could not be resolved; ask HR to verify your employee record'];
        }
        if (!in_array($role, self::ORG_WIDE_ROLES, true) && !$this->delegateInScope($actorUserId, $role, $delegateUserId)) {
            return ['success' => false, 'message' => 'The selected delegate is not within your organizational scope'];
        }

        // §22 — the delegated authority must be an explicit subset of what
        // this role may delegate, and may never include non-delegatable
        // modules (settings, permission administration, ...).
        $delegatable = $this->delegatablePermissions($role);
        $requested   = array_values(array_unique(array_map('strval', (array) $permissions)));
        if ($requested === []) {
            return ['success' => false, 'message' => 'Select at least one delegated authority'];
        }
        foreach ($requested as $perm) {
            [$mod] = array_pad(explode(':', $perm, 2), 2, '');
            if (in_array($mod, self::NON_DELEGATABLE_MODULES, true)) {
                return ['success' => false, 'message' => "The module '{$mod}' cannot be delegated"];
            }
            if (!in_array($perm, $delegatable['flat'], true)) {
                return ['success' => false, 'message' => "The authority '{$perm}' is outside what your role can delegate"];
            }
        }

        // §34 — one non-overlapping window per (delegator, delegate) pair.
        // Different delegates for the same delegator MAY overlap (scopes stay
        // separate, spec §33).
        if ($this->hasOverlappingDelegation($actorUserId, $delegateUserId, $startDate, $endDate)) {
            return ['success' => false, 'message' => 'This delegate already has a delegation from you that overlaps the selected period'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO delegations
                (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
                 permissions, start_date, end_date, reason, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $permJson  = json_encode($requested);
        $scopeType = $scope['type'];
        $scopeId   = (int) $scope['id'];
        $createdBy = $actorUserId;
        $stmt->bind_param(
            'iississssi',
            $actorUserId,
            $delegateUserId,
            $role,
            $scopeType,
            $scopeId,
            $permJson,
            $startDate,
            $endDate,
            $reason,
            $createdBy
        );
        $stmt->execute();
        $delegationId = (int) $this->db->insert_id;
        $stmt->close();

        $delegateName = trim(($delegate['first_name'] ?? '') . ' ' . ($delegate['last_name'] ?? ''));
        $actorName    = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? ''));

        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_CREATE,
            "Delegation #{$delegationId} created: {$actorName} ({$role}) → {$delegateName}",
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'delegator_user_id' => $actorUserId,
                    'delegate_user_id'  => $delegateUserId,
                    'delegated_role'    => $role,
                    'scope'             => $scope['label'],
                    'permissions'       => $requested,
                    'start_date'        => $startDate,
                    'end_date'          => $endDate,
                    'reason'            => $reason,
                ],
            ]
        );

        $this->notifyUser(
            $delegateUserId,
            'Delegation Request',
            "{$actorName} has requested you as their delegate from {$startDate} to {$endDate}. Pending HR approval.",
            'delegation_pending',
            '/delegations'
        );
        $this->notifyRoles(
            $this->approvalRolesFor($role),
            'Delegation Awaiting Approval',
            "Delegation #{$delegationId}: {$actorName} → {$delegateName} ({$startDate} to {$endDate})",
            'delegation_pending',
            '/delegations'
        );

        return [
            'success' => true,
            'message' => 'Delegation created and submitted for approval',
            'data'    => ['id' => $delegationId, 'status' => 'pending'],
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    //  Lifecycle: approve / reject / cancel
    // ────────────────────────────────────────────────────────────────────

    /**
     * HR approval step (§11): pending → approved. Activation is date-driven,
     * so an approved delegation becomes effective automatically on its start
     * date and expires automatically on its end date.
     *
     * Authorization: the route gate enforces `delegations:approve`; here we
     * additionally require the approver to be neither the delegator nor the
     * delegate, and — when the delegator holds org-wide authority (HR /
     * MD / super admin) — to be a super_admin (no self-approval anywhere).
     */
    public function approve(int $approverUserId, int $delegationId): array
    {
        $delegation = $this->find($delegationId);
        if (!$delegation) {
            return ['success' => false, 'message' => 'Delegation not found'];
        }
        if ($delegation['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending delegations can be approved'];
        }

        $guard = $this->assertCanDecide($approverUserId, $delegation);
        if ($guard !== null) {
            return $guard;
        }

        $stmt = $this->db->prepare("
            UPDATE delegations
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->bind_param('ii', $approverUserId, $delegationId);
        $stmt->execute();
        $stmt->close();

        $this->clearActiveCache();

        $approverName  = $this->userName($approverUserId);
        $delegateName  = $this->userName((int) $delegation['delegate_user_id']);
        $delegatorName = $this->userName((int) $delegation['delegator_user_id']);

        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_APPROVE,
            "Delegation #{$delegationId} approved by {$approverName}: {$delegatorName} → {$delegateName}",
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'delegator_user_id' => (int) $delegation['delegator_user_id'],
                    'delegate_user_id'  => (int) $delegation['delegate_user_id'],
                    'start_date'        => $delegation['start_date'],
                    'end_date'          => $delegation['end_date'],
                ],
            ]
        );

        $this->notifyUser(
            (int) $delegation['delegate_user_id'],
            'Delegation Approved',
            "You are now set as acting delegate for {$delegatorName} from {$delegation['start_date']} to {$delegation['end_date']}.",
            'delegation_approved',
            '/delegations'
        );
        $this->notifyUser(
            (int) $delegation['delegator_user_id'],
            'Delegation Approved',
            "Your delegation to {$delegateName} ({$delegation['start_date']} to {$delegation['end_date']}) was approved by {$approverName}.",
            'delegation_approved',
            '/delegations'
        );

        return ['success' => true, 'message' => 'Delegation approved', 'data' => ['status' => 'approved']];
    }

    /**
     * HR rejection step: pending → rejected (terminal).
     */
    public function reject(int $approverUserId, int $delegationId, string $reason = ''): array
    {
        $delegation = $this->find($delegationId);
        if (!$delegation) {
            return ['success' => false, 'message' => 'Delegation not found'];
        }
        if ($delegation['status'] !== 'pending') {
            return ['success' => false, 'message' => 'Only pending delegations can be rejected'];
        }

        $guard = $this->assertCanDecide($approverUserId, $delegation);
        if ($guard !== null) {
            return $guard;
        }

        $stmt = $this->db->prepare("UPDATE delegations SET status = 'rejected' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $delegationId);
        $stmt->execute();
        $stmt->close();

        $this->clearActiveCache();

        $reason = mb_substr(trim($reason), 0, 500);
        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_REJECT,
            "Delegation #{$delegationId} rejected" . ($reason !== '' ? ": {$reason}" : ''),
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'delegator_user_id' => (int) $delegation['delegator_user_id'],
                    'delegate_user_id'  => (int) $delegation['delegate_user_id'],
                    'reason'            => $reason,
                ],
            ]
        );

        $this->notifyUser(
            (int) $delegation['delegate_user_id'],
            'Delegation Rejected',
            'A delegation request from ' . $this->userName((int) $delegation['delegator_user_id']) . ' was rejected by HR.',
            'delegation_rejected',
            '/delegations'
        );
        $this->notifyUser(
            (int) $delegation['delegator_user_id'],
            'Delegation Rejected',
            'Your delegation request (' . $this->userName((int) $delegation['delegate_user_id']) . ') was rejected by HR.',
            'delegation_rejected',
            '/delegations'
        );

        return ['success' => true, 'message' => 'Delegation rejected', 'data' => ['status' => 'rejected']];
    }

    /**
     * Cancel a pending/approved/active delegation (§35). Allowed for the
     * DELEGATOR themselves (ownership bypass) or an HR/super_admin user —
     * never for the delegate. Takes effect immediately: the next permission
     * resolution no longer sees the delegation.
     */
    public function cancel(int $actorUserId, int $delegationId, string $reason = ''): array
    {
        $delegation = $this->find($delegationId);
        if (!$delegation) {
            return ['success' => false, 'message' => 'Delegation not found'];
        }
        if (!in_array($delegation['status'], ['pending', 'approved', 'active'], true)) {
            return ['success' => false, 'message' => 'Only pending, approved or active delegations can be cancelled'];
        }

        $isDelegator = (int) $delegation['delegator_user_id'] === $actorUserId;
        $actorRole   = (string) ($this->userById($actorUserId)['role'] ?? '');
        $isHr        = in_array($actorRole, ['hr_manager', 'super_admin'], true);
        if (!$isDelegator && !$isHr) {
            return ['success' => false, 'message' => 'Only the delegator or HR can cancel this delegation'];
        }

        $stmt = $this->db->prepare("UPDATE delegations SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $delegationId);
        $stmt->execute();
        $stmt->close();

        $this->clearActiveCache();

        $reason = mb_substr(trim($reason), 0, 500);
        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_DELEGATION_CANCELLED,
            "Delegation #{$delegationId} cancelled by " . $this->userName($actorUserId) . ($reason !== '' ? ": {$reason}" : ''),
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'cancelled_by'      => $actorUserId,
                    'delegator_user_id' => (int) $delegation['delegator_user_id'],
                    'delegate_user_id'  => (int) $delegation['delegate_user_id'],
                    'previous_status'   => $delegation['status'],
                    'reason'            => $reason,
                ],
            ]
        );

        $this->notifyUser(
            (int) $delegation['delegate_user_id'],
            'Delegation Cancelled',
            'Your acting delegation from ' . $this->userName((int) $delegation['delegator_user_id']) . ' was cancelled.',
            'delegation_cancelled',
            '/delegations'
        );
        if (!$isDelegator) {
            $this->notifyUser(
                (int) $delegation['delegator_user_id'],
                'Delegation Cancelled',
                'Your delegation to ' . $this->userName((int) $delegation['delegate_user_id']) . ' was cancelled by HR.',
                'delegation_cancelled',
                '/delegations'
            );
        }

        return ['success' => true, 'message' => 'Delegation cancelled', 'data' => ['status' => 'cancelled']];
    }

    // ────────────────────────────────────────────────────────────────────
    //  Automatic duty-cover delegation from an APPROVED leave application
    // ────────────────────────────────────────────────────────────────────

    /**
     * Create (or return) the duty-cover delegation for an APPROVED leave
     * application, wiring the delegate picked on the Apply Leave form
     * (`leave_applications.delegate_emp_id`, migration 012) into the Acting
     * Authority module so the delegate actually RECEIVES permissions.
     *
     * Why this exists: before it the two delegate mechanisms were disjoint.
     * The Apply-Leave delegate was written to a column on the leave row and
     * could only ever BACK-UP approve the applicant's own application — it
     * granted no authority at all — while the `delegations` table (the only
     * thing AuthorizationService Priority 6 consults) could only be populated
     * by hand. Approving a leave therefore granted the appointee nothing.
     *
     * Failures are swallowed and logged: an approval that has already been
     * committed must never be undone by a delegation problem.
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function createFromApprovedLeave(int $applicationId, int $approverUserId): array
    {
        try {
            return $this->doCreateFromApprovedLeave($applicationId, $approverUserId);
        } catch (\Throwable $e) {
            error_log('[DelegationService] auto delegation failed for leave #' . $applicationId . ': ' . $e->getMessage());
            return ['success' => false, 'message' => 'Automatic delegation could not be created.'];
        }
    }

    /**
     * Real work behind createFromApprovedLeave().
     *
     * Design decisions:
     *
     *  - STATUS is 'approved'/'active' immediately, never 'pending'. The
     *    leave workflow IS the authorization gate: the application's own
     *    multi-stage chain (subsection → section → dept → MD → BOD → HR)
     *    already vetted who may be absent and for how long. Routing an auto
     *    row through HR a second time would make the feature unusable, and
     *    the leave approver is recorded in `approved_by` so the grant stays
     *    attributable.
     *
     *  - WINDOW is the leave window verbatim (start_date..end_date), so the
     *    existing lazy sweep expires the authority on the last day of leave
     *    with no cron, exactly like a manual delegation.
     *
     *  - PERMISSIONS are the delegator's OWN grants intersected with
     *    AUTO_DELEGATABLE_PAGE_MODULES. The delegate can never receive more
     *    than the absent person held; this substitutes, it never promotes.
     *
     *  - SCOPE is the APPLICANT'S ORG UNIT, resolved independently of role —
     *    resolveDelegatorScope() is supervisory-only and returns null for an
     *    officer/employee, so an ordinary employee on leave must resolve scope
     *    a different way or no delegation would be recorded at all.
     *
     *  - IDEMPOTENT: one delegation per leave application (UNIQUE
     *    leave_application_id, migration 096). Re-entry returns the existing
     *    row instead of stacking duplicate grants.
     */
    private function doCreateFromApprovedLeave(int $applicationId, int $approverUserId): array
    {
        $app = $this->approvedLeaveWithDelegate($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'No approved leave application with a delegate.'];
        }

        // Idempotency — a leave application mints at most one delegation.
        $existing = $this->findByLeaveApplication($applicationId);
        if ($existing !== null) {
            return [
                'success' => true,
                'message' => 'Delegation already exists for this leave application',
                'data'    => ['id' => (int) $existing['id'], 'status' => $existing['status'], 'created' => false],
            ];
        }

        $applicantUserId = (int) ($app['applicant_user_id'] ?? 0);
        $delegateUserId  = (int) ($app['delegate_user_id'] ?? 0);

        // A delegate who IS the applicant must never hold authority over their
        // own absence — the self-approval guard, which also absorbs a
        // mis-picked delegate equal to the person going on leave.
        if ($applicantUserId <= 0 || $delegateUserId <= 0) {
            return ['success' => false, 'message' => 'Leave application is missing an applicant or delegate account.'];
        }
        if ($applicantUserId === $delegateUserId) {
            return ['success' => false, 'message' => 'The appointed delegate is the applicant; no delegation created.'];
        }

        $delegate = $this->userById($delegateUserId);
        if (!$delegate || (int) ($delegate['is_active'] ?? 0) !== 1) {
            // The delegate exists as an employee but has no usable login, so
            // there is no principal to attach the authority to.
            return ['success' => false, 'message' => 'The appointed delegate has no active user account.'];
        }

        $delegatorRole = (string) ($app['applicant_role'] ?? '');
        $scope = $this->resolveApplicantScope($app);
        if ($scope === null) {
            return ['success' => false, 'message' => 'The applicant organizational unit could not be resolved.'];
        }

        $permissions = $this->autoDelegatedPermissions($delegatorRole);
        if ($permissions === []) {
            return ['success' => false, 'message' => 'The applicant holds no delegatable page permissions.'];
        }

        $startDate = (string) $app['start_date'];
        $endDate   = (string) $app['end_date'];
        $reason    = mb_substr(sprintf(
            'Automatic duty cover for approved leave application #%d (%s).',
            $applicationId,
            (string) ($app['leave_type_name'] ?? 'leave')
        ), 0, 500);

        // A leave that has already begun yields 'active' straight away; a
        // future window yields 'approved' and is flipped to 'active' by the
        // existing lazy sweep on its first day. Both are already "effective"
        // for permission resolution, which filters on the date window.
        $initialStatus = $startDate <= date('Y-m-d') ? 'active' : 'approved';
        $scopeId       = (int) $scope['id'];
        $source        = self::SOURCE_LEAVE_APPLICATION;

        $stmt = $this->db->prepare("
            INSERT INTO delegations
                (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
                 permissions, start_date, end_date, reason, status, approved_by, approved_at,
                 source, leave_application_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
        ");
        // bind_param() binds BY REFERENCE, so every argument must be a plain
        // variable — an array index expression or a function call raises
        // "Only variables should be passed by reference". Assign first, then
        // bind (same pattern as LeaveApplicationService::insertApplication).
        $scopeType  = (string) $scope['type'];
        $permJson   = json_encode($permissions);

        // Type string mirrors the 14 bound placeholders exactly, in order:
        // delegator(i) delegate(i) role(s) scope_type(s) scope_id(i)
        // permissions(s) start(s) end(s) reason(s) status(s) approved_by(i)
        // source(s) leave_application_id(i) created_by(i).
        // approved_at is written as NOW() and is deliberately NOT bound.
        $stmt->bind_param(
            'iississsssisii',
            $applicantUserId,
            $delegateUserId,
            $delegatorRole,
            $scopeType,
            $scopeId,
            $permJson,
            $startDate,
            $endDate,
            $reason,
            $initialStatus,
            $approverUserId,
            $source,
            $applicationId,
            $approverUserId
        );
        $stmt->execute();
        $delegationId = (int) $this->db->insert_id;
        $stmt->close();

        $this->clearActiveCache();

        $delegatorName = $this->userName($applicantUserId);
        $delegateName  = $this->userName($delegateUserId);

        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_CREATE,
            "Delegation #{$delegationId} auto-created from approved leave #{$applicationId}: {$delegatorName} → {$delegateName} ({$startDate} to {$endDate})",
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'source'              => self::SOURCE_LEAVE_APPLICATION,
                    'leave_application_id' => $applicationId,
                    'delegator_user_id'    => $applicantUserId,
                    'delegate_user_id'     => $delegateUserId,
                    'delegated_role'       => $delegatorRole,
                    'scope'                => $scope['label'],
                    'permissions'          => $permissions,
                    'approved_by'          => $approverUserId,
                    'start_date'           => $startDate,
                    'end_date'             => $endDate,
                ],
            ]
        );

        $this->notifyUser(
            $delegateUserId,
            'You are now covering for ' . $delegatorName,
            sprintf(
                'Their %s leave was approved. You have temporary access to their pages (%s) from %s to %s. Your own permissions are unchanged and resume automatically afterwards.',
                (string) ($app['leave_type_name'] ?? 'leave'),
                implode(', ', $permissions),
                $startDate,
                $endDate
            ),
            'delegation_leave_cover',
            '/delegations'
        );

        return [
            'success' => true,
            'message' => 'Automatic duty-cover delegation created',
            'data'    => [
                'id'            => $delegationId,
                'status'        => $initialStatus,
                'created'       => true,
                'permissions'   => $permissions,
                'delegate_name' => $delegateName,
            ],
        ];
    }

    /**
     * Withdraw the automatic delegation minted for a leave application.
     *
     * Called when the leave itself is cancelled or invalidated AFTER it was
     * approved: the person is no longer away, so the cover authority must stop
     * immediately. Scoped strictly to the auto row linked to THIS application,
     * so withdrawing one leave never disturbs an unrelated manual delegation.
     *
     * Safe to call when no row exists (e.g. cancelling a leave that never
     * reached approval) — it simply reports false.
     */
    public function cancelForLeaveApplication(int $applicationId, string $reason = ''): bool
    {
        try {
            return $this->doCancelForLeaveApplication($applicationId, $reason);
        } catch (\Throwable $e) {
            error_log('[DelegationService] auto delegation cancel failed for leave #' . $applicationId . ': ' . $e->getMessage());
            return false;
        }
    }

    /** Real work behind cancelForLeaveApplication(). */
    private function doCancelForLeaveApplication(int $applicationId, string $reason): bool
    {
        $existing = $this->findByLeaveApplication($applicationId);
        if ($existing === null) {
            return false;
        }
        // Already terminal (expired / cancelled / rejected) — nothing to do.
        if (!in_array($existing['status'], ['pending', 'approved', 'active'], true)) {
            return false;
        }

        $delegationId = (int) $existing['id'];
        $stmt = $this->db->prepare("UPDATE delegations SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param('i', $delegationId);
        $stmt->execute();
        $stmt->close();

        $this->clearActiveCache();

        $reason = mb_substr(trim($reason), 0, 500);
        AuditService::getInstance()->log(
            AuditService::MODULE_DELEGATIONS,
            AuditService::ACTION_DELEGATION_CANCELLED,
            "Delegation #{$delegationId} cancelled — linked leave application #{$applicationId} was withdrawn",
            [
                'target_type' => 'Delegation',
                'target_id'   => $delegationId,
                'metadata'    => [
                    'leave_application_id' => $applicationId,
                    'delegate_user_id'     => (int) $existing['delegate_user_id'],
                    'delegator_user_id'    => (int) $existing['delegator_user_id'],
                    'previous_status'      => $existing['status'],
                    'reason'               => $reason,
                ],
            ]
        );

        $this->notifyUser(
            (int) $existing['delegate_user_id'],
            'Duty cover ended',
            'The leave you were covering for has been withdrawn, so your temporary authority has ended. Your normal permissions apply again.',
            'delegation_cancelled',
            '/delegations'
        );

        return true;
    }

    /**
     * The approved leave application plus everything needed to build a
     * delegation: the applicant's user + role, and the appointed delegate's
     * user account (resolved from leave_applications.delegate_emp_id through
     * employees.employee_id — the same linkage DelegateService uses).
     *
     * Returns null when the application is missing, is not yet 'approved', or
     * carries no delegate — each of which means "no delegation to create".
     *
     * @return array<string, mixed>|null
     */
    private function approvedLeaveWithDelegate(int $applicationId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT la.id, la.start_date, la.end_date, la.status,
                   la.employee_id, la.delegate_emp_id, la.applied_by_user_id,
                   e.subsection_id, e.section_id, e.department_id,
                   u.id AS applicant_user_id, u.role AS applicant_role,
                   lt.name AS leave_type_name
            FROM leave_applications la
            JOIN employees e ON e.id = la.employee_id
            LEFT JOIN users u ON u.employee_id = e.employee_id
            LEFT JOIN leave_types lt ON lt.id = la.leave_type_id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || $row['status'] !== 'approved' || empty($row['delegate_emp_id'])) {
            return null;
        }

        // The applicant is the EMPLOYEE who is away, not whoever typed the
        // form. Fall back to applied_by_user_id only when the employee has no
        // login of their own (HR filing on someone's behalf).
        if (empty($row['applicant_user_id'])) {
            $row['applicant_user_id'] = (int) ($row['applied_by_user_id'] ?? 0);
        }
        $row['applicant_role'] = (string) ($row['applicant_role'] ?? '');

        $row['delegate_user_id'] = $this->userIdForEmployee((int) $row['delegate_emp_id']);
        return $row;
    }

    /** users.id for an employees.id (active accounts only), or null. */
    private function userIdForEmployee(int $employeeId): ?int
    {
        if ($employeeId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT u.id FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.id = ? AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    /** The delegation linked to a leave application, or null. */
    private function findByLeaveApplication(int $applicationId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT d.*,
                   du.first_name AS delegator_first_name, du.last_name AS delegator_last_name,
                   tu.first_name AS delegate_first_name,  tu.last_name AS delegate_last_name
            FROM delegations d
            JOIN users du ON du.id = d.delegator_user_id
            JOIN users tu ON tu.id = d.delegate_user_id
            WHERE d.leave_application_id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * The exact permission snapshot granted to an auto duty-cover delegate.
     *
     * For every module in AUTO_DELEGATABLE_PAGE_MODULES the APPLICANT'S OWN
     * role grants are intersected with the actions that module may hand over:
     * the 'leave' module contributes its approval actions (that is what lets
     * the delegate actually decide applications in scope), every other module
     * contributes its page permission only.
     *
     * Intersecting with the delegator's real grants is the security property
     * that matters — a delegation SUBSTITUTES for the absent person, so the
     * delegate can never end up holding authority the absent person lacked.
     *
     * @return array<int, string> sorted "module:action" strings
     */
    private function autoDelegatedPermissions(string $delegatorRole): array
    {
        if ($delegatorRole === '') {
            return [];
        }

        $granted = [];
        foreach (self::AUTO_DELEGATABLE_PAGE_MODULES as $module) {
            // Defence in depth: the page allowlist and the hard blacklist are
            // independent gates, so a future edit to either one still cannot
            // hand out settings / users / audit authority.
            if (in_array($module, self::NON_DELEGATABLE_MODULES, true)) {
                continue;
            }

            $actions = ($module === 'leave') ? self::DELEGATABLE_ACTIONS : ['view'];
            foreach ($this->roleGrants($delegatorRole, $module, $actions) as $permission) {
                $granted[$permission] = true;
            }
        }

        $list = array_keys($granted);
        sort($list);
        return $list;
    }

    /**
     * Which of the given actions the role is actually granted for a module.
     * The requested actions are first intersected with the central permission
     * catalog, so an unknown action can never reach the query.
     *
     * @param array<int, string> $actions
     * @return array<int, string>
     */
    private function roleGrants(string $role, string $module, array $actions): array
    {
        $moduleActions = array_values(array_intersect(self::catalogActionsFor($module), $actions));
        if ($moduleActions === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($moduleActions), '?'));
        $stmt = $this->db->prepare("
            SELECT action FROM role_permissions
            WHERE role = ? AND module = ? AND is_granted = 1
              AND action IN ({$placeholders})
        ");
        $types  = 'ss' . str_repeat('s', count($moduleActions));
        $params = array_merge([$role, $module], $moduleActions);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $granted = [];
        while ($row = $result->fetch_assoc()) {
            $granted[] = $module . ':' . $row['action'];
        }
        $stmt->close();
        return $granted;
    }

    /**
     * Action keys declared for a module in the central permission catalog
     * (backend/config/permissions.php — the single source of truth). An
     * unknown module yields [], which makes roleGrants() a no-op rather than a
     * silently-wrong query.
     *
     * @return array<int, string>
     */
    private static function catalogActionsFor(string $module): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            // Catalog is a plain PHP array literal — no DB, no side effects.
            $catalog = require __DIR__ . '/../../config/permissions.php';
            foreach (($catalog['modules'] ?? []) as $definition) {
                $key = (string) ($definition['key'] ?? '');
                if ($key === '') {
                    continue;
                }
                foreach (($definition['actions'] ?? []) as $action) {
                    $actionKey = (string) ($action['key'] ?? '');
                    if ($actionKey !== '') {
                        $cache[$key][] = $actionKey;
                    }
                }
            }
        }
        return $cache[$module] ?? [];
    }

    /**
     * Scope for the auto duty-cover delegation: the APPLICANT'S ORG UNIT,
     * resolved from their employee record rather than from their role.
     *
     * Deliberately does NOT reuse resolveDelegatorScope(), which is
     * supervisory-only and returns null for officer/employee — an ordinary
     * employee taking leave must still produce a properly scoped delegation
     * covering their own unit.
     *
     * @return array{type: string, id: int, label: string}|null
     */
    private function resolveApplicantScope(array $app): ?array
    {
        $subsectionId = (int) ($app['subsection_id'] ?? 0);
        $sectionId    = (int) ($app['section_id'] ?? 0);
        $departmentId = (int) ($app['department_id'] ?? 0);

        if ($subsectionId > 0) {
            return ['type' => 'subsection', 'id' => $subsectionId, 'label' => $this->scopeLabel('subsection', $subsectionId)];
        }
        if ($sectionId > 0) {
            return ['type' => 'section', 'id' => $sectionId, 'label' => $this->scopeLabel('section', $sectionId)];
        }
        if ($departmentId > 0) {
            return ['type' => 'department', 'id' => $departmentId, 'label' => $this->scopeLabel('department', $departmentId)];
        }
        // No org unit on the employee record at all — record the delegation
        // as organization-wide rather than refusing to capture the hand-over.
        return ['type' => 'organization', 'id' => 0, 'label' => 'Organization-wide'];
    }

    // ────────────────────────────────────────────────────────────────────
    //  Lookups: find / list / UI dropdown sources
    // ────────────────────────────────────────────────────────────────────

    /**
     * Fetch one delegation with resolved names (raw row; permissions decoded).
     */
    public function find(int $delegationId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   du.first_name AS delegator_first_name, du.last_name AS delegator_last_name,
                   tu.first_name AS delegate_first_name, tu.last_name AS delegate_last_name
            FROM delegations d
            JOIN users du ON du.id = d.delegator_user_id
            JOIN users tu ON tu.id = d.delegate_user_id
            WHERE d.id = ?
        ");
        $stmt->bind_param('i', $delegationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * List delegations for the management page. HR/super_admin (or any
     * org-wide role) sees EVERYTHING; everyone else sees only the
     * delegations where they are the delegator or the delegate.
     */
    public function listFor(int $actorUserId): array
    {
        $actorRole = (string) ($this->userById($actorUserId)['role'] ?? '');
        $seesAll   = in_array($actorRole, ['hr_manager', 'super_admin', 'managing_director'], true);

        if ($seesAll) {
            $stmt = $this->db->prepare("
                SELECT d.*,
                       du.first_name AS delegator_first_name, du.last_name AS delegator_last_name,
                       tu.first_name AS delegate_first_name, tu.last_name AS delegate_last_name
                FROM delegations d
                JOIN users du ON du.id = d.delegator_user_id
                JOIN users tu ON tu.id = d.delegate_user_id
                ORDER BY FIELD(d.status, 'pending', 'approved', 'active', 'cancelled', 'rejected', 'expired'), d.end_date, d.id DESC
            ");
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare("
                SELECT d.*,
                       du.first_name AS delegator_first_name, du.last_name AS delegator_last_name,
                       tu.first_name AS delegate_first_name, tu.last_name AS delegate_last_name
                FROM delegations d
                JOIN users du ON du.id = d.delegator_user_id
                JOIN users tu ON tu.id = d.delegate_user_id
                WHERE d.delegator_user_id = ? OR d.delegate_user_id = ?
                ORDER BY FIELD(d.status, 'pending', 'approved', 'active', 'cancelled', 'rejected', 'expired'), d.end_date, d.id DESC
            ");
            $stmt->bind_param('ii', $actorUserId, $actorUserId);
            $stmt->execute();
        }

        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->hydrate($row);
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Active users this delegator may appoint (§6): active employees inside
     * the delegator's organizational scope with an active user account,
     * excluding the delegator. Org-wide roles may appoint any active user.
     *
     * @return array<int, array{id:int, first_name:string, last_name:string, employee_id:string, role:string}>
     */
    public function eligibleDelegates(int $delegatorUserId): array
    {
        $employee = $this->employeeByUserId($delegatorUserId);
        if (!$employee) {
            return [];
        }
        $role = (string) ($employee['user_role'] ?? '');

        $base = "
            SELECT DISTINCT u.id, e.first_name, e.last_name, e.employee_id,
                   COALESCE(u.role, 'officer') AS role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE e.employee_status = 'active'
              AND u.is_active = 1
              AND u.id != ?
        ";
        $order = ' ORDER BY e.first_name, e.last_name LIMIT 200';

        $inOrgWideScope = in_array($role, self::ORG_WIDE_ROLES, true);
        $params = [$delegatorUserId];
        $types  = 'i';

        if (!$inOrgWideScope) {
            $scope = $this->resolveDelegatorScope($delegatorUserId, $role);
            if ($scope === null || $scope['type'] === 'organization') {
                return [];
            }
            $column = ['department' => 'e.department_id', 'section' => 'e.section_id', 'subsection' => 'e.subsection_id'][$scope['type']] ?? 'e.section_id';
            $base .= " AND {$column} = ?";
            $params[] = (int) $scope['id'];
            $types .= 'i';
        }

        $stmt = $this->db->prepare($base . $order);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'id'          => (int) $row['id'],
                'first_name'  => (string) $row['first_name'],
                'last_name'   => (string) $row['last_name'],
                'employee_id' => (string) $row['employee_id'],
                'role'        => (string) $row['role'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * The authority this role MAY delegate (§22): the role's granted
     * role_permissions restricted to DELEGATABLE modules, minus the hard
     * blacklist. Returned grouped by module plus a flat "module:action" list.
     *
     * @return array{flat: string[], grouped: array<string, string[]>}
     */
    public function delegatablePermissions(string $role): array
    {
        $placeholders = implode(',', array_fill(0, count(self::DELEGATABLE_MODULES), '?'));
        $stmt = $this->db->prepare("
            SELECT module, action FROM role_permissions
            WHERE role = ? AND is_granted = 1 AND module IN ({$placeholders})
        ");
        $types = 's' . str_repeat('s', count(self::DELEGATABLE_MODULES));
        $params = array_merge([$role], self::DELEGATABLE_MODULES);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $grouped = [];
        $flat = [];
        while ($row = $result->fetch_assoc()) {
            $module = (string) $row['module'];
            if (in_array($module, self::NON_DELEGATABLE_MODULES, true)) {
                continue;
            }
            $action = (string) $row['action'];
            $grouped[$module][] = $action;
            $flat[] = "{$module}:{$action}";
        }
        $stmt->close();

        return ['flat' => $flat, 'grouped' => $grouped];
    }

    // ────────────────────────────────────────────────────────────────────
    //  Effective authority resolution (AuthorizationService integration)
    // ────────────────────────────────────────────────────────────────────

    /**
     * All delegations currently conveying authority to the given user:
     * status approved/active AND today inside [start_date, end_date].
     * Cached per request (mirrors the AuthorizationService override cache —
     * §37: there is no persistent permission cache to invalidate, so a
     * delegation change takes effect on the very next resolution).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (array_key_exists($userId, $this->activeCache)) {
            return $this->activeCache[$userId];
        }

        $this->sweep();

        $stmt = $this->db->prepare("
            SELECT d.*,
                   du.first_name AS delegator_first_name, du.last_name AS delegator_last_name,
                   tu.first_name AS delegate_first_name, tu.last_name AS delegate_last_name
            FROM delegations d
            JOIN users du ON du.id = d.delegator_user_id
            JOIN users tu ON tu.id = d.delegate_user_id
            WHERE d.delegate_user_id = ?
              AND d.status IN ('approved', 'active')
              AND d.start_date <= CURDATE()
              AND d.end_date >= CURDATE()
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->hydrate($row);
        }
        $stmt->close();

        $this->activeCache[$userId] = $rows;
        return $rows;
    }

    /**
     * TRUE when an active delegation explicitly grants module:action to the
     * user. Called by AuthorizationService as the priority step between role
     * permissions and default deny. Blacklisted modules are refused here even
     * if a hand-edited row contained them (defence in depth, §32).
     */
    public function permissionAllowedByDelegation(int $userId, string $module, string $action): bool
    {
        if (in_array($module, self::NON_DELEGATABLE_MODULES, true)) {
            return false;
        }
        $needle = "{$module}:{$action}";
        foreach ($this->activeForUser($userId) as $delegation) {
            if (in_array($needle, $delegation['permissions'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flat list of delegated "module:action" strings for the frontend
     * permission context (sidebar / routes / buttons mirror of
     * permissionAllowedByDelegation). Blacklisted modules excluded.
     *
     * @return array<int, string>
     */
    public function activePermissionStrings(int $userId): array
    {
        $out = [];
        foreach ($this->activeForUser($userId) as $delegation) {
            foreach ($delegation['permissions'] as $perm) {
                [$mod] = array_pad(explode(':', $perm, 2), 2, '');
                if (in_array($mod, self::NON_DELEGATABLE_MODULES, true)) {
                    continue;
                }
                if (!in_array($perm, $out, true)) {
                    $out[] = $perm;
                }
            }
        }
        return $out;
    }

    /**
     * Lazy lifecycle sweep (§36): approved delegations whose window started
     * become 'active'; approved/active delegations past their end date become
     * 'expired'. Runs once per request from activeForUser() — no cron needed,
     * and authority is DATE-DRIVEN in the queries regardless of the sweep.
     */
    public function sweep(): void
    {
        if ($this->swept) {
            return;
        }
        $this->swept = true;

        try {
            // 1. Activation: approved + window started.
            $res = $this->db->query("
                SELECT id, delegate_user_id, delegator_user_id, end_date
                FROM delegations
                WHERE status = 'approved' AND start_date <= CURDATE()
            ");
            $activated = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $activated[] = $row;
                }
                $res->close();
            }
            if ($activated !== []) {
                $this->db->query("UPDATE delegations SET status = 'active' WHERE status = 'approved' AND start_date <= CURDATE()");
                foreach ($activated as $row) {
                    $this->notifyUser(
                        (int) $row['delegate_user_id'],
                        'Delegation Active',
                        'You are now acting as delegate for ' . $this->userName((int) $row['delegator_user_id']) . ' until ' . $row['end_date'] . '.',
                        'delegation_active',
                        '/delegations'
                    );
                }
            }

            // 2. Expiry: past the end date (approved never activated, or active).
            $res = $this->db->query("
                SELECT id, delegate_user_id, end_date
                FROM delegations
                WHERE status IN ('approved', 'active') AND end_date < CURDATE()
            ");
            $expiredRows = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $expiredRows[] = $row;
                }
                $res->close();
            }
            if ($expiredRows !== []) {
                $this->db->query("UPDATE delegations SET status = 'expired' WHERE status IN ('approved', 'active') AND end_date < CURDATE()");
                foreach ($expiredRows as $row) {
                    AuditService::getInstance()->log(
                        AuditService::MODULE_DELEGATIONS,
                        AuditService::ACTION_DELEGATION_EXPIRED,
                        "Delegation #{$row['id']} expired on {$row['end_date']}",
                        ['target_type' => 'Delegation', 'target_id' => (int) $row['id']]
                    );
                    $this->notifyUser(
                        (int) $row['delegate_user_id'],
                        'Delegation Expired',
                        'Your acting delegation ended on ' . $row['end_date'] . '; your normal permissions apply again.',
                        'delegation_expired',
                        '/delegations'
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[DelegationService] sweep failed: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────────────────
    //  Leave approval integration (§14–§19, §31)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Returns the active delegation authorizing $userId to decide the leave
     * application at its CURRENT pending stage, or null when the normal
     * (role-based) authorization path applies / no delegation covers it.
     *
     * Enforced together (§31 — backend is the final authority):
     *   1. A delegation is active for the delegate (status + date window).
     *   2. The delegated_role matches the role required by the stage.
     *   3. The application's applicant is inside the delegated scope.
     *   4. The delegate is NOT the applicant (self-approval guard, §32).
     *
     * @param array $app      leave_applications row (needs employee_id, status)
     * @param array $managers LeaveWorkflowService::getManagers() row for the
     *                        applicant (subsection_id/section_id/department_id)
     */
    public function canActAsLeaveApprover(int $delegateUserId, string $stageStatus, array $app, array $managers): ?array
    {
        $requiredRole = self::STAGE_ROLES[$stageStatus] ?? null;
        if ($requiredRole === null) {
            return null;
        }

        $delegateEmployeeId = $this->employeeIdOfUser($delegateUserId);
        if ($delegateEmployeeId === null || (int) ($app['employee_id'] ?? 0) === $delegateEmployeeId) {
            // No employee record, or the delegate is the applicant — never
            // authorize deciding your own application via delegation.
            return null;
        }

        foreach ($this->activeForUser($delegateUserId) as $delegation) {
            if ($delegation['delegated_role'] !== $requiredRole) {
                continue;
            }
            if (!$this->scopeCovers($delegation, $managers)) {
                continue;
            }
            return $delegation;
        }

        return null;
    }

    /**
     * TRUE when the delegation's snapshotted scope covers the applicant's
     * organizational unit (§8 — scope-aware authority).
     */
    private function scopeCovers(array $delegation, array $managers): bool
    {
        $scopeId = (int) $delegation['scope_id'];
        switch ($delegation['scope_type']) {
            case 'organization':
                return true;
            case 'department':
                return $scopeId > 0 && (int) ($managers['department_id'] ?? 0) === $scopeId;
            case 'section':
                return $scopeId > 0 && (int) ($managers['section_id'] ?? 0) === $scopeId;
            case 'subsection':
                return $scopeId > 0 && (int) ($managers['subsection_id'] ?? 0) === $scopeId;
            default:
                return false;
        }
    }

    /**
     * SQL WHERE fragments (OR-joined) extending the approver visibility
     * queries so a delegate sees EXACTLY the leave applications the delegator
     * would see, for as long as the delegation is active (§17/§18 — routing;
     * existing pending applications are covered automatically because this is
     * a plain scope query over the same tables).
     *
     * When $stageAware is TRUE (pending queues only), each fragment is
     * additionally restricted to the stage(s) whose required role equals this
     * delegation's delegated_role — mirroring canActAsLeaveApprover()'s
     * `STAGE_ROLES[status] === delegated_role` check so a section-head
     * delegation, for example, never surfaces pending_dept_head rows.
     * Scope fragments for approved/rejected history stay scope-only.
     *
     * Fragments reference the `e` alias (applicant employee row) used by
     * LeaveApprovalService's queries.
     *
     * @return array<int, string> e.g. ['(e.section_id = 5)']
     */
    public function delegatedVisibilityFragments(int $userId, bool $stageAware = false): array
    {
        $fragments = [];
        foreach ($this->activeForUser($userId) as $delegation) {
            switch ($delegation['scope_type']) {
                case 'organization':
                    // Org-wide authority (HR / MD / super admin delegator) —
                    // mirrors the delegator's own '1=1' visibility clause.
                    if (!$stageAware) {
                        return ['1=1'];
                    }
                    $scope = '1=1';
                    break;
                case 'department':
                    $scope = '(e.department_id = ' . (int) $delegation['scope_id'] . ')';
                    break;
                case 'section':
                    $scope = '(e.section_id = ' . (int) $delegation['scope_id'] . ')';
                    break;
                case 'subsection':
                    $scope = '(e.subsection_id = ' . (int) $delegation['scope_id'] . ')';
                    break;
                default:
                    continue 2;
            }

            if ($stageAware) {
                // Only the pending stage(s) this delegated_role may decide —
                // inverse of STAGE_ROLES (static keys, so the SQL stays static).
                $statuses = self::pendingStatusesForRole((string) ($delegation['delegated_role'] ?? ''));
                if ($statuses === []) {
                    // Not a role that decides any pending stage (e.g.
                    // delegated_role with no STAGE_ROLES entry) — contributes
                    // nothing to the pending queue.
                    continue;
                }
                $statusList = "'" . implode("','", $statuses) . "'";
                $fragments[] = $scope === '1=1'
                    ? "(la.status IN ({$statusList}))"
                    : "({$scope} AND la.status IN ({$statusList}))";
                continue;
            }

            $fragments[] = $scope;
        }
        return array_values(array_unique($fragments));
    }

    /**
     * Pending stage statuses whose required role equals $role — the inverse
     * of STAGE_ROLES (e.g. 'section_head' → ['pending_section_head']).
     *
     * @return array<int, string>
     */
    private static function pendingStatusesForRole(string $role): array
    {
        if ($role === '') {
            return [];
        }
        $statuses = [];
        foreach (self::STAGE_ROLES as $status => $stageRole) {
            if ($stageRole === $role) {
                $statuses[] = $status;
            }
        }
        return $statuses;
    }

    // ────────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ────────────────────────────────────────────────────────────────────

    /** Decode JSON permissions + derived display fields on a raw row. */
    private function hydrate(array $row): array
    {
        $decoded = json_decode((string) ($row['permissions'] ?? '[]'), true);
        $row['permissions'] = is_array($decoded) ? array_values($decoded) : [];
        $row['delegator_name'] = trim(($row['delegator_first_name'] ?? '') . ' ' . ($row['delegator_last_name'] ?? ''));
        $row['delegate_name']  = trim(($row['delegate_first_name'] ?? '') . ' ' . ($row['delegate_last_name'] ?? ''));
        $row['scope_label']    = $this->scopeLabel((string) ($row['scope_type'] ?? ''), (int) ($row['scope_id'] ?? 0));

        // Rows written before migration 096 have no `source` column value;
        // they are all the original hand-created delegations.
        $row['source'] = (string) ($row['source'] ?? self::SOURCE_MANUAL);
        $row['is_auto'] = $row['source'] === self::SOURCE_LEAVE_APPLICATION;

        // Pre-resolved labels so the Delegations page can render "who got
        // which page" without duplicating the permission catalog in the SPA.
        $row['permission_details'] = $this->permissionDetails($row['permissions']);

        return $row;
    }

    /**
     * Expand "module:action" strings into the catalog's human labels, e.g.
     * ['permission' => 'leave:approve', 'module_label' => 'Leave Management',
     *  'action_label' => 'Approve', 'type' => 'action'].
     *
     * The `type` field comes from the central catalog ('page' drives sidebar /
     * route visibility, 'action' drives buttons) which is exactly the
     * distinction the UI needs to answer "which PAGES did this delegate get".
     *
     * @param array<int, string> $permissions
     * @return array<int, array<string, string>>
     */
    private function permissionDetails(array $permissions): array
    {
        $catalog = self::permissionLabelMap();
        $details = [];
        foreach ($permissions as $permission) {
            [$module, $action] = array_pad(explode(':', (string) $permission, 2), 2, '');
            $moduleLabels = $catalog[$module] ?? null;
            if ($moduleLabels === null) {
                // Never drop a permission just because the catalog has not
                // caught up — show the raw key rather than hide the grant.
                $details[] = [
                    'permission'   => (string) $permission,
                    'module'       => $module,
                    'action'       => $action,
                    'module_label' => ucfirst(str_replace('_', ' ', $module)),
                    'action_label' => ucfirst(str_replace('_', ' ', $action)),
                    'type'         => 'action',
                ];
                continue;
            }
            $actionMeta = $moduleLabels['actions'][$action] ?? null;
            $details[] = [
                'permission'   => (string) $permission,
                'module'       => $module,
                'action'       => $action,
                'module_label' => $moduleLabels['label'],
                'action_label' => $actionMeta['label'] ?? ucfirst(str_replace('_', ' ', $action)),
                'type'         => $actionMeta['type'] ?? 'action',
            ];
        }
        return $details;
    }

    /**
     * module key => ['label' => string, 'actions' => [key => ['label','type']]]
     * built once from the central permission catalog.
     *
     * @return array<string, array{label: string, actions: array<string, array{label: string, type: string}>}>
     */
    private static function permissionLabelMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $catalog = require __DIR__ . '/../../config/permissions.php';
        foreach (($catalog['modules'] ?? []) as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $actions = [];
            foreach (($definition['actions'] ?? []) as $action) {
                $actionKey = (string) ($action['key'] ?? '');
                if ($actionKey === '') {
                    continue;
                }
                $actions[$actionKey] = [
                    'label' => (string) ($action['label'] ?? $actionKey),
                    'type'  => (string) ($action['type'] ?? 'action'),
                ];
            }
            $map[$key] = [
                'label'   => (string) ($definition['label'] ?? $key),
                'actions' => $actions,
            ];
        }
        return $map;
    }

    /** Human-readable scope label ("Section A", "Organization-wide", ...). */
    private function scopeLabel(string $scopeType, int $scopeId): string
    {
        if ($scopeType === 'organization') {
            return 'Organization-wide';
        }
        if ($scopeId <= 0) {
            return ucfirst($scopeType);
        }
        try {
            // Table names cannot be bound parameters, so each whitelisted
            // scope type gets its own fully static prepared statement
            // (SqlInjectionSurfaceTest invariant: mysqli must only ever
            // receive static SQL — no interpolation, no concatenation).
            $stmt = match ($scopeType) {
                'department' => $this->db->prepare('SELECT name FROM departments WHERE id = ?'),
                'section'    => $this->db->prepare('SELECT name FROM sections WHERE id = ?'),
                'subsection' => $this->db->prepare('SELECT name FROM subsections WHERE id = ?'),
                default      => null,
            };
            if ($stmt === null) {
                return ucfirst($scopeType);
            }
            $stmt->bind_param('i', $scopeId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['name'])) {
                return (string) $row['name'];
            }
        } catch (\Throwable $e) {
            error_log('[DelegationService] scope label failed: ' . $e->getMessage());
        }
        return ucfirst($scopeType) . ' #' . $scopeId;
    }

    private function userById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, email, first_name, last_name, role, is_active FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** Display name of a user ("First Last"), best-effort. */
    public function userName(int $userId): string
    {
        $user = $this->userById($userId);
        if (!$user) {
            return "User #{$userId}";
        }
        return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: "User #{$userId}";
    }

    private function employeeByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT e.*, u.role as user_role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ? AND e.employee_status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /** employees.id (int PK) for a users.id, or null. */
    private function employeeIdOfUser(int $userId): ?int
    {
        $stmt = $this->db->prepare('
            SELECT e.id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ? LIMIT 1
        ');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Snapshot the DELEGATOR'S organizational scope for the delegation row
     * (§8). Falls back from subsection to section for subsection heads
     * without an assigned subsection (mirrors the legacy scope rules).
     *
     * @return array{type: string, id: int, label: string}|null
     */
    private function resolveDelegatorScope(int $delegatorUserId, string $role): ?array
    {
        if (in_array($role, self::ORG_WIDE_ROLES, true)) {
            return ['type' => 'organization', 'id' => 0, 'label' => 'Organization-wide'];
        }

        $employee = $this->employeeByUserId($delegatorUserId);
        if (!$employee) {
            return null;
        }

        $subsectionId = (int) ($employee['subsection_id'] ?? 0);
        $sectionId    = (int) ($employee['section_id'] ?? 0);
        $departmentId = (int) ($employee['department_id'] ?? 0);

        switch ($role) {
            case 'sub_section_head':
                if ($subsectionId > 0) {
                    return ['type' => 'subsection', 'id' => $subsectionId, 'label' => $this->scopeLabel('subsection', $subsectionId)];
                }
                if ($sectionId > 0) {
                    return ['type' => 'section', 'id' => $sectionId, 'label' => $this->scopeLabel('section', $sectionId)];
                }
                return null;
            case 'section_head':
                return $sectionId > 0
                    ? ['type' => 'section', 'id' => $sectionId, 'label' => $this->scopeLabel('section', $sectionId)]
                    : null;
            case 'dept_head':
            case 'manager':
                return $departmentId > 0
                    ? ['type' => 'department', 'id' => $departmentId, 'label' => $this->scopeLabel('department', $departmentId)]
                    : null;
            default:
                return null;
        }
    }

    /**
     * TRUE when the candidate delegate works inside the delegator's unit
     * (§6) — mirrors the LeaveApplicationService delegate scope rules.
     */
    private function delegateInScope(int $delegatorUserId, string $role, int $delegateUserId): bool
    {
        $delegator = $this->employeeByUserId($delegatorUserId);
        $stmt = $this->db->prepare('
            SELECT subsection_id, section_id, department_id
            FROM employees
            WHERE employee_id = (SELECT employee_id FROM users WHERE id = ?)
            LIMIT 1
        ');
        $stmt->bind_param('i', $delegateUserId);
        $stmt->execute();
        $delegate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$delegator || !$delegate) {
            return false;
        }

        switch ($role) {
            case 'sub_section_head':
                if ((int) ($delegator['subsection_id'] ?? 0) > 0) {
                    return (int) $delegator['subsection_id'] === (int) $delegate['subsection_id'];
                }
                return (int) ($delegator['section_id'] ?? 0) > 0
                    && (int) $delegator['section_id'] === (int) $delegate['section_id'];
            case 'section_head':
                return (int) ($delegator['section_id'] ?? 0) > 0
                    && (int) $delegator['section_id'] === (int) $delegate['section_id'];
            case 'dept_head':
            case 'manager':
                return (int) ($delegator['department_id'] ?? 0) > 0
                    && (int) $delegator['department_id'] === (int) $delegate['department_id'];
            default:
                return false;
        }
    }

    /** §34 — overlap guard for the same (delegator, delegate) pair. */
    private function hasOverlappingDelegation(int $delegatorUserId, int $delegateUserId, string $startDate, string $endDate): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS c FROM delegations
            WHERE delegator_user_id = ?
              AND delegate_user_id = ?
              AND status IN ('pending', 'approved', 'active')
              AND start_date <= ?
              AND end_date >= ?
        ");
        $stmt->bind_param('iiss', $delegatorUserId, $delegateUserId, $endDate, $startDate);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count > 0;
    }

    /**
     * Approval-side guard shared by approve()/reject(): the approver must be
     * neither the delegator nor the delegate, and org-wide delegators can
     * only be approved by a super_admin (no self-approval anywhere, §11/§32).
     *
     * @return array|null null = allowed; array = error result
     */
    private function assertCanDecide(int $approverUserId, array $delegation): ?array
    {
        if ($approverUserId === (int) $delegation['delegator_user_id']) {
            return ['success' => false, 'message' => 'The delegator cannot approve their own delegation'];
        }
        if ($approverUserId === (int) $delegation['delegate_user_id']) {
            return ['success' => false, 'message' => 'The delegate cannot approve this delegation'];
        }

        $approverRole = (string) ($this->userById($approverUserId)['role'] ?? '');
        if (in_array($delegation['delegated_role'], self::ORG_WIDE_ROLES, true)) {
            if ($approverRole !== 'super_admin') {
                return ['success' => false, 'message' => 'Only a Super Admin can approve a delegation of org-wide authority'];
            }
            return null;
        }

        if (!in_array($approverRole, ['hr_manager', 'super_admin'], true)) {
            return ['success' => false, 'message' => 'Only HR or a Super Admin can approve delegations'];
        }
        return null;
    }

    /** Roles that approve delegations created by the given delegator role. */
    private function approvalRolesFor(string $delegatorRole): array
    {
        return in_array($delegatorRole, self::ORG_WIDE_ROLES, true)
            ? ['super_admin']
            : ['hr_manager', 'super_admin'];
    }

    private function notifyUser(int $userId, string $title, string $message, string $type, ?string $link = null): void
    {
        try {
            NotificationService::getInstance()->sendInApp($userId, $title, $message, $type, $link);
        } catch (\Throwable $e) {
            error_log('[DelegationService] notification failed: ' . $e->getMessage());
        }
    }

    private function notifyRoles(array $roles, string $title, string $message, string $type, ?string $link = null): void
    {
        if ($roles === []) {
            return;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $types = str_repeat('s', count($roles));
            $stmt = $this->db->prepare("SELECT id FROM users WHERE role IN ({$placeholders}) AND is_active = 1");
            $stmt->bind_param($types, ...$roles);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $this->notifyUser((int) $row['id'], $title, $message, $type, $link);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('[DelegationService] role notification failed: ' . $e->getMessage());
        }
    }

    /** Reset the per-request active-delegation cache (after mutations). */
    private function clearActiveCache(): void
    {
        $this->activeCache = [];
    }

    /** Test hook: reset the singleton between tests. */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }













}

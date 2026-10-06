<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Auth;
use App\Services\Leave\InvalidLeaveTransitionException;
use App\Services\Leave\LeaveTypePolicy;
use App\Services\Leave\LeaveWorkflowRules;

/**
 * LeaveApprovalService
 *
 * Handles the approval workflow for leave applications.
 * Manages the multi-stage approval hierarchy:
 *   subsection_head → section_head → dept_head → managing_director → bod_chair → hr
 *
 * This service was referenced by LeaveController but did not exist,
 * causing a fatal error on controller instantiation.  It is now
 * implemented with the full approval / rejection / cancellation
 * lifecycle, reusing the existing LeaveWorkflowService for hierarchy
 * resolution and LeaveApplicationService for balance updates.
 */
class LeaveApprovalService
{
    private \mysqli $db;
    private LeaveWorkflowService $workflowService;
    private LeaveCalculationService $calculationService;
    private DelegationService $delegationService;
    private DelegateService $delegateService;
    private \App\Services\Notification\LeaveNotificationService $notificationService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->workflowService = new LeaveWorkflowService();
        $this->calculationService = new LeaveCalculationService();
        $this->delegationService = DelegationService::getInstance();
        $this->delegateService = new DelegateService();
        $this->notificationService = new \App\Services\Notification\LeaveNotificationService();
    }

    /**
     * List leave applications for the current approver, grouped by
     * status (pending / approved / rejected).
     *
     * @param int $userId
     * @param array $pagination
     * @return array
     */
    public function listForApprover(int $userId, array $pagination): array
    {
        $limit = (int) ($pagination['limit'] ?? 15);

        // Get the current user's employee record and role
        $stmt = $this->db->prepare("
            SELECT e.*, u.role as user_role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ? AND e.employee_status = 'active'
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found', 'data' => ['counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0], 'role' => '']];
        }

        $role = $currentUser['user_role'] ?? 'officer';
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return ['success' => false, 'message' => 'User not found', 'data' => ['counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0], 'role' => $role]];
        }

        $pendingOffset  = (int) ($pagination['pending_offset'] ?? 0);
        $approvedOffset = (int) ($pagination['approved_offset'] ?? 0);
        $rejectedOffset = (int) ($pagination['rejected_offset'] ?? 0);

        // Determine which applications this user can see based on their role
        $pendingApps = $this->getPendingForApprover($userId, $role, $currentUser, $limit, $pendingOffset);
        $approvedApps = $this->getApprovedForApprover($userId, $role, $currentUser, $limit, $approvedOffset);
        $rejectedApps = $this->getRejectedForApprover($userId, $role, $currentUser, $limit, $rejectedOffset);

        // Enrich rows with approver names / employee codes so the UI does not
        // fall back to "System" / "Not Assigned".  Pending rows resolve their
        // *next* approver from the org-hierarchy *_emp_id columns; approved and
        // rejected rows resolve their *actual decider* from the per-stage
        // *_approved_by columns (see resolveDeciders()).
        $pendingApps  = $this->attachPendingApprovers($pendingApps);
        $approvedApps = $this->resolveDeciders($approvedApps);
        $rejectedApps = $this->resolveDeciders($rejectedApps);

        // Counts must be the true total, independent of the LIMIT, otherwise a
        // limit=1 request would always report 1/1/1.

        // Counts must reflect the true total, independent of the applied LIMIT,
        // otherwise a limit=1 counts request would always report 1/1/1.
        $pendingTotal  = $this->countForApprover($role, $employeeId, $currentUser, 'pending', $userId);
        $approvedTotal = $this->countForApprover($role, $employeeId, $currentUser, 'approved', $userId);
        $rejectedTotal = $this->countForApprover($role, $employeeId, $currentUser, 'rejected', $userId);

        return [
            'success' => true,
            'data' => [
                'counts' => [
                    'pending'  => $pendingTotal,
                    'approved' => $approvedTotal,
                    'rejected' => $rejectedTotal,
                ],
                'role' => $role,
                'pending'  => $pendingApps,
                'approved' => $approvedApps,
                'rejected' => $rejectedApps,
            ],
        ];
    }

    /**
     * Approve a leave application (advance it one step in the workflow).
     */
    public function approve(int $userId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $role = $currentUser['role'] ?? '';

        // Verify the user is an authorised approver for this application
        if (!$this->isAuthorisedApprover($userId, $app, $role)) {
            return ['success' => false, 'message' => 'You are not authorised to approve this application'];
        }

        // Resolve the ACTIVE DELEGATION covering this decision, if any, so the
        // approval history records the delegation reference + the ORIGINAL
        // approver (§19). Null when the natural role path authorized it.
        $delegation = $this->activeDelegationFor($userId, $app);

        // Phase 5 §6/§21: formal transition guard. Approve is only valid from
        // a pending stage — repeat approvals (double-clicks, or the HR /
        // super-admin bypass acting on an already-decided application) are
        // blocked here so the balance ledger can NEVER be applied twice.
        try {
            LeaveWorkflowRules::assertCanDecide($app['status'], 'approve');
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'approve',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $currentStatus = $app['status'];
        $nextStatus = $this->getNextStatus($currentStatus, $role, $app);

        if ($nextStatus === null) {
            return ['success' => false, 'message' => 'This application cannot be approved at this stage'];
        }

        $this->db->begin_transaction();
        try {
            // Update the application status
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sii', $nextStatus, $userId, $applicationId);
            $stmt->execute();
            $stmt->close();

            // If fully approved, apply balance updates
            if ($nextStatus === 'approved') {
                $this->applyBalanceUpdates($applicationId, $app);
            }

            // Log history
            $this->logHistory($applicationId, $userId, 'approved', $app, null, $delegation);

            $this->db->commit();

            // After commit: the decision is durable, and a notification failure
            // must never be able to roll back an approval that already happened.
            $fresh = $this->notificationService->findApplication($applicationId);
            if ($fresh !== null) {
                if ($nextStatus === 'approved') {
                    $this->notificationService->notifyFullyApproved($fresh, $userId);
                } else {
                    $this->notificationService->notifyStageAdvanced($fresh, $currentStatus, $userId);
                }
            }

            // Terminal approval also hands the appointed duty-cover delegate
            // their temporary authority (DelegationService::createFromApprovedLeave).
            // Deliberately AFTER commit: the leave is approved either way, and
            // the service swallows + logs its own failures so a delegation
            // problem can never undo a decision the approver already made.
            if ($nextStatus === LeaveWorkflowRules::STATUS_APPROVED) {
                $this->delegationService->createFromApprovedLeave($applicationId, $userId);
            }

            return [
                'success' => true,
                'message' => $nextStatus === 'approved'
                    ? 'Leave application fully approved'
                    : "Leave application approved — forwarded to next approver",
                'data' => ['status' => $nextStatus],
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave approval failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the approval. Please try again.'];
        }
    }

    /**
     * Reject a leave application.
     */
    public function reject(int $userId, int $applicationId, string $reason): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $role = $currentUser['role'] ?? '';

        if (!$this->isAuthorisedApprover($userId, $app, $role)) {
            return ['success' => false, 'message' => 'You are not authorised to reject this application'];
        }

        // Resolve the ACTIVE DELEGATION covering this decision, if any, so the
        // approval history records the delegation reference + the ORIGINAL
        // approver (§19). Null when the natural role path authorized it.
        $delegation = $this->activeDelegationFor($userId, $app);

        // Phase 5 §6: reject is only valid from a pending stage. Rejecting an
        // already-approved application would desynchronise the status from
        // the balances deducted at approval time.
        try {
            LeaveWorkflowRules::assertCanDecide($app['status'], 'reject');
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'reject',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $this->db->begin_transaction();
        try {
            // Persist the ACTUAL decider (the logged-in users.id) plus the reason so
            // the manage tabs show "WHO rejected / why" instead of falling back to
            // "System" / "—".  The generic approved_by pair always captures the
            // rejecter (outranking any previous forwarding approver in
            // resolveDeciders()); the per-stage *_approved_by column matching the
            // current stage is ALSO filled for backward compatibility with legacy
            // consumers (stages without a dedicated column such as bod_chair /
            // manager simply rely on the generic pair).
            $stageCols = [
                'pending_subsection_head'   => ['subsection_head_approved_by',   'subsection_head_approved_at'],
                'pending_section_head'      => ['section_head_approved_by',        'section_head_approved_at'],
                'pending_dept_head'         => ['dept_head_approved_by',          'dept_head_approved_at'],
                'pending_managing_director' => ['managing_director_approved_by', 'managing_director_approved_at'],
                'pending_hr'                => ['hr_approved_by',                 'hr_approved_at'],
                'pending_hr_manager'        => ['hr_approved_by',                 'hr_approved_at'],
            ];
            [$deciderCol, $deciderAtCol] = $stageCols[$app['status']] ?? ['approved_by', 'approved_at'];

            $sets = [
                "status = 'rejected'",
                'rejection_reason = ?',
            ];
            $params = [mb_substr($reason, 0, 1000)];
            $types = 's';

            if ($deciderCol !== 'approved_by') {
                // Stamp the generic decision pair as well so resolveDeciders()
                // always sees the real rejecter (never a stale forwarding stage);
                // stages without a dedicated column use the pair via the fallback.
                $sets[] = 'approved_by = ?';
                $sets[] = 'approved_at = NOW()';
                $params[] = $userId;
                $types .= 'i';
            }

            $sets[] = $deciderCol . ' = ?';
            $params[] = $userId;
            $types .= 'i';

            if ($deciderAtCol !== null) {
                $sets[] = $deciderAtCol . ' = NOW()';
            }
            $sets[] = 'updated_at = NOW()';

            $sql = "UPDATE leave_applications SET " . implode(', ', $sets) . " WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types . 'i', ...array_merge($params, [$applicationId]));
            $stmt->execute();
            $stmt->close();

            $this->logHistory($applicationId, $userId, 'rejected', $app, $reason, $delegation);

            $this->db->commit();

            $fresh = $this->notificationService->findApplication($applicationId);
            if ($fresh !== null) {
                $this->notificationService->notifyRejected($fresh, $reason, $userId);
            }

            return ['success' => true, 'message' => 'Leave application rejected', 'data' => ['status' => 'rejected']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave rejection failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the rejection. Please try again.'];
        }
    }

    /**
     * Invalidate a leave application (admin-only, removes from active workflow).
     */
    public function invalidate(int $userId, int $applicationId, string $reason): array
    {
        $auth = Auth::getInstance();
        if (!$auth->isSuperAdmin() && !$auth->isHRManager()) {
            return ['success' => false, 'message' => 'Only HR or Super Admin can invalidate applications'];
        }

        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        // Phase 5 §5/§6: invalidation is the formal REVERSAL path. Allowed
        // from pending stages and from approved (which must restore the
        // deducted balances); rejected/cancelled/invalidated are final.
        $wasApproved = $app['status'] === LeaveWorkflowRules::STATUS_APPROVED;
        try {
            LeaveWorkflowRules::assertCanInvalidate($app['status']);
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'invalidate',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'invalidated', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $stmt->close();

            // Reversing a fully-approved application restores the balances
            // that were deducted at approval time (mirror of the deduction).
            if ($wasApproved) {
                $this->reverseBalanceUpdates($applicationId, $app);
            }

            $this->logHistory($applicationId, $userId, 'invalidated', $app, $reason);

            $this->db->commit();

            // The person is no longer away, so any duty-cover delegation minted
            // for this leave stops immediately. Scoped to THIS application's
            // auto row, so an unrelated manual delegation is never touched.
            if ($wasApproved) {
                $this->delegationService->cancelForLeaveApplication(
                    $applicationId,
                    'Leave application #' . $applicationId . ' was invalidated.'
                );
            }

            $fresh = $this->notificationService->findApplication($applicationId);
            if ($fresh !== null) {
                $this->notificationService->notifyWithdrawn($fresh, 'invalidated');
            }

            return ['success' => true, 'message' => 'Leave application invalidated', 'data' => ['status' => 'invalidated']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave invalidation failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the invalidation. Please try again.'];
        }
    }

    /**
     * Cancel a still-pending leave application.  Only the applicant may cancel.
     */
    public function cancel(int $userId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        // Only the applicant can cancel
        $applicantEmployeeId = $this->getEmployeeIdFromUserId($userId);
        if ($applicantEmployeeId != $app['employee_id']) {
            return ['success' => false, 'message' => 'Only the applicant can cancel this application'];
        }

        // Can only cancel if still in a pending state. The pending set comes
        // from the single source of truth (LeaveWorkflowRules) — the previous
        // hand-written list silently omitted the 'pending' (column default)
        // and 'pending_hr_manager' stages, making those applications
        // impossible to cancel.
        if (!LeaveWorkflowRules::isPending($app['status'])) {
            return ['success' => false, 'message' => 'Only pending applications can be cancelled'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $stmt->close();

            $this->logHistory($applicationId, $userId, 'cancelled', $app);

            $this->db->commit();

            $fresh = $this->notificationService->findApplication($applicationId);
            if ($fresh !== null) {
                $this->notificationService->notifyWithdrawn($fresh, 'cancelled');
            }

            return ['success' => true, 'message' => 'Leave application cancelled', 'data' => ['status' => 'cancelled']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave cancellation failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the cancellation. Please try again.'];
        }
    }

    // ───────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get a leave application by ID with leave type info.
     */
    private function getApplication(int $applicationId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT la.*, lt.name as leave_type_name
            FROM leave_applications la
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get a user by ID.
     */
    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get employee ID from user ID.
     */
    private function getEmployeeIdFromUserId(int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT e.id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : null;
    }

    /**
     * Public read of the approver check for callers that are NOT making a
     * decision — e.g. the leave-document endpoints, where a supervisor must be
     * able to read the evidence for an application they may decide.
     *
     * Deliberately read-only: approve/reject keep calling the private
     * isAuthorisedApprover() so nothing outside this service can transition a
     * workflow state.
     *
     * Returns false for a missing application or user.
     */
    public function isAuthorisedApproverForApplication(int $userId, int $applicationId): bool
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return false;
        }

        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }

        return $this->isAuthorisedApprover($userId, $app, (string) ($user['role'] ?? ''));
    }

    /**
     * Check if the user is an authorised approver for this application.
     */
    private function isAuthorisedApprover(int $userId, array $app, string $role): bool
    {
        $auth = Auth::getInstance();

        // Super admin and HR can approve anything
        if ($auth->isSuperAdmin() || $auth->isHRManager()) {
            return true;
        }

        $currentEmployeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$currentEmployeeId) {
            return false;
        }

        $managers = $this->workflowService->getManagers($app['employee_id']);
        $status = $app['status'];

        switch ($status) {
            case 'pending_subsection_head':
                return $role === 'sub_section_head'
                    && $managers['subsection_head_emp_id'] == $currentEmployeeId;
            case 'pending_section_head':
                return in_array($role, ['section_head', 'sub_section_head'])
                    && $managers['section_head_emp_id'] == $currentEmployeeId;
            case 'pending_dept_head':
                return in_array($role, ['dept_head', 'section_head', 'sub_section_head'])
                    && $managers['dept_head_emp_id'] == $currentEmployeeId;
            case 'pending_managing_director':
                return $role === 'managing_director';
            case 'pending_hr':
                return $role === 'hr_manager';
            case 'pending_bod_chair':
                return $role === 'bod_chair' || $role === 'bod_chairman';
            case 'pending_manager':
                return $role === 'manager';
        }

        // ── Temporary Delegation / Acting Authority ─────────────────────
        // An active, HR-approved delegation whose delegated_role matches the
        // role required by the current stage, whose snapshotted scope covers
        // the applicant's unit, and whose delegate is not the applicant,
        // authorizes this decision (spec §14/§18/§31).
        try {
            if ($this->delegationService->canActAsLeaveApprover($userId, $status, $app, $managers) !== null) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[LeaveApprovalService] delegation check failed: ' . $e->getMessage());
        }

        // ── Applicant-picked duty-cover delegate (existing 012 feature) ──
        // A delegate recorded on the application itself may decide ONLY when
        // the natural approver at this stage is the applicant (self-
        // application backup case). This was previously dead code — wired in
        // as documented intent.
        try {
            if ($this->delegateService->canDelegateApprove((int) $app['id'], $userId)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[LeaveApprovalService] duty-cover delegate check failed: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * The active delegation (if any) that authorizes $userId to decide $app at
     * its current stage. Only queried AFTER isAuthorisedApprover() succeeded,
     * so the extra lookup runs for delegated decisions and natural approvers
     * alike but stays cheap (per-request cached inside DelegationService).
     */
    private function activeDelegationFor(int $userId, array $app): ?array
    {
        try {
            $managers = $this->workflowService->getManagers((int) $app['employee_id']);
            return $this->delegationService->canActAsLeaveApprover(
                $userId,
                (string) ($app['status'] ?? ''),
                $app,
                $managers
            );
        } catch (\Throwable $e) {
            error_log('[LeaveApprovalService] active delegation lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Wrap an approver visibility clause with the delegated scopes of an
     * active delegation (§17/§18): the delegate sees EXACTLY what the
     * delegator would see, for as long as the delegation is active. When no
     * delegation applies, the clause is returned unchanged.
     *
     * $stageAware (pending queues only) additionally restricts each fragment
     * to the stage(s) the delegated_role owns, mirroring
     * DelegationService::canActAsLeaveApprover().
     */
    private function wrapWithDelegatedScopes(string $where, int $userId, bool $stageAware = false): string
    {
        try {
            $fragments = $this->delegationService->delegatedVisibilityFragments($userId, $stageAware);
        } catch (\Throwable $e) {
            return $where;
        }
        if ($fragments === []) {
            return $where;
        }
        return '(' . $where . ' OR ' . implode(' OR ', $fragments) . ')';
    }

    /**
     * WHERE fragment for the PENDING queue: list ONLY applications the current
     * user is actually entitled to decide right now.
     *
     * Mirrors isAuthorisedApprover() stage-by-stage (role gate + LIVE org-head
     * lookup — the same correlated subqueries as LeaveWorkflowService::
     * getManagers()), so that:
     *   - a row leaves an approver's queue as soon as it advances to a stage
     *     they cannot decide (section head approves → pending_dept_head →
     *     disappears from the section head's Pending tab);
     *   - approvers never see their OWN application (self-approval is not a
     *     decision path — the applicant tracks it under "My Leave");
     *   - delegations and applicant-picked duty-cover delegates are matched
     *     stage-aware too.
     *
     * Scope-only visibility (buildApproverWhereClause) is deliberately NOT
     * used for pending rows: it lists every pending row in the approver's org
     * unit regardless of stage, which produced "You are not authorised to
     * approve this application" on rows the user could not decide.
     *
     * The SAME fragment is used by getPendingForApprover() and
     * countForApprover('pending') so the tab badge always matches the rows.
     */
    private function buildPendingAuthorityWhere(int $userId, string $role, int $employeeId, array $currentUser): string
    {
        $empId = (int) $employeeId;
        $auth = Auth::getInstance();
        $branches = [];

        // HR manager / super admin bypass: they may decide ANY pending stage
        // (isAuthorisedApprover() returns true before the stage switch).
        if ($auth->isSuperAdmin() || $auth->isHRManager()) {
            $branches[] = '1=1';
        } else {
            // LIVE org-head lookups — byte-for-byte mirrors of the correlated
            // subqueries in LeaveWorkflowService::getManagers(). Alias `e` is
            // the applicant's employees row, already joined by both callers.
            $liveSubsectionHead = "(SELECT e2.id FROM employees e2 JOIN users u2 ON u2.employee_id = e2.employee_id WHERE e2.subsection_id = e.subsection_id AND u2.role = 'sub_section_head' LIMIT 1)";
            $liveSectionHead    = "(SELECT e3.id FROM employees e3 JOIN users u3 ON u3.employee_id = e3.employee_id WHERE e3.section_id = e.section_id AND u3.role = 'section_head' LIMIT 1)";
            $liveDeptHead       = "(SELECT e4.id FROM employees e4 JOIN users u4 ON u4.employee_id = e4.employee_id WHERE e4.department_id = e.department_id AND u4.role = 'dept_head' LIMIT 1)";

            if ($role === 'sub_section_head') {
                $branches[] = "(la.status = 'pending_subsection_head' AND {$liveSubsectionHead} = {$empId})";
            }
            if (in_array($role, ['section_head', 'sub_section_head'], true)) {
                $branches[] = "(la.status = 'pending_section_head' AND {$liveSectionHead} = {$empId})";
            }
            if (in_array($role, ['dept_head', 'section_head', 'sub_section_head'], true)) {
                $branches[] = "(la.status = 'pending_dept_head' AND {$liveDeptHead} = {$empId})";
            }
            if ($role === 'managing_director') {
                // Mirrors isAuthorisedApprover(): role check only, no emp-id match.
                $branches[] = "la.status = 'pending_managing_director'";
            }
            if (in_array($role, ['bod_chair', 'bod_chairman'], true)) {
                $branches[] = "la.status = 'pending_bod_chair'";
            }
            if ($role === 'manager') {
                $branches[] = "la.status = 'pending_manager'";
            }
            if ($role === 'hr_manager') {
                // isAuthorisedApprover()'s switch: pending_hr → hr_manager.
                // (The session bypass above normally covers this already;
                // this branch keeps DB-role and session-role views identical.)
                $branches[] = "la.status = 'pending_hr'";
            }
            // pending_hr_manager: no natural branch on purpose — mirrors
            // isAuthorisedApprover(), which has NO switch case for it either;
            // only the HR/super-admin bypass or a stage-matched delegation
            // decides those rows.
        }

        $natural = $branches !== [] ? '(' . implode(' OR ', $branches) . ')' : '1=0';

        // Active delegations: delegator's scope AND the stage(s) the
        // delegated_role owns (routed like canActAsLeaveApprover()).
        $authority = $this->wrapWithDelegatedScopes($natural, $userId, true);

        // Applicant-picked duty-cover delegate (012 feature): mirrors
        // DelegateService::canDelegateApprove() — valid only when the natural
        // approver at this stage IS the applicant (self-application backup).
        $dutyCover = $this->dutyCoverDelegateClause($empId, $currentUser);
        if ($dutyCover !== null) {
            $authority = '(' . $authority . ' OR ' . $dutyCover . ')';
        }

        // Never surface your own application in the approvals queue.
        return '(la.employee_id <> ' . $empId . ' AND ' . $authority . ')';
    }

    /**
     * SQL branch for the applicant-picked duty-cover delegate (mirror of
     * DelegateService::canDelegateApprove()): the recorded delegate may decide
     * a pending stage ONLY when that stage's natural approver is the applicant
     * themself (self-application backup) and the delegate shares the
     * applicant's org unit. Delegate ≠ applicant is already enforced by the
     * caller's `la.employee_id <> ...` exclusion.
     *
     * Returns null when the user has no org unit (can never be a duty-cover).
     */
    private function dutyCoverDelegateClause(int $employeeId, array $currentUser): ?string
    {
        $emp = (int) $employeeId;
        $mySubsection = (int) ($currentUser['subsection_id'] ?? 0);
        $mySection    = (int) ($currentUser['section_id'] ?? 0);
        $myDepartment = (int) ($currentUser['department_id'] ?? 0);

        // Applicant's user role — mirror of DelegateService::getEmployeeRole().
        $applicantRole = "(SELECT u2.role FROM users u2 WHERE u2.employee_id = e.employee_id LIMIT 1)";

        $branches = [];
        if ($mySubsection > 0) {
            $branches[] = "(la.status = 'pending_subsection_head' AND {$applicantRole} = 'sub_section_head' AND e.subsection_id = {$mySubsection})";
        }
        if ($mySection > 0) {
            $branches[] = "(la.status = 'pending_section_head' AND {$applicantRole} = 'section_head' AND e.section_id = {$mySection})";
        }
        if ($myDepartment > 0) {
            $branches[] = "(la.status = 'pending_dept_head' AND {$applicantRole} = 'dept_head' AND e.department_id = {$myDepartment})";
        }

        if ($branches === []) {
            return null;
        }

        return '(la.delegate_emp_id = ' . $emp . ' AND (' . implode(' OR ', $branches) . '))';
    }

    /**
     * Get the next status in the approval workflow.
     * Considers the applicant's role so ordinary employee leaves terminate
     * at Department Head / HR rather than escalating to Managing Director and Board Chair.
     */
    private function getNextStatus(string $currentStatus, string $approverRole, array $app = []): ?string
    {
        if ($approverRole === 'super_admin') {
            return 'approved';
        }

        // Get applicant's role in the organization
        $applicantRole = 'officer';
        if (!empty($app['employee_id'])) {
            $stmt = $this->db->prepare("
                SELECT COALESCE(u.role, 'officer') as role
                FROM employees e
                LEFT JOIN users u ON u.employee_id = e.employee_id
                WHERE e.id = ?
            ");
            $empId = (int)$app['employee_id'];
            $stmt->bind_param('i', $empId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['role'])) {
                $applicantRole = $row['role'];
            }
        }

        // Standard staff (officer, employee, sub_section_head, section_head)
        // Dept Head approval is the final approval for departmental staff.
        if (in_array($applicantRole, ['officer', 'employee', 'sub_section_head', 'section_head', ''], true)) {
            switch ($currentStatus) {
                case 'pending_subsection_head':
                    return 'pending_section_head';
                case 'pending_section_head':
                    return 'pending_dept_head';
                case 'pending_dept_head':
                case 'pending_hr':
                case 'pending_manager':
                    return 'approved';
                default:
                    return 'approved';
            }
        }

        // Department Heads / HR Managers: escalate to Managing Director
        if (in_array($applicantRole, ['dept_head', 'manager', 'hr_manager'], true)) {
            switch ($currentStatus) {
                case 'pending_managing_director':
                case 'pending_hr':
                    return 'approved';
                default:
                    return 'pending_managing_director';
            }
        }

        // Managing Director: escalate to Board Chairman
        if ($applicantRole === 'managing_director') {
            switch ($currentStatus) {
                case 'pending_bod_chair':
                    return 'approved';
                default:
                    return 'pending_bod_chair';
            }
        }

        return 'approved';
    }

    /**
     * Apply balance updates when an application is fully approved.
     */
    private function applyBalanceUpdates(int $applicationId, array $app): void
    {
        $leaveTypeId = (int) $app['leave_type_id'];
        $employeeId = (int) $app['employee_id'];
        $financialYearId = (int) $app['financial_year_id'];
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays = (float) ($app['annual_days'] ?? 0);

        // Claim a Day — credit annual leave
        if ($leaveTypeId === LeaveTypePolicy::TYPE_CLAIM_A_DAY) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $daysToAdd = (float) ($primaryDays > 0 ? $primaryDays : ($app['days_requested'] ?? 0));
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET allocated_days = allocated_days + ?,
                    accumulated_days = accumulated_days + ?,
                    remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('dddiii', $daysToAdd, $daysToAdd, $daysToAdd, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        // Leave of Absence — no balance deduction
        if ($leaveTypeId === LeaveTypePolicy::TYPE_ABSENCE) {
            return;
        }

        // Normal leave — deduct from primary balance
        if ($primaryDays > 0) {
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $primaryDays, $primaryDays, $employeeId, $leaveTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }

        // Deduct from annual leave if applicable
        if ($annualDays > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $annualDays, $annualDays, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Restore balances when a fully-approved application is invalidated
     * (Phase 5 §5 reversal). Exact mirror of applyBalanceUpdates() with
     * inverted deltas; runs inside the caller's transaction.
     */
    private function reverseBalanceUpdates(int $applicationId, array $app): void
    {
        $leaveTypeId = (int) $app['leave_type_id'];
        $employeeId = (int) $app['employee_id'];
        $financialYearId = (int) $app['financial_year_id'];
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays = (float) ($app['annual_days'] ?? 0);

        // Claim a Day — remove the annual leave credit
        if ($leaveTypeId === LeaveTypePolicy::TYPE_CLAIM_A_DAY) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $daysToAdd = (float) ($primaryDays > 0 ? $primaryDays : ($app['days_requested'] ?? 0));
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET allocated_days = allocated_days - ?,
                    accumulated_days = accumulated_days - ?,
                    remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('dddiii', $daysToAdd, $daysToAdd, $daysToAdd, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        // Leave of Absence — no balance movement to reverse
        if ($leaveTypeId === LeaveTypePolicy::TYPE_ABSENCE) {
            return;
        }

        // Restore the primary balance deduction
        if ($primaryDays > 0) {
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days - ?, remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $primaryDays, $primaryDays, $employeeId, $leaveTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }

        // Restore the annual leave deduction
        if ($annualDays > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days - ?, remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $annualDays, $annualDays, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Log an approval history entry.
     */
    private function logHistory(int $applicationId, int $userId, string $action, array $app, ?string $comment = null, ?array $delegation = null): void
    {
        $delegationId   = $delegation !== null ? (int) $delegation['id'] : null;
        $actedForUserId = $delegation !== null ? (int) $delegation['delegator_user_id'] : null;

        $comment = $comment ?? "Leave application {$action} by user {$userId}";
        if ($delegationId !== null) {
            // §19/§39/§40 — the history must show the ORIGINAL approver (the
            // delegator) and the delegation reference next to the acting
            // user; it must NOT read as if the delegate held the role.
            $delegatorName = $this->delegationService->userName($actedForUserId ?? 0);
            $comment .= ' — acting as delegate for ' . $delegatorName . ' (Delegation #' . $delegationId . ')';
        }

        $stmt = $this->db->prepare("
            INSERT INTO leave_history (leave_application_id, action, performed_by, delegation_id, acted_for_user_id, comments, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isiiss', $applicationId, $action, $userId, $delegationId, $actedForUserId, $comment);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get pending applications for an approver.
     */
    private function getPendingForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $pendingStatuses = "'pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_hr','pending_hr_manager','pending_bod_chair','pending_manager'";

        // Stage-aware AUTHORITY filter (not scope): only rows this user may
        // decide at their CURRENT stage, never their own application. See
        // buildPendingAuthorityWhere() — the same fragment feeds the count.
        $where = $this->buildPendingAuthorityWhere($userId, $role, $employeeId, $currentUser);

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE la.status IN ({$pendingStatuses})
              AND {$where}
            ORDER BY la.applied_at DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Get approved applications for an approver.
     */
    private function getApprovedForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $where = $this->wrapWithDelegatedScopes(
            $this->buildApproverWhereClause($role, $employeeId, $currentUser, 'approved'),
            $userId
        );

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no,
                   lh_del.delegation_id AS delegate_delegation_id,
                   lh_del.acted_for_user_id AS acted_for_user_id
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_history lh_del ON lh_del.id = (
                SELECT MAX(x.id) FROM leave_history x
                WHERE x.leave_application_id = la.id AND x.delegation_id IS NOT NULL
            )
            WHERE la.status = 'approved'
              AND {$where}
            ORDER BY la.applied_at DESC, la.id DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Get rejected applications for an approver.
     */
    private function getRejectedForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $where = $this->wrapWithDelegatedScopes(
            $this->buildApproverWhereClause($role, $employeeId, $currentUser, 'rejected'),
            $userId
        );

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no,
                   lh_del.delegation_id AS delegate_delegation_id,
                   lh_del.acted_for_user_id AS acted_for_user_id
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_history lh_del ON lh_del.id = (
                SELECT MAX(x.id) FROM leave_history x
                WHERE x.leave_application_id = la.id AND x.delegation_id IS NOT NULL
            )
            WHERE la.status = 'rejected'
              AND {$where}
            ORDER BY la.applied_at DESC, la.id DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Build the WHERE clause that determines which applications an
     * approver can see, based on their role and organisational scope.
     */
    private function buildApproverWhereClause(string $role, int $employeeId, array $currentUser, string $category): string
    {
        $auth = Auth::getInstance();

        if ($auth->isSuperAdmin() || $auth->isHRManager()) {
            return '1=1';
        }

        if ($role === 'managing_director') {
            return '1=1';
        }

        if ($role === 'dept_head') {
            return "e.department_id = " . ((int) ($currentUser['department_id'] ?? 0));
        }

        if ($role === 'section_head') {
            return "e.section_id = " . ((int) ($currentUser['section_id'] ?? 0));
        }

        if ($role === 'sub_section_head') {
            $subId = (int) ($currentUser['subsection_id'] ?? 0);
            if ($subId > 0) {
                return "e.subsection_id = {$subId}";
            }
            return "e.section_id = " . ((int) ($currentUser['section_id'] ?? 0));
        }

        // Default: only see own applications
        return "la.employee_id = {$employeeId}";
    }

    /**
     * Attach approver label + name to each pending row so the Stage column
     * shows the correct approver instead of "Approver / Not Assigned".
     */
    private function attachPendingApprovers(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $stageMap = [
            'pending_subsection_head'   => ['label' => 'Subsection Head',   'col' => 'subsection_head_emp_id'],
            'pending_section_head'      => ['label' => 'Section Head',      'col' => 'section_head_emp_id'],
            'pending_dept_head'         => ['label' => 'Department Head',   'col' => 'dept_head_emp_id'],
            'pending_managing_director' => ['label' => 'Managing Director', 'col' => 'md_emp_id'],
            'pending_manager'           => ['label' => 'Manager',           'col' => 'manager_emp_id'],
        ];

        // Collect approver employee ids to resolve with one batched query.
        $empIds = [];
        foreach ($rows as $row) {
            $status = $row['status'] ?? '';
            $col = $stageMap[$status]['col'] ?? 'dept_head_emp_id';
            $id = (int) ($row[$col] ?? 0);
            if ($id > 0) {
                $empIds[$id] = true;
            }
        }

        $names = [];
        if ($empIds) {
            $list = implode(',', array_map(fn($v) => (int)$v, array_keys($empIds)));
            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $names[(int)$x['id']] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
            }
            $stmt->close();
        }

        foreach ($rows as &$row) {
            $status = $row['status'] ?? '';
            $def = $stageMap[$status] ?? null;
            if ($def) {
                $row['pending_approver_label'] = $def['label'];
                $id = (int) ($row[$def['col']] ?? 0);
                $row['pending_approver_name'] = ($id > 0 && !empty($names[$id]))
                    ? $names[$id]
                    : 'Not Assigned';
            } else {
                $row['pending_approver_label'] = 'Approver';
                $row['pending_approver_name']  = 'Not Assigned';
            }
        }
        unset($row);

        return $rows;
    }

/**
     * Resolve the actual deciding approver's name + decision date for an
     * approved or rejected application and attach `approver_name` / `action_date`
     * to each row.
     *
     * Why this exists: the live leave_applications schema has NO single
     * approved_by / approved_at column, and leave_history only records the
     * "applied"/"approved"/"auto-approved" events (never a "rejected" action),
     * so neither source alone identifies the decider for most rows.  The real
     * decider is therefore the highest stage that populated its per-stage
     * *_approved_by column, e.g.:
     *     managing_director_approved_by > hr_approved_by > dept_head_approved_by
     *     > section_head_approved_by > subsection_head_approved_by > manager_emp_id
     *
     * The *_approved_by columns hold a MIXED id space -- some store a users.id
     * (e.g. 355 = "DAVID KIMANI"), others an employees.id (e.g. 473 = "JAMES
     * MAINA", with no matching user) -- so each id is resolved against users
     * FIRST, then employees.
     */
    private function resolveDeciders(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        // Decision-chain priority (latest / final stage wins).  First column that
        // resolves to a known person is the decider; its date column supplies the
        // action date.  approved_by/approved_at is written by the current Phase 5+
        // workflow on BOTH approve() and reject(), so it outranks the legacy per-stage
        // columns below.
        $priority = [
            ['id' => 'approved_by',                     'date' => 'approved_at'],
            ['id' => 'managing_director_approved_by', 'date' => 'managing_director_approved_at'],
            ['id' => 'hr_approved_by',               'date' => 'hr_approved_at'],
            ['id' => 'dept_head_approved_by',        'date' => 'dept_head_approved_at'],
            ['id' => 'section_head_approved_by',     'date' => 'section_head_approved_at'],
            ['id' => 'subsection_head_approved_by',  'date' => 'subsection_head_approved_at'],
            ['id' => 'manager_emp_id',               'date' => 'manager_emp_id'],
        ];

        // 1. Collect every candidate id across all rows.
        $candidateIds = [];
        foreach ($rows as $row) {
            foreach ($priority as $col) {
                $id = (int) ($row[$col['id']] ?? 0);
                if ($id > 0) {
                    $candidateIds[$id] = true;
                }
            }
        }

        // 2. Batch-resolve names.  users.id and employees.id overlap, so query
        //    users first and only fall back to employees for ids that are not
        //    users.  This correctly maps 355 -> "DAVID KIMANI" (a user) while
        //    473 -> "JAMES MAINA" (employee only).
        $names = [];
        if ($candidateIds) {
            $list = implode(',', array_map(fn($v) => (int)$v, array_keys($candidateIds)));

            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $names[(int)$x['id']] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
            }
            $stmt->close();

            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $id = (int)$x['id'];
                if (!isset($names[$id])) {
                    $names[$id] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
                }
            }
            $stmt->close();
        }

        // 3. Resolve "acting for" delegator names (leave_history rows written
        //    by a delegate carry acted_for_user_id) so the UI shows e.g.
        //    "Grace Wanjiru (acting for Samuel Mwangi)" — the delegate is the
        //    truthful ACTING approver; the delegator stays the recorded owner.
        $actingForIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['acted_for_user_id'] ?? 0);
            if ($id > 0) {
                $actingForIds[$id] = true;
            }
        }
        $actingForNames = [];
        if ($actingForIds) {
            $list = implode(',', array_map(fn($v) => (int)$v, array_keys($actingForIds)));
            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $actingForNames[(int)$x['id']] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
            }
            $stmt->close();
        }

        // 4. For each row, pick the highest-priority populated stage.
        foreach ($rows as &$row) {
            $row['approver_name'] = 'System';
            $row['action_date']   = null;

            foreach ($priority as $col) {
                $id = (int) ($row[$col['id']] ?? 0);
                if ($id > 0 && !empty($names[$id])) {
                    $row['approver_name'] = $names[$id];
                    $dateCol = $col['date'];
                    // manager_emp_id has no dedicated date column; fall back to
                    // applied_at so the cell is never unexpectedly empty.
                    if ($dateCol === 'manager_emp_id') {
                        $row['action_date'] = $row['applied_at'] ?? null;
                    } else {
                        $row['action_date'] = $row[$dateCol] ?? null;
                    }
                    break;
                }
            }

            $actingFor = (int) ($row['acted_for_user_id'] ?? 0);
            if ($actingFor > 0 && isset($actingForNames[$actingFor]) && $actingForNames[$actingFor] !== '') {
                $row['approver_name'] .= ' (acting for ' . $actingForNames[$actingFor] . ')';
            }
        }
        unset($row);

        return $rows;
    }


    /**
     * Count leave applications for an approver in a given category.
     * Mirrors the SELECT queries' joins/where so the count always matches
     * the visible rows, and is independent of the pagination LIMIT.
     */
    private function countForApprover(string $role, int $employeeId, array $currentUser, string $category, ?int $userId = null): int
    {
        $pendingStatuses = "'pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_hr','pending_hr_manager','pending_bod_chair','pending_manager'";

        switch ($category) {
            case 'approved':
                $statusCondition = "la.status = 'approved'";
                break;
            case 'rejected':
                $statusCondition = "la.status = 'rejected'";
                break;
            case 'pending':
            default:
                $statusCondition = "la.status IN ({$pendingStatuses})";
                break;
        }

        // Same filter as the SELECT queries so the count always matches the
        // visible rows (delegated queues included). The pending category uses
        // the stage-aware AUTHORITY fragment — NOT the scope-only clause —
        // otherwise the badge would count rows the tab no longer shows.
        if ($category === 'pending') {
            $where = $this->buildPendingAuthorityWhere(
                (int) ($userId ?? 0),
                $role,
                $employeeId,
                $currentUser
            );
        } else {
            $where = $this->wrapWithDelegatedScopes(
                $this->buildApproverWhereClause($role, $employeeId, $currentUser, $category),
                (int) ($userId ?? 0)
            );
        }

        $sql = "
            SELECT COUNT(*) AS total
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE {$statusCondition}
              AND {$where}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        return $total;
    }

    /**
     * Get the annual leave type ID.
     */
    private function getAnnualLeaveTypeId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 1;
    }
}

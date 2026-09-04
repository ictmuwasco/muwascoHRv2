<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Controllers\BaseController;
use App\Helpers\Auth;
use App\Services\DelegationService;

/**
 * Delegation Controller — REST API for the Temporary Delegation / Acting
 * Authority module.
 *
 * Thin HTTP layer only: every action resolves the authenticated user and
 * delegates the business rules (eligibility, lifecycle, scope, audit,
 * notifications) to DelegationService. Route-level permission gates
 * (delegations:view/create/approve/cancel) are enforced by
 * AuthorizationMiddleware BEFORE the controller runs (api.php route table).
 *
 * Endpoints:
 *   GET  /delegations                        — list (scoped per role)
 *   POST /delegations                        — create (pending, HR-approved)
 *   GET  /delegations/eligible-delegates     — dropdown source for the creator
 *   GET  /delegations/delegatable-permissions— authority options for the creator
 *   PUT  /delegations/{id}/approve           — HR approval step
 *   PUT  /delegations/{id}/reject            — HR rejection step
 *   PUT  /delegations/{id}/cancel            — delegator / HR cancellation
 *
 * Place: backend/app/Controllers/Leave/DelegationController.php
 */
class DelegationController extends BaseController
{
    private DelegationService $delegationService;

    public function __construct()
    {
        $this->delegationService = DelegationService::getInstance();
    }

    /**
     * GET /api/delegations
     * List delegations. HR / managing_director / super_admin see all;
     * everyone else sees only delegations where they are the delegator or
     * the delegate.
     */
    public function indexAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $this->success(['delegations' => $this->delegationService->listFor($userId)]);
    }

    /**
     * POST /api/delegations
     * Create a delegation request (status = pending, requires HR approval).
     */
    public function storeAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $data = $this->getJsonBody();
        $result = $this->delegationService->create($userId, is_array($data) ? $data : []);

        if (!$result['success']) {
            $this->error($result['message'], 422);
            return;
        }
        $this->success($result['data'] ?? null, $result['message'], 201);
    }

    /**
     * GET /api/delegations/eligible-delegates
     * Active users the authenticated delegator may appoint (their scope).
     */
    public function eligibleDelegatesAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $this->success(['delegates' => $this->delegationService->eligibleDelegates($userId)]);
    }

    /**
     * GET /api/delegations/delegatable-permissions
     * The authority the authenticated delegator's role MAY delegate
     * (grouped by module + flat "module:action" list).
     */
    public function delegatablePermissionsAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $role = (string) ($_SESSION['user_role'] ?? '');
        $this->success(['permissions' => $this->delegationService->delegatablePermissions($role)]);
    }

    /**
     * PUT /api/delegations/{id}/approve
     * HR approval step: pending → approved (activation is date-driven).
     */
    public function approveAction(int $id): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $result = $this->delegationService->approve($userId, $id);
        if (!$result['success']) {
            $this->error($result['message'], 422);
            return;
        }
        $this->success($result['data'] ?? null, $result['message']);
    }

    /**
     * PUT /api/delegations/{id}/reject
     * HR rejection step: pending → rejected (terminal).
     */
    public function rejectAction(int $id): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $data = $this->getJsonBody();
        $reason = is_array($data) ? (string) ($data['reason'] ?? '') : '';

        $result = $this->delegationService->reject($userId, $id, $reason);
        if (!$result['success']) {
            $this->error($result['message'], 422);
            return;
        }
        $this->success($result['data'] ?? null, $result['message']);
    }

    /**
     * PUT /api/delegations/{id}/cancel
     * Cancel a pending/approved/active delegation (delegator or HR).
     */
    public function cancelAction(int $id): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            $this->error('Authentication required', 401);
            return;
        }

        $data = $this->getJsonBody();
        $reason = is_array($data) ? (string) ($data['reason'] ?? '') : '';

        $result = $this->delegationService->cancel($userId, $id, $reason);
        if (!$result['success']) {
            $this->error($result['message'], 422);
            return;
        }
        $this->success($result['data'] ?? null, $result['message']);
    }
}

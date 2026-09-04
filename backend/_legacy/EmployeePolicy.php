<?php

declare(strict_types=1);

namespace App\Policies;

use App\Helpers\Session;
use App\Helpers\Auth;
use App\Helpers\AuthorizationService;

/**
 * Employee Policy
 *
 * Thin convenience wrapper over the single authorization engine.
 * IMPORTANT: checks are keyed by the authenticated USER ID; the user's role
 * is derived inside AuthorizationService from trusted context (never passed
 * in). Actions map to catalog permissions (backend/config/permissions.php).
 */
class EmployeePolicy
{
    private AuthorizationService $authService;

    public function __construct()
    {
        $this->authService = AuthorizationService::getInstance();
    }

    /**
     * Check if the user can view employees.
     */
    public function view(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'employees', 'view');
    }

    /**
     * Check if the user can create employees.
     */
    public function create(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'employees', 'create');
    }

    /**
     * Check if the user can update employees.
     */
    public function update(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'employees', 'edit');
    }

    /**
     * Check if the user can delete employees.
     */
    public function delete(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'employees', 'delete');
    }

    /**
     * Check if the user can view (employee) reports.
     */
    public function viewReports(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'reports', 'view');
    }

    /**
     * Check if the user can export reports.
     */
    public function export(): bool
    {
        return $this->authService->hasPermission(Auth::getInstance()->id(), 'reports', 'export');
    }
}
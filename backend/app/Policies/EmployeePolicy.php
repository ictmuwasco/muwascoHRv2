<?php

declare(strict_types=1);

namespace App\Policies;

use App\Helpers\Session;
use App\Helpers\AuthorizationService;

/**
 * Employee Policy
 *
 * Defines authorization rules for employee operations.
 */
class EmployeePolicy
{
    private AuthorizationService $authService;

    public function __construct()
    {
        $this->authService = new AuthorizationService();
    }

    /**
     * Check if the user can view employees.
     */
    public function view(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'view');
    }

    /**
     * Check if the user can create employees.
     */
    public function create(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'create');
    }

    /**
     * Check if the user can update employees.
     */
    public function update(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'edit');
    }

    /**
     * Check if the user can delete employees.
     */
    public function delete(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'delete');
    }

    /**
     * Check if the user can view employee reports.
     */
    public function viewReports(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'reports');
    }

    /**
     * Check if the user can export employees.
     */
    public function export(?string $userRole = null): bool
    {
        $userRole = $userRole ?? Session::getInstance()->get('user_role');
        return $this->authService->hasPermission($userRole, 'employees', 'export');
    }
}
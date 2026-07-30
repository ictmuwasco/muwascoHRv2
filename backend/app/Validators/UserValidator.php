<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * User Validator
 *
 * Validates user data according to business rules.
 */
class UserValidator extends BaseValidator
{
    private UserRepositoryInterface $userRepository;
    private EmployeeRepositoryInterface $employeeRepository;
    private ?int $excludeId = null;

    public function __construct(
        UserRepositoryInterface $userRepository,
        EmployeeRepositoryInterface $employeeRepository
    ) {
        $this->userRepository = $userRepository;
        $this->employeeRepository = $employeeRepository;
    }

    /**
     * Set the ID to exclude from uniqueness checks (for updates).
     */
    public function setExcludeId(int $id): void
    {
        $this->excludeId = $id;
    }

    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        // Email is required, must be valid, and must be unique
        $this->validateRequired('email', 'Email');
        $this->validateEmail('email', 'Email');
        if (!empty($this->data['email'])) {
            if ($this->userRepository->emailExists($this->data['email'], $this->excludeId)) {
                $this->addError('email', 'Email already exists.');
            }
        }

        // First name is required
        $this->validateRequired('first_name', 'First name');
        $this->validateMaxLength('first_name', 100, 'First name');

        // Last name is required
        $this->validateRequired('last_name', 'Last name');
        $this->validateMaxLength('last_name', 100, 'Last name');

        // Role is required
        $this->validateRequired('role', 'Role');
        $this->validateIn('role', ['admin', 'hr', 'manager', 'employee'], 'Role');

        // Password validation
        if (!empty($this->data['password'])) {
            $this->validateMinLength('password', 8, 'Password');
            $this->validateMaxLength('password', 255, 'Password');
        } elseif (!$this->excludeId) {
            // Password is required for new users
            $this->addError('password', 'Password is required.');
        }

        // Employee ID validation (optional)
        if (!empty($this->data['employee_id'])) {
            $employee = $this->employeeRepository->findById((int)$this->data['employee_id']);
            if (!$employee) {
                $this->addError('employee_id', 'Invalid employee selected.');
            }
        }

        // Phone validation (optional)
        if (!empty($this->data['phone'])) {
            $this->validatePhone('phone', 'Phone');
            $this->validateMaxLength('phone', 20, 'Phone');
        }

        // Status validation (optional)
        if (isset($this->data['is_active'])) {
            $this->validateIn('is_active', [0, 1], 'Status');
        }
    }
}
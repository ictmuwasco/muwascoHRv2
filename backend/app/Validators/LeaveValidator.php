<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\LeaveRepositoryInterface;

/**
 * Leave Validator
 *
 * Validates leave application data according to business rules.
 */
class LeaveValidator extends BaseValidator
{
    private EmployeeRepositoryInterface $employeeRepository;
    private LeaveRepositoryInterface $leaveRepository;

    public function __construct(
        EmployeeRepositoryInterface $employeeRepository,
        LeaveRepositoryInterface $leaveRepository
    ) {
        $this->employeeRepository = $employeeRepository;
        $this->leaveRepository = $leaveRepository;
    }

    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        // Employee ID is required
        $this->validateRequired('employee_id', 'Employee');
        $this->validateInteger('employee_id', 'Employee');

        if (!empty($this->data['employee_id'])) {
            $employee = $this->employeeRepository->findById((int)$this->data['employee_id']);
            if (!$employee) {
                $this->addError('employee_id', 'Invalid employee selected.');
            }
        }

        // Leave type ID is required
        $this->validateRequired('leave_type_id', 'Leave type');
        $this->validateInteger('leave_type_id', 'Leave type');

        // Start date is required and must be valid
        $this->validateRequired('start_date', 'Start date');
        $this->validateDate('start_date', 'Start date');

        // End date is required and must be valid
        $this->validateRequired('end_date', 'End date');
        $this->validateDate('end_date', 'End date');

        // Start date must be before or equal to end date
        if (!empty($this->data['start_date']) && !empty($this->data['end_date'])) {
            if ($this->data['start_date'] > $this->data['end_date']) {
                $this->addError('end_date', 'End date must be after or equal to start date.');
            }
        }

        // Reason is required and must be at least 10 characters
        $this->validateRequired('reason', 'Reason');
        if (!empty($this->data['reason'])) {
            $this->validateMinLength('reason', 10, 'Reason');
            $this->validateMaxLength('reason', 500, 'Reason');
        }

        // Check for leave conflicts if dates are provided
        if (!empty($this->data['employee_id']) && !empty($this->data['start_date']) && !empty($this->data['end_date'])) {
            if ($this->leaveRepository->hasConflict((int)$this->data['employee_id'], $this->data['start_date'], $this->data['end_date'])) {
                $this->addError('dates', 'Leave dates conflict with existing leave application.');
            }
        }
    }
}
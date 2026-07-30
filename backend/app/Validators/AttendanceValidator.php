<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\Contracts\EmployeeRepositoryInterface;

/**
 * Attendance Validator
 *
 * Validates attendance data according to business rules.
 */
class AttendanceValidator extends BaseValidator
{
    private EmployeeRepositoryInterface $employeeRepository;

    public function __construct(EmployeeRepositoryInterface $employeeRepository)
    {
        $this->employeeRepository = $employeeRepository;
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

        // Date is required and must be valid
        $this->validateRequired('date', 'Date');
        $this->validateDate('date', 'Date');

        // Clock in time validation (optional)
        if (!empty($this->data['clock_in_time'])) {
            $this->validateTime('clock_in_time', 'Clock in time');
        }

        // Clock out time validation (optional)
        if (!empty($this->data['clock_out_time'])) {
            $this->validateTime('clock_out_time', 'Clock out time');
        }

        // Status validation (optional)
        if (!empty($this->data['status'])) {
            $this->validateIn('status', ['present', 'absent', 'late', 'half_day', 'on_leave'], 'Status');
        }

        // Notes validation (optional)
        if (!empty($this->data['notes'])) {
            $this->validateMaxLength('notes', 500, 'Notes');
        }
    }

    /**
     * Validate that a field is a valid time (HH:MM:SS or HH:MM).
     */
    private function validateTime(string $field, string $label): void
    {
        if (!empty($this->data[$field])) {
            $time = $this->data[$field];
            if (!preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])(:([0-5][0-9]))?$/', $time)) {
                $this->addError($field, "{$label} must be a valid time (HH:MM or HH:MM:SS).");
            }
        }
    }
}
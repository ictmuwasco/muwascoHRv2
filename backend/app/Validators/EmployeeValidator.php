<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;

/**
 * Employee Validator
 *
 * Validates employee data according to business rules.
 */
class EmployeeValidator extends BaseValidator
{
    private EmployeeRepositoryInterface $employeeRepository;
    private DepartmentRepositoryInterface $departmentRepository;
    private SectionRepositoryInterface $sectionRepository;
    private OfficeRepositoryInterface $officeRepository;
    private ?int $excludeId = null;

    public function __construct(
        EmployeeRepositoryInterface $employeeRepository,
        DepartmentRepositoryInterface $departmentRepository,
        SectionRepositoryInterface $sectionRepository,
        OfficeRepositoryInterface $officeRepository
    ) {
        $this->employeeRepository = $employeeRepository;
        $this->departmentRepository = $departmentRepository;
        $this->sectionRepository = $sectionRepository;
        $this->officeRepository = $officeRepository;
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
        // Employee ID is required and must be unique
        $this->validateRequired('employee_id', 'Employee ID');
        if (!empty($this->data['employee_id'])) {
            if ($this->employeeRepository->employeeIdExists($this->data['employee_id'], $this->excludeId)) {
                $this->addError('employee_id', 'Employee ID already exists.');
            }
        }

        // Email is required, must be valid, and must be unique
        $this->validateRequired('email', 'Email');
        $this->validateEmail('email', 'Email');
        if (!empty($this->data['email'])) {
            if ($this->employeeRepository->emailExists($this->data['email'], $this->excludeId)) {
                $this->addError('email', 'Email already exists.');
            }
        }

        // National ID is required and must be unique
        $this->validateRequired('national_id', 'National ID');
        if (!empty($this->data['national_id'])) {
            if ($this->employeeRepository->nationalIdExists($this->data['national_id'], $this->excludeId)) {
                $this->addError('national_id', 'National ID already exists.');
            }
        }

        // First name is required
        $this->validateRequired('first_name', 'First name');
        $this->validateMaxLength('first_name', 100, 'First name');

        // Last name is required
        $this->validateRequired('last_name', 'Last name');
        $this->validateMaxLength('last_name', 100, 'Last name');

        // Employee type is required
        $this->validateRequired('employee_type', 'Employee type');
        $this->validateIn('employee_type', ['permanent', 'contract', 'intern', 'consultant'], 'Employee type');

        // Employee status is required
        $this->validateRequired('employee_status', 'Employee status');
        $this->validateIn('employee_status', ['active', 'inactive', 'on_leave', 'terminated'], 'Employee status');

        // Employment date is required and must be a valid date
        $this->validateRequired('employment_date', 'Employment date');
        $this->validateDate('employment_date', 'Employment date');

        // Phone validation (optional)
        if (!empty($this->data['phone'])) {
            $this->validatePhone('phone', 'Phone');
            $this->validateMaxLength('phone', 20, 'Phone');
        }

        // Department validation (optional)
        if (!empty($this->data['department_id'])) {
            $this->validateInteger('department_id', 'Department');
            if (!$this->departmentRepository->findById((int)$this->data['department_id'])) {
                $this->addError('department_id', 'Invalid department selected.');
            }
        }

        // Section validation (optional)
        if (!empty($this->data['section_id'])) {
            $this->validateInteger('section_id', 'Section');
            if (!$this->sectionRepository->findById((int)$this->data['section_id'])) {
                $this->addError('section_id', 'Invalid section selected.');
            }
        }

        // Office validation (optional)
        if (!empty($this->data['office_id'])) {
            $this->validateInteger('office_id', 'Office');
            if (!$this->officeRepository->findById((int)$this->data['office_id'])) {
                $this->addError('office_id', 'Invalid office selected.');
            }
        }

        // Gender validation (optional)
        if (!empty($this->data['gender'])) {
            $this->validateIn('gender', ['male', 'female', 'other'], 'Gender');
        }

        // Marital status validation (optional)
        if (!empty($this->data['marital_status'])) {
            $this->validateIn('marital_status', ['single', 'married', 'divorced', 'widowed'], 'Marital status');
        }
    }
}
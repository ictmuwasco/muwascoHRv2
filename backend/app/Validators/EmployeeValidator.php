<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Employee Validator
 *
 * HTTP request validation: input shape and format only.
 *
 * Business rules (employee_id / email / national_id uniqueness, department,
 * section and office existence, contract date ranges) are owned by
 * EmployeeService and are intentionally NOT duplicated here.
 */
class EmployeeValidator extends BaseValidator
{
    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        $this->validateRequired('employee_id', 'Employee ID');
        $this->validateRequired('email', 'Email');
        $this->validateEmail('email', 'Email');
        $this->validateMaxLength('email', 255, 'Email');
        $this->validateRequired('national_id', 'National ID');
        $this->validateRequired('first_name', 'First name');
        $this->validateMaxLength('first_name', 100, 'First name');
        $this->validateRequired('last_name', 'Last name');
        $this->validateMaxLength('last_name', 100, 'Last name');
        $this->validateRequired('employee_type', 'Employee type');
        $this->validateRequired('employee_status', 'Employee status');
        $this->validateRequired('hire_date', 'Hire date');
        $this->validateDate('hire_date', 'Hire date');

        if (!empty($this->data['phone'])) {
            $this->validateMaxLength('phone', 20, 'Phone');
        }
    }
}
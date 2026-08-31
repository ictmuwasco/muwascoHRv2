<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Leave Validator
 *
 * HTTP request validation: input shape and format only.
 *
 * Business rules (leave balance and eligibility, overlapping applications,
 * delegation and supporting-document requirements) are owned by
 * LeaveApplicationService and are intentionally NOT duplicated here.
 */
class LeaveValidator extends BaseValidator
{
    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        $this->validateRequired('employee_id', 'Employee');
        $this->validateInteger('employee_id', 'Employee');

        $this->validateRequired('leave_type_id', 'Leave type');
        $this->validateInteger('leave_type_id', 'Leave type');

        $this->validateRequired('start_date', 'Start date');
        $this->validateDate('start_date', 'Start date');

        $this->validateRequired('end_date', 'End date');
        $this->validateDate('end_date', 'End date');

        if (!empty($this->data['start_date']) && !empty($this->data['end_date'])) {
            if ($this->data['start_date'] > $this->data['end_date']) {
                $this->addError('end_date', 'End date must be after or equal to start date.');
            }
        }

        if (!empty($this->data['reason'])) {
            $this->validateMaxLength('reason', 500, 'Reason');
        }
    }
}
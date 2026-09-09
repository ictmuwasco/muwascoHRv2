<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Department Validator
 *
 * HTTP request validation: input shape and format only.
 *
 * Business rules (department-name uniqueness, organisational hierarchy
 * integrity) are owned by DepartmentService and are intentionally NOT
 * duplicated here.
 */
class DepartmentValidator extends BaseValidator
{
    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        $this->validateRequired('name', 'Department name');
        $this->validateMaxLength('name', 150, 'Department name');

        if (!empty($this->data['description'])) {
            $this->validateMaxLength('description', 500, 'Description');
        }
    }
}
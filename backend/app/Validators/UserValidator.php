<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * User Validator
 *
 * HTTP request validation: input shape and format only.
 *
 * Business rules (email uniqueness, employee binding, role catalogue and
 * privilege-escalation guards) are owned by UserService and are intentionally
 * NOT duplicated here.
 */
class UserValidator extends BaseValidator
{
    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        $this->validateRequired('email', 'Email');
        $this->validateEmail('email', 'Email');
        $this->validateMaxLength('email', 255, 'Email');

        $this->validateRequired('first_name', 'First name');
        $this->validateMaxLength('first_name', 100, 'First name');

        $this->validateRequired('last_name', 'Last name');
        $this->validateMaxLength('last_name', 100, 'Last name');

        $this->validateRequired('role', 'Role');

        if (!empty($this->data['password'])) {
            $this->validateMinLength('password', 8, 'Password');
            $this->validateMaxLength('password', 255, 'Password');
        }

        if (!empty($this->data['phone'])) {
            $this->validateMaxLength('phone', 20, 'Phone');
        }
    }
}
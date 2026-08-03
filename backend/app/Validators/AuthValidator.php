<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * Auth Validator
 *
 * Validates authentication data according to business rules.
 */
class AuthValidator extends BaseValidator
{
    /**
     * Perform the actual validation logic.
     */
    protected function performValidation(array $data): void
    {
        // Email is required and must be valid
        $this->validateRequired('email', 'Email');
        $this->validateEmail('email', 'Email');
        $this->validateMaxLength('email', 255, 'Email');

        // Password is required
        $this->validateRequired('password', 'Password');
        $this->validateMinLength('password', 8, 'Password');
        $this->validateMaxLength('password', 255, 'Password');

        // Remember me validation (optional)
        if (isset($this->data['remember_me'])) {
            $this->validateIn('remember_me', [0, 1, '0', '1', true, false], 'Remember me');
        }
    }
}
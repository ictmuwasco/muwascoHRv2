<?php

declare(strict_types=1);

namespace App\Validators;

use App\Validators\Contracts\ValidatorInterface;

/**
 * Base Validator
 *
 * Provides common validation functionality for all validators.
 */
abstract class BaseValidator implements ValidatorInterface
{
    protected array $errors = [];
    protected array $data = [];

    /**
     * Validate the given data.
     *
     * @param array $data
     * @return array<string, string>
     */
    public function validate(array $data): array
    {
        $this->data = $data;
        $this->errors = [];

        $this->performValidation($data);

        return $this->errors;
    }

    /**
     * Perform the actual validation logic.
     *
     * @param array $data
     * @return void
     */
    abstract protected function performValidation(array $data): void;

    /**
     * Check if the validation passes.
     *
     * @param array $data
     * @return bool
     */
    public function passes(array $data): bool
    {
        $this->validate($data);
        return empty($this->errors);
    }

    /**
     * Check if the validation fails.
     *
     * @param array $data
     * @return bool
     */
    public function fails(array $data): bool
    {
        return !$this->passes($data);
    }

    /**
     * Get all validation errors.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first error message for a given field.
     *
     * @param string $field
     * @return string|null
     */
    public function firstError(string $field): ?string
    {
        return $this->errors[$field] ?? null;
    }

    /**
     * Add an error message for a field.
     *
     * @param string $field
     * @param string $message
     * @return void
     */
    protected function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    /**
     * Validate that a field is required.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateRequired(string $field, string $label): void
    {
        if (empty($this->data[$field])) {
            $this->addError($field, "{$label} is required.");
        }
    }

    /**
     * Validate that a field is a valid email.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateEmail(string $field, string $label): void
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "{$label} must be a valid email address.");
        }
    }

    /**
     * Validate that a field has a minimum length.
     *
     * @param string $field
     * @param int $min
     * @param string $label
     * @return void
     */
    protected function validateMinLength(string $field, int $min, string $label): void
    {
        if (!empty($this->data[$field]) && strlen($this->data[$field]) < $min) {
            $this->addError($field, "{$label} must be at least {$min} characters.");
        }
    }

    /**
     * Validate that a field has a maximum length.
     *
     * @param string $field
     * @param int $max
     * @param string $label
     * @return void
     */
    protected function validateMaxLength(string $field, int $max, string $label): void
    {
        if (!empty($this->data[$field]) && strlen($this->data[$field]) > $max) {
            $this->addError($field, "{$label} must not exceed {$max} characters.");
        }
    }

    /**
     * Validate that a field is a valid date.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateDate(string $field, string $label): void
    {
        if (!empty($this->data[$field])) {
            $date = \DateTime::createFromFormat('Y-m-d', $this->data[$field]);
            if (!$date || $date->format('Y-m-d') !== $this->data[$field]) {
                $this->addError($field, "{$label} must be a valid date (YYYY-MM-DD).");
            }
        }
    }

    /**
     * Validate that a field is a valid phone number.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validatePhone(string $field, string $label): void
    {
        if (!empty($this->data[$field])) {
            $phone = preg_replace('/[^0-9+]/', '', $this->data[$field]);
            if (strlen($phone) < 10 || strlen($phone) > 15) {
                $this->addError($field, "{$label} must be a valid phone number.");
            }
        }
    }

    /**
     * Validate that a field is a valid URL.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateUrl(string $field, string $label): void
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_URL)) {
            $this->addError($field, "{$label} must be a valid URL.");
        }
    }

    /**
     * Validate that a field is a valid integer.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateInteger(string $field, string $label): void
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_INT)) {
            $this->addError($field, "{$label} must be a valid integer.");
        }
    }

    /**
     * Validate that a field is a valid numeric value.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateNumeric(string $field, string $label): void
    {
        if (!empty($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->addError($field, "{$label} must be a valid number.");
        }
    }

    /**
     * Validate that a field is in a given list of values.
     *
     * @param string $field
     * @param array $allowedValues
     * @param string $label
     * @return void
     */
    protected function validateIn(string $field, array $allowedValues, string $label): void
    {
        if (!empty($this->data[$field]) && !in_array($this->data[$field], $allowedValues, true)) {
            $this->addError($field, "{$label} is invalid.");
        }
    }

    /**
     * Validate that a field is a valid JSON string.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateJson(string $field, string $label): void
    {
        if (!empty($this->data[$field])) {
            json_decode($this->data[$field]);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addError($field, "{$label} must be valid JSON.");
            }
        }
    }

    /**
     * Validate that a field is a valid file upload.
     *
     * @param string $field
     * @param string $label
     * @return void
     */
    protected function validateFile(string $field, string $label): void
    {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                $this->addError($field, "{$label} upload failed.");
            }
        }
    }

    /**
     * Validate that a field is a valid file type.
     *
     * @param string $field
     * @param array $allowedTypes
     * @param string $label
     * @return void
     */
    protected function validateFileType(string $field, array $allowedTypes, string $label): void
    {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $fileType = mime_content_type($_FILES[$field]['tmp_name']);
            if (!in_array($fileType, $allowedTypes, true)) {
                $this->addError($field, "{$label} must be one of: " . implode(', ', $allowedTypes));
            }
        }
    }

    /**
     * Validate that a field is a valid file size.
     *
     * @param string $field
     * @param int $maxSizeInBytes
     * @param string $label
     * @return void
     */
    protected function validateFileSize(string $field, int $maxSizeInBytes, string $label): void
    {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            if ($_FILES[$field]['size'] > $maxSizeInBytes) {
                $maxSizeInMB = $maxSizeInBytes / (1024 * 1024);
                $this->addError($field, "{$label} must not exceed {$maxSizeInMB} MB.");
            }
        }
    }
}
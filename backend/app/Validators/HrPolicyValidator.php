<?php

declare(strict_types=1);

namespace App\Validators;

/**
 * HrPolicy Validator — HTTP request validation: input shape and format only.
 *
 * Business rules (status transitions, version uniqueness, immutable published
 * versions, single active policy) are owned by PolicyService and are
 * intentionally NOT duplicated here.
 */
class HrPolicyValidator extends BaseValidator
{
    /**
     * Validate policy metadata (upload + metadata-edit payloads).
     */
    protected function performValidation(array $data): void
    {
        // Title: required on upload; optional-but-validated on edit.
        if (array_key_exists('title', $data)) {
            $this->validateMaxLength('title', 200, 'Title');
        }

        if (array_key_exists('version', $data) && !empty($data['version'])) {
            $this->validateMaxLength('version', 30, 'Version');
        }

        if (array_key_exists('description', $data) && !empty($data['description'])) {
            $this->validateMaxLength('description', 2000, 'Description');
        }

        if (!empty($data['source_type'])) {
            $this->validateIn('source_type', [
                'manual', 'cba', 'circular', 'law', 'procedure', 'handbook',
            ], 'Source type');
        }

        if (!empty($data['effective_date'])) {
            if (!is_string($data['effective_date'])
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['effective_date'])
                || strtotime($data['effective_date']) === false) {
                $this->addError('effective_date', 'Effective date must be a valid YYYY-MM-DD date.');
            }
        }

        if (array_key_exists('acknowledgement_message', $data) && !empty($data['acknowledgement_message'])) {
            $this->validateMaxLength('acknowledgement_message', 1000, 'Acknowledgement message');
        }

        if (!empty($data['status'])) {
            $this->validateIn('status', ['draft', 'review'], 'Status');
        }
    }
}

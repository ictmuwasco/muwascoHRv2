<?php

declare(strict_types=1);

namespace App\Validators;

use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;

/**
 * Department Validator
 *
 * Validates department data according to business rules.
 */
class DepartmentValidator extends BaseValidator
{
    private DepartmentRepositoryInterface $departmentRepository;
    private SectionRepositoryInterface $sectionRepository;
    private ?int $excludeId = null;

    public function __construct(
        DepartmentRepositoryInterface $departmentRepository,
        SectionRepositoryInterface $sectionRepository
    ) {
        $this->departmentRepository = $departmentRepository;
        $this->sectionRepository = $sectionRepository;
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
        // Department name is required and must be unique
        $this->validateRequired('name', 'Department name');
        $this->validateMaxLength('name', 150, 'Department name');
        if (!empty($this->data['name'])) {
            if ($this->departmentRepository->nameExists($this->data['name'], $this->excludeId)) {
                $this->addError('name', 'Department name already exists.');
            }
        }

        // Description validation (optional)
        if (!empty($this->data['description'])) {
            $this->validateMaxLength('description', 500, 'Description');
        }

        // Status validation (optional)
        if (!empty($this->data['status'])) {
            $this->validateIn('status', ['active', 'inactive'], 'Status');
        }
    }
}
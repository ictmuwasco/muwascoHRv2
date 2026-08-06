<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\DepartmentServiceInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;

/**
 * Department Service
 *
 * Contains business logic for department management.
 * Orchestrates repository operations and enforces business rules.
 */
class DepartmentService implements DepartmentServiceInterface
{
    private ?DepartmentRepositoryInterface $departmentRepository = null;
    private ?SectionRepositoryInterface $sectionRepository = null;
    private array $dependencies = [];

    public function setDepartmentRepository(DepartmentRepositoryInterface $repository): void
    {
        $this->departmentRepository = $repository;
    }

    public function setSectionRepository(SectionRepositoryInterface $repository): void
    {
        $this->sectionRepository = $repository;
    }

    public function setDependency(string $name, mixed $dependency): void
    {
        $this->dependencies[$name] = $dependency;
    }

    public function getDependency(string $name): mixed
    {
        return $this->dependencies[$name] ?? null;
    }

    public function getAllDepartments(): array
    {
        return $this->departmentRepository->getAllActive();
    }

    public function getDepartmentById(int $id): ?array
    {
        return $this->departmentRepository->findWithSections($id);
    }

    public function createDepartment(array $data): int
    {
        // Business rule: Validate department data
        $errors = $this->validateDepartmentData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Remove status field if present (departments table doesn't have status column)
        unset($data['status']);

        return $this->departmentRepository->create($data);
    }

    public function updateDepartment(int $id, array $data): bool
    {
        // Business rule: Check if department exists
        $existingDepartment = $this->departmentRepository->findById($id);
        if (!$existingDepartment) {
            throw new \InvalidArgumentException('Department not found');
        }

        // Business rule: Validate department data
        $errors = $this->validateDepartmentData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Remove status field if present (departments table doesn't have status column)
        unset($data['status']);

        return $this->departmentRepository->update($id, $data);
    }

    public function deleteDepartment(int $id): bool
    {
        // Business rule: Check if department exists
        $department = $this->departmentRepository->findById($id);
        if (!$department) {
            throw new \InvalidArgumentException('Department not found');
        }

        // Business rule: Check if department has employees
        // This would be implemented with EmployeeRepository
        // For now, we'll just delete

        return $this->departmentRepository->delete($id);
    }

    public function getDepartmentHierarchy(): array
    {
        return $this->departmentRepository->getHierarchy();
    }

    public function getSections(int $departmentId): array
    {
        // Business rule: Validate department exists
        $department = $this->departmentRepository->findById($departmentId);
        if (!$department) {
            throw new \InvalidArgumentException('Department not found');
        }

        return $this->sectionRepository->getByDepartment($departmentId);
    }

    public function getSubsections(int $sectionId): array
    {
        // Business rule: Validate section exists
        $section = $this->sectionRepository->findById($sectionId);
        if (!$section) {
            throw new \InvalidArgumentException('Section not found');
        }

        return $this->sectionRepository->getSubsections($sectionId);
    }

    public function getAllSections(): array
    {
        return $this->sectionRepository->findAll();
    }

    public function getSectionById(int $id): ?array
    {
        return $this->sectionRepository->findWithSubsections($id);
    }

    public function createSection(array $data): int
    {
        // Business rule: Validate section data
        $errors = $this->validateSectionData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Remove status field if present (sections table doesn't have status column)
        unset($data['status']);

        return $this->sectionRepository->create($data);
    }

    public function updateSection(int $id, array $data): bool
    {
        // Business rule: Check if section exists
        $existingSection = $this->sectionRepository->findById($id);
        if (!$existingSection) {
            throw new \InvalidArgumentException('Section not found');
        }

        // Business rule: Validate section data
        $errors = $this->validateSectionData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Remove status field if present (sections table doesn't have status column)
        unset($data['status']);

        return $this->sectionRepository->update($id, $data);
    }

    public function deleteSection(int $id): bool
    {
        // Business rule: Check if section exists
        $section = $this->sectionRepository->findById($id);
        if (!$section) {
            throw new \InvalidArgumentException('Section not found');
        }

        return $this->sectionRepository->delete($id);
    }

    public function getAllSubsections(): array
    {
        $result = $this->sectionRepository->getAllSubsections();
        return $result;
    }

    public function getSubsectionById(int $id): ?array
    {
        return $this->sectionRepository->findSubsectionById($id);
    }

    public function createSubsection(array $data): int
    {
        // Business rule: Validate subsection data
        $errors = $this->validateSubsectionData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        // Remove status field if present (subsections table doesn't have status column)
        unset($data['status']);

        return $this->sectionRepository->createSubsection($data);
    }

    public function updateSubsection(int $id, array $data): bool
    {
        // Business rule: Check if subsection exists
        $existingSubsection = $this->sectionRepository->findSubsectionById($id);
        if (!$existingSubsection) {
            throw new \InvalidArgumentException('Subsection not found');
        }

        // Business rule: Validate subsection data
        $errors = $this->validateSubsectionData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Normalize name
        if (!empty($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $this->sectionRepository->updateSubsection($id, $data);
    }

    public function deleteSubsection(int $id): bool
    {
        // Business rule: Check if subsection exists
        $subsection = $this->sectionRepository->findSubsectionById($id);
        if (!$subsection) {
            throw new \InvalidArgumentException('Subsection not found');
        }

        return $this->sectionRepository->deleteSubsection($id);
    }

    public function validateDepartmentData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Department name is required
        if (empty($data['name'])) {
            $errors[] = 'Department name is required';
        } elseif ($this->departmentRepository->nameExists($data['name'], $excludeId)) {
            $errors[] = 'Department name already exists';
        }

        return $errors;
    }

    private function validateSectionData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Section name is required
        if (empty($data['name'])) {
            $errors[] = 'Section name is required';
        } elseif (!empty($data['department_id'])) {
            // Ensure department_id is an integer for the repository method
            $departmentId = is_int($data['department_id']) ? $data['department_id'] : (int)$data['department_id'];
            if ($this->sectionRepository->nameExists($data['name'], $departmentId, $excludeId)) {
                $errors[] = 'Section name already exists in this department';
            }
        }

        // Business rule: Department ID is optional (nullable in database)
        // Only validate if provided
        if (!empty($data['department_id']) && !is_numeric($data['department_id'])) {
            $errors[] = 'Department must be a valid number';
        }

        // Remove status field if present (sections table may not have status column)
        unset($data['status']);

        return $errors;
    }

    private function validateSubsectionData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Subsection name is required
        if (empty($data['name'])) {
            $errors[] = 'Subsection name is required';
        }

        // Business rule: Section ID is required
        if (empty($data['section_id'])) {
            $errors[] = 'Section is required';
        }

        // Remove status field if present (subsections table may not have status column)
        unset($data['status']);

        return $errors;
    }
}

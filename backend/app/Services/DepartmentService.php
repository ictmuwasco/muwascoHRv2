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

        // Business rule: Set default status if not provided
        if (empty($data['status'])) {
            $data['status'] = 'active';
        }

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

    public function validateDepartmentData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Department name is required
        if (empty($data['name'])) {
            $errors[] = 'Department name is required';
        } elseif ($this->departmentRepository->nameExists($data['name'], $excludeId)) {
            $errors[] = 'Department name already exists';
        }

        // Business rule: Status must be valid if provided
        if (!empty($data['status']) && !in_array($data['status'], ['active', 'inactive'])) {
            $errors[] = 'Invalid department status';
        }

        return $errors;
    }
}
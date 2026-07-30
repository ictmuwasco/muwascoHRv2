<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\EmployeeServiceInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;

/**
 * Employee Service
 *
 * Contains business logic for employee management.
 * Orchestrates repository operations and enforces business rules.
 */
class EmployeeService implements EmployeeServiceInterface
{
    private EmployeeRepositoryInterface $employeeRepository;
    private DepartmentRepositoryInterface $departmentRepository;
    private SectionRepositoryInterface $sectionRepository;
    private OfficeRepositoryInterface $officeRepository;
    private array $dependencies = [];

    public function setEmployeeRepository(EmployeeRepositoryInterface $repository): void
    {
        $this->employeeRepository = $repository;
    }

    public function setDepartmentRepository(DepartmentRepositoryInterface $repository): void
    {
        $this->departmentRepository = $repository;
    }

    public function setSectionRepository(SectionRepositoryInterface $repository): void
    {
        $this->sectionRepository = $repository;
    }

    public function setOfficeRepository(OfficeRepositoryInterface $repository): void
    {
        $this->officeRepository = $repository;
    }

    public function setDependency(string $name, mixed $dependency): void
    {
        $this->dependencies[$name] = $dependency;
    }

    public function getDependency(string $name): mixed
    {
        return $this->dependencies[$name] ?? null;
    }

    public function getAllEmployees(array $filters = [], int $page = 1, int $limit = 30): array
    {
        return $this->employeeRepository->search($filters, $page, $limit);
    }

    public function getEmployeeById(int $id): ?array
    {
        return $this->employeeRepository->findWithDetails($id);
    }

    public function createEmployee(array $data): int
    {
        // Business rule: Validate employee data
        $errors = $this->validateEmployeeData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Check if department exists
        if (!empty($data['department_id'])) {
            $department = $this->departmentRepository->findById((int)$data['department_id']);
            if (!$department) {
                throw new \InvalidArgumentException('Invalid department selected');
            }
        }

        // Business rule: Check if section exists
        if (!empty($data['section_id'])) {
            $section = $this->sectionRepository->findById((int)$data['section_id']);
            if (!$section) {
                throw new \InvalidArgumentException('Invalid section selected');
            }
        }

        // Business rule: Check if office exists
        if (!empty($data['office_id'])) {
            $office = $this->officeRepository->findById((int)$data['office_id']);
            if (!$office) {
                throw new \InvalidArgumentException('Invalid office selected');
            }
        }

        // Business rule: Normalize email
        if (!empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Business rule: Normalize phone numbers
        if (!empty($data['phone'])) {
            $data['phone'] = preg_replace('/[^0-9+]/', '', $data['phone']);
        }

        return $this->employeeRepository->create($data);
    }

    public function updateEmployee(int $id, array $data): bool
    {
        // Business rule: Check if employee exists
        $existingEmployee = $this->employeeRepository->findById($id);
        if (!$existingEmployee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Business rule: Validate employee data
        $errors = $this->validateEmployeeData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        // Business rule: Check if department exists
        if (!empty($data['department_id'])) {
            $department = $this->departmentRepository->findById((int)$data['department_id']);
            if (!$department) {
                throw new \InvalidArgumentException('Invalid department selected');
            }
        }

        // Business rule: Check if section exists
        if (!empty($data['section_id'])) {
            $section = $this->sectionRepository->findById((int)$data['section_id']);
            if (!$section) {
                throw new \InvalidArgumentException('Invalid section selected');
            }
        }

        // Business rule: Check if office exists
        if (!empty($data['office_id'])) {
            $office = $this->officeRepository->findById((int)$data['office_id']);
            if (!$office) {
                throw new \InvalidArgumentException('Invalid office selected');
            }
        }

        // Business rule: Normalize email
        if (!empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Business rule: Normalize phone numbers
        if (!empty($data['phone'])) {
            $data['phone'] = preg_replace('/[^0-9+]/', '', $data['phone']);
        }

        return $this->employeeRepository->update($id, $data);
    }

    public function deleteEmployee(int $id): bool
    {
        // Business rule: Check if employee exists
        $employee = $this->employeeRepository->findById($id);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Business rule: Check if employee has active attendance records
        // This would be implemented with AttendanceRepository
        // For now, we'll just delete

        return $this->employeeRepository->delete($id);
    }

    public function searchEmployees(string $query, array $filters = [], int $page = 1, int $limit = 30): array
    {
        $filters['search'] = $query;
        return $this->employeeRepository->search($filters, $page, $limit);
    }

    public function getOrganizationHierarchy(): array
    {
        return $this->employeeRepository->getOrganizationHierarchy();
    }

    public function getDepartments(): array
    {
        return $this->departmentRepository->getAllActive();
    }

    public function getSectionsByDepartment(int $departmentId): array
    {
        return $this->sectionRepository->getByDepartment($departmentId);
    }

    public function getSubsectionsBySection(int $sectionId): array
    {
        return $this->sectionRepository->getSubsections($sectionId);
    }

    public function getOffices(): array
    {
        return $this->officeRepository->getAllActive();
    }

    public function validateEmployeeData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        // Business rule: Employee ID is required
        if (empty($data['employee_id'])) {
            $errors[] = 'Employee ID is required';
        } elseif ($this->employeeRepository->employeeIdExists($data['employee_id'], $excludeId)) {
            $errors[] = 'Employee ID already exists';
        }

        // Business rule: Email is required and must be valid
        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        } elseif ($this->employeeRepository->emailExists($data['email'], $excludeId)) {
            $errors[] = 'Email already exists';
        }

        // Business rule: National ID is required
        if (empty($data['national_id'])) {
            $errors[] = 'National ID is required';
        } elseif ($this->employeeRepository->nationalIdExists($data['national_id'], $excludeId)) {
            $errors[] = 'National ID already exists';
        }

        // Business rule: First name is required
        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        // Business rule: Last name is required
        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        // Business rule: Employee type is required
        if (empty($data['employee_type'])) {
            $errors[] = 'Employee type is required';
        }

        // Business rule: Employee status is required
        if (empty($data['employee_status'])) {
            $errors[] = 'Employee status is required';
        }

        // Business rule: Employment date is required
        if (empty($data['employment_date'])) {
            $errors[] = 'Employment date is required';
        }

        return $errors;
    }
}
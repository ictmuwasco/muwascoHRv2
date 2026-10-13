<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Contracts\EmployeeServiceInterface;
use App\Services\PasswordResetService;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;
use App\Repositories\UserRepository;
use InvalidArgumentException;

/**
 * Employee Service
 *
 * Contains business logic for employee management.
 * Orchestrates repository operations and enforces business rules.
 */
class EmployeeService implements EmployeeServiceInterface
{
    private ?EmployeeRepositoryInterface $employeeRepository = null;
    private ?DepartmentRepositoryInterface $departmentRepository = null;
    private ?SectionRepositoryInterface $sectionRepository = null;
    private ?OfficeRepositoryInterface $officeRepository = null;
    private ?UserRepository $userRepository = null;
    private ?PasswordResetService $passwordResetService = null;

    private array $dependencies = [];

    public function __construct(
        EmployeeRepositoryInterface $employeeRepository = null,
        mixed $departmentRepository = null,
        mixed $sectionRepository = null,
        mixed $officeRepository = null
    ) {
        $this->employeeRepository = $employeeRepository;
        $this->departmentRepository = $departmentRepository instanceof DepartmentRepositoryInterface ? $departmentRepository : null;
        $this->sectionRepository = $sectionRepository instanceof SectionRepositoryInterface ? $sectionRepository : null;
        $this->officeRepository = $officeRepository instanceof OfficeRepositoryInterface ? $officeRepository : null;
    }

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

    public function setUserRepository(UserRepository $repository): void
    {
        $this->userRepository = $repository;
    }

    public function setDependency(string $name, mixed $dependency): void
    {
        $this->dependencies[$name] = $dependency;
    }

    public function getDependency(string $name): mixed
    {
        return $this->dependencies[$name] ?? null;
    }

    public function setPasswordResetService(PasswordResetService $service): void
    {
        $this->passwordResetService = $service;
    }

    // Alias methods for test compatibility
    public function getAll(array $filters = [], int $page = 1, int $limit = 30): array
    {
        return $this->getAllEmployees($filters, $page, $limit);
    }

    public function create(array $data): int
    {
        return $this->createEmployee($data);
    }

    public function update(int $id, array $data): bool
    {
        return $this->updateEmployee($id, $data);
    }

    /**
     * Fields a client may set on an employee record (Phase 7, P7-8
     * mass-assignment guard). The repository builds INSERT/UPDATE column
     * lists from array keys, so an unfiltered payload would let a caller
     * write arbitrary employees-table columns.
     *
     * Deliberately NOT client-writable:
     *   - salary            Ã¢â‚¬â€ sensitive HR data; owned by the payroll domain
     *   - profile_image_url Ã¢â‚¬â€ set exclusively by the profile-image endpoints
     *   - profile_token     Ã¢â‚¬â€ consent/onboarding server-side token
     *   - id / created_at / updated_at Ã¢â‚¬â€ server-owned
     *
     * next_of_kin / dependants ARE writable: array forms are persisted to
     * the child tables (saveNextOfKin/saveDependants) and JSON-string forms
     * to the employees text columns Ã¢â‚¬â€ both replaced into $data by the
     * service's own handling before this filter runs.
     */
    private const EMPLOYEE_WRITABLE_FIELDS = [
        'employee_id', 'first_name', 'last_name', 'surname', 'gender',
        'national_id', 'email', 'designation', 'phone', 'date_of_birth',
        'address', 'department_id', 'section_id', 'subsection_id', 'office_id',
        'position', 'hire_date', 'employment_type', 'employee_type',
        'employee_status', 'scale_id', 'contract_start_date', 'contract_end_date',
        'next_of_kin', 'dependants',
    ];

    /**
     * employees columns that ALSO exist on the linked login account (users).
     *
     * When an employee is updated these are mirrored onto the users row so the
     * two records cannot drift (an employee whose email changed but whose
     * account still logs in with the old one, names mismatching in the users
     * UI, etc). Deliberately an explicit allowlist: the users repository
     * builds SET clauses from array keys, and password / role / is_active /
     * session_token / login_identifier / last_activity are NEVER written from
     * an employee edit (role goes through the guarded path below, status
     * through PUT /users/{id}/toggle-status).
     */
    private const USER_SYNC_FIELDS = [
        'employee_id', 'email', 'first_name', 'last_name', 'surname',
        'gender', 'designation', 'phone', 'address',
    ];

    /**
     * Normalise a next_of_kin / dependants payload that may arrive as a
     * JSON string or as an already-decoded array.
     *
     * This is caller-supplied input on a write path and nothing upstream
     * validates it. The previous bare json_decode() returned null on
     * malformed JSON, the is_array() guard then fell through, and the
     * employee's next of kin was silently never saved - the caller received a
     * 200 and no error at all. decodeRequest() throws a JsonException on
     * malformed input so the controller can answer 400 rather than lose data
     * quietly.
     *
     * @return array<mixed>|null Null when the value is neither valid JSON nor
     *                          an array, which the caller treats as "absent".
     */
    private function decodeEmbeddedList(mixed $value, string $field): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = \App\Helpers\Json::decodeRequest($value, null, 'employee.' . $field);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Keep only client-writable fields, silently dropping anything else.
     */
    private function filterWritable(array $data, array $allowed): array
    {
        return array_intersect_key($data, array_flip($allowed));
    }

    public function delete(int $id): bool
    {
        return $this->deleteEmployee($id);
    }

    public function getAllEmployees(array $filters = [], int $page = 1, int $limit = 30): array
    {
        return $this->employeeRepository->search($filters, $page, $limit);
    }

    public function getEmployeeById(int $id): ?array
    {
        return $this->employeeRepository->findWithDetails($id);
    }

    public function getEmployeeByUserId(int $userId): ?array
    {
        return $this->employeeRepository->findByUserId($userId);
    }

    public function updateEmployeeProfile(int $id, array $data): bool
    {
        // Business rule: Check if employee exists
        $existingEmployee = $this->employeeRepository->findById($id);
        if (!$existingEmployee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Sanitize text fields to prevent XSS
        $textFields = ['first_name', 'last_name', 'surname', 'address', 'designation', 'position', 'national_id'];
        foreach ($textFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data[$field] = trim(strip_tags((string)$data[$field]));
            }
        }

        // Business rule: Normalize email if provided
        if (!empty($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        // Business rule: Normalize phone if provided
        if (!empty($data['phone'])) {
            $data['phone'] = preg_replace('/[^0-9+]/', '', $data['phone']);
        }

        // Handle next_of_kin - save to separate table
        if (isset($data['next_of_kin'])) {
            $nextOfKin = $this->decodeEmbeddedList($data['next_of_kin'], 'next_of_kin');
            if ($nextOfKin !== null) {
                // Convert single object to array
                if (isset($nextOfKin['name'])) {
                    $nextOfKin = [$nextOfKin];
                }
                $this->employeeRepository->saveNextOfKin($id, $nextOfKin);
                unset($data['next_of_kin']);
            }
        }
        
        // Handle dependants - save to separate table
        if (isset($data['dependants'])) {
            $dependants = $this->decodeEmbeddedList($data['dependants'], 'dependants');
            if ($dependants !== null) {
                $this->employeeRepository->saveDependants($id, $dependants);
                unset($data['dependants']);
            }
        }

        if (empty($data)) {
            return true;
        }

        // Phase 7 (P7-8): mass-assignment guard (see EMPLOYEE_WRITABLE_FIELDS).
        $data = $this->filterWritable($data, self::EMPLOYEE_WRITABLE_FIELDS);

        $result = $this->employeeRepository->update($id, $data);

        // Profile edits carry shared columns (phone, address, email...) too -
        // keep the linked login account in step. No employee_type here (the
        // self-service endpoint cannot change employment fields), so this only
        // mirrors columns; the guarded role path no-ops.
        if ($result) {
            $this->syncLinkedUserAccount($id, $existingEmployee, $data);
        }

        return $result;
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

        // Sanitize text fields to prevent XSS
        $textFields = ['first_name', 'last_name', 'surname', 'address', 'designation', 'position', 'national_id'];
        foreach ($textFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data[$field] = trim(strip_tags((string)$data[$field]));
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

        // Only pass contract dates to the repository if this is a contract-based
        // employee ('contract' or 'csuite') and the migration has been applied.
        // This avoids DB errors when the columns are missing.
        if (!$this->isContractBased($data['employment_type'] ?? '')) {
            unset($data['contract_start_date'], $data['contract_end_date']);
        }

        // Phase 7 (P7-8): mass-assignment guard (see EMPLOYEE_WRITABLE_FIELDS).
        $data = $this->filterWritable($data, self::EMPLOYEE_WRITABLE_FIELDS);

        // ONE transaction for every row a hire touches: employees, the initial
        // contract (if any) and the auto-created users account. Without it any
        // failure AFTER the employees INSERT left the row committed - the exact
        // "phantom 500" that hit POST /employees: the employee and user rows
        // survived, the response died, and every retry then bounced off
        // validateEmployeeData()'s duplicate checks with a 400. Now a
        // mid-flight failure rolls the whole hire back, so a retry re-creates
        // it instead of colliding with a half-written record.
        $db = \db();
        $db->beginTransaction();

        try {
            $employeeId = $this->employeeRepository->create($data);

            // Create initial contract record if this is a contract-based employee
            // ('csuite' contracts are renewable just like plain 'contract' ones).
            if ($this->isContractBased($data['employment_type'] ?? '')) {
                $this->createEmployeeContract($employeeId, $data);
            }

            // Auto-create the linked user account so the employee can log in
            // immediately using their customized email and employee number as password.
            $welcome = $this->createUserForEmployee($data, $employeeId);

            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable) {
                // A failed rollback must not mask the original failure.
            }
            throw $e;
        }

        // Welcome mail goes out only AFTER the commit (never inside the
        // transaction: no network I/O while locks are held, and no mail can
        // announce an account a rollback would delete) and is non-fatal.
        $this->sendWelcomeEmailForNewUser($welcome);

        return $employeeId;
    }

    public function updateEmployee(int $id, array $data): bool
    {
        // Business rule: Check if employee exists
        $existingEmployee = $this->employeeRepository->findById($id);
        if (!$existingEmployee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Check if this is a partial update (e.g., only next_of_kin or dependants)
        $partialUpdateFields = ['next_of_kin', 'dependants', 'documents'];
        $isPartialUpdate = !empty($data) && count(array_intersect(array_keys($data), $partialUpdateFields)) === count($data);

        // Business rule: Validate employee data (skip full validation for partial updates)
        if (!$isPartialUpdate) {
            $errors = $this->validateEmployeeData($data, $id);
            if (!empty($errors)) {
                throw new \InvalidArgumentException(implode(', ', $errors));
            }
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

        // Sanitize text fields to prevent XSS
        $textFields = ['first_name', 'last_name', 'surname', 'address', 'designation', 'position', 'national_id'];
        foreach ($textFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $data[$field] = trim(strip_tags((string)$data[$field]));
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

        // Only pass contract dates to the repository if this is a contract-based
        // employee. If the migration hasn't been run yet, omitting these avoids
        // "Unknown column" 500s.
        if (!$this->isContractBased($data['employment_type'] ?? '')) {
            unset($data['contract_start_date'], $data['contract_end_date']);
        }

        // If this employee is being set to a contract-based type, create an
        // initial contract record in the employee_contracts history table
        // (first contract or on transition to contract).
        if ($this->isContractBased($data['employment_type'] ?? '')
            && $this->employeeRepository->getEmployeeContractCount($id) === 0) {
            $this->createEmployeeContract($id, array_merge($data, [
                'employee_type' => $data['employee_type'] ?? $existingEmployee['employee_type'] ?? null,
                'designation' => $data['designation'] ?? $existingEmployee['designation'] ?? null,
                'department_id' => $data['department_id'] ?? $existingEmployee['department_id'] ?? null,
                'section_id' => $data['section_id'] ?? $existingEmployee['section_id'] ?? null,
            ]));
        }

        // Handle next_of_kin - save to separate table
        if (isset($data['next_of_kin'])) {
            $nextOfKin = $this->decodeEmbeddedList($data['next_of_kin'], 'next_of_kin');
            if ($nextOfKin !== null) {
                // Convert single object to array
                if (isset($nextOfKin['name'])) {
                    $nextOfKin = [$nextOfKin];
                }
                $this->employeeRepository->saveNextOfKin($id, $nextOfKin);
                unset($data['next_of_kin']);
            }
        }
        
        // Handle dependants - save to separate table
        if (isset($data['dependants'])) {
            $dependants = $this->decodeEmbeddedList($data['dependants'], 'dependants');
            if ($dependants !== null) {
                $this->employeeRepository->saveDependants($id, $dependants);
                unset($data['dependants']);
            }
        }

        if (empty($data)) {
            return true;
        }

        // Phase 7 (P7-8): mass-assignment guard (see EMPLOYEE_WRITABLE_FIELDS).
        $data = $this->filterWritable($data, self::EMPLOYEE_WRITABLE_FIELDS);

        $result = $this->employeeRepository->update($id, $data);

        // Mirror the edit onto the linked login account (shared columns +
        // role derived from employee_type). Runs AFTER the employee write so
        // the sync always reflects committed data; failures are logged, never
        // fatal - the employee edit itself already succeeded.
        if ($result) {
            $this->syncLinkedUserAccount($id, $existingEmployee, $data);
        }

        return $result;
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

    /**
     * Add a document for an employee.
     */
    public function addDocument(array $documentData): int
    {
        $query = "INSERT INTO employee_documents 
                  (employee_id, document_name, category, file_name, uploaded_at) 
                  VALUES (?, ?, ?, ?, ?)";
        
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare($query);
        $stmt->bind_param(
            'issss',
            $documentData['employee_id'],
            $documentData['document_name'],
            $documentData['category'],
            $documentData['file_name'],
            $documentData['uploaded_at']
        );
        $stmt->execute();
        $documentId = (int)$db->insert_id;
        $stmt->close();
        
        return $documentId;
    }

    /**
     * Get a document by ID.
     */
    public function getDocumentById(int $documentId): ?array
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM employee_documents WHERE id = ?");
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ?: null;
    }

    /**
     * Delete a document.
     */
    public function deleteDocument(int $documentId): bool
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM employee_documents WHERE id = ?");
        $stmt->bind_param('i', $documentId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
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
        } elseif ($this->employeeRepository->nationalIdExists((string)$data['national_id'], $excludeId)) {
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

        // Business rule: Hire date is required
        if (empty($data['hire_date'])) {
            $errors[] = 'Hire date is required';
        }

        // Business rule: date of birth, when provided, must be at least
        // MIN_EMPLOYEE_AGE (18) years ago - the form caps its date picker with
        // the same max date. Format shape alone is checked by
        // EmployeeValidator::validateDate(); this is the age rule itself.
        $dateOfBirth = trim((string)($data['date_of_birth'] ?? ''));
        if ($dateOfBirth !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth) || strtotime($dateOfBirth) === false) {
                $errors[] = 'Date of birth must be a valid date';
            } elseif (strtotime($dateOfBirth) > strtotime('-18 years')) {
                $errors[] = 'Employee must be at least 18 years old';
            }
        }

        // Business rule: Contract dates validation for contract-based employees
        // ('contract' and 'csuite' both carry contract terms)
        if ($this->isContractBased($data['employment_type'] ?? '')) {
            // Normalize: treat null/missing as empty string for validation
            $startDate = (string)($data['contract_start_date'] ?? '');
            $endDate = (string)($data['contract_end_date'] ?? '');

            // Only validate if at least one date field is being provided
            if ($startDate !== '' || $endDate !== '') {
                if ($startDate === '') {
                    $errors[] = 'Contract start date is required for contract employees';
                }
                if ($endDate === '') {
                    $errors[] = 'Contract end date is required for contract employees';
                }
                if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
                    $errors[] = 'Contract end date cannot be before start date';
                }
            }
        }

        return $errors;
    }

    /**
     * Create a linked user account for a newly created employee.
     *
     * Login credentials:
     *  - email    => employee's customized email
     *  - password => employee number (employee_id)
     *
     * Role is derived from employee_type so RBAC works out of the box.
     *
     * @return array{user_id:int, email:string, name:string}|null what the
     *         post-commit welcome mail needs, or null when no account was
     *         created (no repository / email already linked). The mail itself
     *         is deliberately NOT sent here - this runs inside createEmployee()'s
     *         transaction, and a welcome mail must never leave before the hire
     *         it announces is committed.
     */
    private function createUserForEmployee(array $data, int $employeeId): ?array
    {
        if (!$this->userRepository) {
            return null;
        }

        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        // Skip if a user account already exists for this email
        $existing = $this->userRepository->findByEmail($email);
        if ($existing) {
            return null;
        }

        $role = $this->mapEmployeeTypeToRole((string)($data['employee_type'] ?? 'officer'));

        $hash = \App\Helpers\Hash::getInstance();
        $passwordHash = $hash->make((string)($data['employee_id'] ?? ''));

        $userData = [
            'email'      => $email,
            'password'   => $passwordHash,
            'role'       => $role,
            'first_name' => (string)($data['first_name'] ?? ''),
            'last_name'  => (string)($data['last_name'] ?? ''),
            'designation'=> (string)($data['designation'] ?? ''),
            'is_active'  => 1,
            'employee_id'=> (string)($data['employee_id'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $userId = $this->userRepository->createUser($userData);

        return [
            'user_id' => $userId,
            'email'   => $email,
            'name'    => trim((string)($data['first_name'] ?? '')) ?: 'there',
        ];
    }

    /**
     * Fire the branded "welcome + set your password" mail for a just-created
     * account. Called by createEmployee() AFTER the transaction commits.
     *
     * Two guarantees, both learned from the incident this code replaces:
     *   - Post-commit: an SMTP failure cannot roll back a hire that is already
     *     durable, and no mail announces an account that never persisted.
     *   - Non-fatal: delivery problems are logged (via logger() - the instance
     *     accessor; Logger::error() is NOT static, and the old static call
     *     threw an Error straight out of its own catch block, which is what
     *     turned a soft failure into the silent 500 on POST /employees) and
     *     never re-thrown.
     *
     * Signature matters: PasswordResetService::sendWelcomeEmail(int $userId,
     * string $email, string $name) lives under declare(strict_types=1), so the
     * retired (email, name, employee_id, otp) call shape raised a TypeError on
     * every create.
     */
    private function sendWelcomeEmailForNewUser(?array $welcome): void
    {
        if ($welcome === null || $this->passwordResetService === null) {
            return;
        }

        try {
            $this->passwordResetService->sendWelcomeEmail(
                $welcome['user_id'],
                $welcome['email'],
                $welcome['name']
            );
        } catch (\Throwable $e) {
            try {
                \logger()->error('Welcome email failed for new employee ' . $welcome['email'], [
                    'email'      => $welcome['email'],
                    'user_id'    => $welcome['user_id'],
                    'error'      => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // This block sits AFTER the commit - a throw here would 500 a
                // hire that already persisted, which is the very incident this
                // code replaces (bootstrap's set_error_handler turns even a
                // failed mkdir() in the Logger constructor into an exception,
                // so \logger() is NOT guaranteed harmless). Swallow it: the
                // mail already failed for its own logged-or-not reason; losing
                // the log line must never cost the response.
            }
        }
    }

    /**
     * Map frontend employee_type values to backend user roles used by RBAC.
     *
     * Every target MUST be a canonical role key (roles table, migration 083 /
     * config/permissions.php): the legacy mapping wrote 'hr' (never a real
     * role) and escalated managing_director/bod_chairman to 'super_admin',
     * which bypassed the privilege-escalation guard in
     * UserService::assertAuthorizedRoleChange(). Each role now maps to its
     * own catalog identity — including officer/manager, which previously
     * fell through to 'employee' and silently downgraded the account's
     * permissions whenever this mapping was replayed (e.g. by the
     * employee->user sync below).
     */
    private function mapEmployeeTypeToRole(string $employeeType): string
    {
        return match ($employeeType) {
            'super_admin'      => 'super_admin',
            'managing_director' => 'managing_director',
            'bod_chairman'     => 'bod_chairman',
            'hr_manager'       => 'hr_manager',
            'dept_head'        => 'dept_head',
            'section_head'     => 'section_head',
            'sub_section_head' => 'sub_section_head',
            'manager'          => 'manager',
            'officer'          => 'officer',
            default            => 'employee',
        };
    }

    /**
     * Mirror an employee update onto its linked users row.
     *
     * WHY: users and employees share identity columns, and users.role — the
     * ONLY value RBAC resolves (AuthorizationService::resolveUserRole) — is
     * derived from employees.employee_type at CREATE time but was never
     * refreshed on UPDATE. An employee promoted/demoted in the Employees
     * interface therefore kept a stale (or empty, pre-sync era) role, and an
     * empty/unknown role denies EVERY permission — the SPA then renders
     * "Access denied … required: dashboard:view" on every page.
     *
     * WHAT SYNCs:
     *  - Shared identity columns from USER_SYNC_FIELDS (payload ∩ allowlist).
     *  - users.role when employee_type is part of the edit (guarded:
     *    super_admin/admin grants require a super_admin ACTOR — same policy
     *    as UserService::assertAuthorizedRoleChange, taken from session,
     *    never from the payload).
     *  - users.role is ALSO repaired when empty/invalid even if
     *    employee_type was not part of the edit (heals existing accounts).
     *
     * Non-fatal by design: the employee edit already committed, so a users
     * write failure is logged and swallowed rather than reported as a failed
     * employee update.
     *
     * @param int   $employeeId       Employees PK (for log context).
     * @param array $existingEmployee Pre-update row (fallbacks + type-change
     *                                detection).
     * @param array $data             Filtered, post-update employee payload.
     */
    private function syncLinkedUserAccount(int $employeeId, array $existingEmployee, array $data): void
    {
        if (!$this->userRepository) {
            return;
        }

        try {
            // users is the FK child (users.employee_id -> employees.employee_id).
            // Look up by the (possibly changed) business number first, then the
            // pre-update number, then email for legacy rows missing the link.
            $user = null;
            $employeeNumber = trim((string) ($data['employee_id'] ?? $existingEmployee['employee_id'] ?? ''));
            if ($employeeNumber !== '') {
                $user = $this->userRepository->findByEmployeeId($employeeNumber);
            }
            $oldNumber = trim((string) ($existingEmployee['employee_id'] ?? ''));
            if (!$user && $oldNumber !== '' && $oldNumber !== $employeeNumber) {
                $user = $this->userRepository->findByEmployeeId($oldNumber);
            }
            if (!$user && !empty($existingEmployee['email'])) {
                $user = $this->userRepository->findByEmail((string) $existingEmployee['email']);
            }
            if (!$user) {
                return; // No login account linked - nothing to sync.
            }

            $userUpdate = [];

            // 1. Shared identity columns present in this edit.
            foreach (self::USER_SYNC_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $userUpdate[$field] = $data[$field];
                }
            }

            // 2. Role: rewrite when employee_type was part of the edit, or
            //    when the stored role is empty/invalid (repair path - heals
            //    accounts stuck in the deny-everywhere state even when the
            //    admin only touched, say, the phone number).
            $currentRole = trim((string) ($user['role'] ?? ''));
            $typeChanged = array_key_exists('employee_type', $data)
                && (string) $data['employee_type'] !== (string) ($existingEmployee['employee_type'] ?? '');
            $roleBroken = $currentRole === '' || !$this->isKnownRole($currentRole);

            if ($typeChanged || $roleBroken) {
                $employeeType = (string) ($data['employee_type']
                    ?? $existingEmployee['employee_type']
                    ?? '');
                if ($employeeType !== '') {
                    $mappedRole = $this->mapEmployeeTypeToRole($employeeType);
                    if ($mappedRole !== $currentRole && $this->canAssignRole($mappedRole)) {
                        $userUpdate['role'] = $mappedRole;
                    }
                }
            }

            if ($userUpdate === []) {
                return;
            }

            $userUpdate['updated_at'] = date('Y-m-d H:i:s');
            $this->userRepository->update((int) $user['id'], $userUpdate);
        } catch (\Throwable $e) {
            \logger()->warning('Employee->user sync failed', [
                'employee_id' => $employeeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Is $role a real, assignable catalog role (config/permissions.php
     * "roles" list, plus the legacy 'admin' alias RBAC normalizes)? An
     * unknown role resolves to deny-by-default in AuthorizationService, so
     * anything not listed here counts as broken and is repair-eligible.
     */
    private function isKnownRole(string $role): bool
    {
        if ($role === 'admin') {
            return true; // legacy alias, normalized to super_admin by RBAC
        }

        $roles = (array) config('permissions.roles', []);

        return in_array($role, $roles, true);
    }

    /**
     * Privilege-escalation guard for the employee->user role sync (mirrors
     * UserService::assertAuthorizedRoleChange): only a super_admin/admin
     * ACTOR (session, never payload) may write super_admin/admin onto an
     * account. When denied the sync skips ONLY the role field, so the
     * legitimate shared-column sync still completes.
     */
    private function canAssignRole(string $role): bool
    {
        if ($role !== 'super_admin' && $role !== 'admin') {
            return true;
        }

        $actingRole = (string) ($_SESSION['user_role'] ?? '');
        $actingUserId = (int) ($_SESSION['user_id'] ?? 0);

        // CLI/worker fallback: re-resolve from the DB, never from a payload.
        if ($actingRole === '' && $actingUserId > 0) {
            try {
                $acting = db()->fetchOne('SELECT role FROM users WHERE id = ?', 'i', [$actingUserId]);
                $actingRole = (string) ($acting['role'] ?? '');
            } catch (\Throwable $e) {
                $actingRole = '';
            }
        }

        return $actingRole === 'admin' || $actingRole === 'super_admin';
    }

    /**
     * Get all contracts for an employee.
     */
    public function getEmployeeContracts(int $employeeId): array
    {
        return $this->employeeRepository->getEmployeeContracts($employeeId);
    }

    /**
     * Get total contract count for an employee.
     */
    public function getEmployeeContractCount(int $employeeId): int
    {
        return $this->employeeRepository->getEmployeeContractCount($employeeId);
    }

    /**
     * Employment types that are contract-based: they carry contract dates,
     * get a contract history record and can be renewed. 'csuite' (technical
     * manager, internal auditor, commercial manager, managing director, ...)
     * is contract-based and renewable, but accrues leave at the
     * permanent-employee rate Ã¢â‚¬â€ see FinancialYearService::getLeaveRules().
     */
    private function isContractBased(?string $employmentType): bool
    {
        return in_array($employmentType, ['contract', 'csuite'], true);
    }

    /**
     * Record the INITIAL contract for an employee (contract_number is
     * assigned automatically). Used when a hire is created as contract
     * employment or when an existing employee transitions to contract.
     */
    /**
     * Record the INITIAL contract for an employee (contract_number is
     * assigned automatically). Used when a hire is created as contract
     * employment or when an existing employee transitions to contract.
     */
    public function createEmployeeContract(int $employeeId, array $data): int
    {
        $nextNumber = $this->employeeRepository->getNextContractNumber($employeeId);

        $newContractId = $this->employeeRepository->createContract([
            'employee_id' => $employeeId,
            'contract_number' => $nextNumber,
            'contract_name' => $data['contract_name'] ?? 'Initial Contract',
            'start_date' => $data['contract_start_date'] ?? date('Y-m-d'),
            'end_date' => $data['contract_end_date'] ?? null,
            'employment_type' => $data['employment_type'] ?? 'contract',
            'employee_type' => $data['employee_type'] ?? null,
            'designation' => $data['designation'] ?? null,
            'department_id' => isset($data['department_id']) ? (int)$data['department_id'] : null,
            'section_id' => isset($data['section_id']) ? (int)$data['section_id'] : null,
        ]);

        $this->syncContractCounters($employeeId);

        return $newContractId;
    }

    /**
     * Keep employees.total_contracts in sync with the canonical
     * COUNT(*) on employee_contracts (tolerates missing column).
     */
    private function syncContractCounters(int $employeeId): void
    {
        $total = $this->employeeRepository->getEmployeeContractCount($employeeId);

        $stmt = \App\Helpers\Database::getInstance()->getConnection()->prepare(
            "UPDATE employees SET total_contracts = ? WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('ii', $total, $employeeId);
            $stmt->execute();
            $stmt->close();
        }
    }

                    
    /**
     * Convert a contract-based employee to permanent employment.
     *
     * Sets employment_type = 'permanent' and clears the active contract
     * date columns on the employee record. The contract history in
     * employee_contracts is preserved as-is (its rows become historical).
     * Unlike renewEmployeeContract(promote_to_permanent) this does NOT
     * create a new contract term Ã¢â‚¬â€ it is a plain type change.
     *
     * Throws InvalidArgumentException when the employee does not exist
     * or is not on a contract-based employment type ('contract'/'csuite').
     */
    public function convertEmployeeToPermanent(int $employeeId): array
    {
        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        $current = strtolower(trim((string)($employee['employment_type'] ?? '')));
        if (!in_array($current, ['contract', 'csuite'], true)) {
            throw new \InvalidArgumentException(
                'Only contract employees can be converted to permanent (current type: '
                . ($employee['employment_type'] ?: 'unknown') . ')'
            );
        }

        $db = \App\Helpers\Database::getInstance()->getConnection();

        $db->begin_transaction();

        try {
            $this->employeeRepository->update($employeeId, ['employment_type' => 'permanent']);

            // Clear the active contract dates (same defensive pattern as the
            // renewal promotion path Ã¢â‚¬â€ tolerates a missing repository method).
            if (method_exists($this->employeeRepository, 'clearContractDates')) {
                $this->employeeRepository->clearContractDates($employeeId);
            } else {
                // Fallback: set to NULL directly (safe column names).
                $db->query(
                    "UPDATE employees SET contract_start_date = NULL, contract_end_date = NULL WHERE id = " . (int)$employeeId
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            \logger()->error('Contract-to-permanent conversion failed', [
                'error' => $e->getMessage(),
                'employee_id' => $employeeId,
            ]);
            throw new \RuntimeException('Failed to convert the employee to permanent. Please try again.');
        }

        // Return the fresh record so the API response reflects the change.
        return $this->employeeRepository->findById($employeeId);
    }

    /**
     * Renew a contract for an employee (HR + self-service).
     * $options: start_date, end_date (Y-m-d) or duration_months (6/12);
     * promote_to_permanent (bool) converts the employee to permanent
     * employment (employment_type = 'permanent', contract dates cleared)
     * while preserving the contract history. Defaults preserve the legacy
     * today Ã¢â€ â€™ +1 year term. Enforces one-renewal-per-contract inside the
     * insert transaction.
     */
    public function renewEmployeeContract(int $employeeId, int $previousContractId, array $options = []): array
    {
        // Get the previous contract
        $previousContract = $this->employeeRepository->getContractById($previousContractId);
        if (!$previousContract || (int)$previousContract['employee_id'] !== $employeeId) {
            throw new \InvalidArgumentException('Contract not found');
        }

        $employee = $this->employeeRepository->findById($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        // Resolve the requested new term dates.
        $startDate = $this->normaliseContractDate($options['start_date'] ?? null) ?? date('Y-m-d');
        $endDate = $this->normaliseContractDate($options['end_date'] ?? null);

        if ($endDate === null && isset($options['duration_months'])) {
            $months = (int)$options['duration_months'];
            if ($months <= 0) {
                throw new \InvalidArgumentException('The renewal period must be a positive number of months.');
            }
            $endDate = date('Y-m-d', strtotime($startDate . " +{$months} months"));
        }
        if ($endDate === null) {
            // Legacy default: extend by one year from the new start date.
            $endDate = date('Y-m-d', strtotime($startDate . ' +1 year'));
        }

        if ($endDate <= $startDate) {
            throw new \InvalidArgumentException('The contract end date must be after the start date.');
        }
        
        $db = \App\Helpers\Database::getInstance()->getConnection();

        $db->begin_transaction();

        try {
            // One-renewal-per-contract guard (inside the transaction so a
            // concurrent renewal of the same contract cannot slip through).
            $successorStmt = $db->prepare(
                'SELECT id, contract_number FROM employee_contracts WHERE renewed_from_contract_id = ? LIMIT 1'
            );
            $successorStmt->bind_param('i', $previousContractId);
            $successorStmt->execute();
            $successor = $successorStmt->get_result()->fetch_assoc();
            $successorStmt->close();

            if ($successor) {
                throw new \InvalidArgumentException(
                    'This contract has already been renewed (Contract #' . $successor['contract_number'] .
                    '). Only the latest contract can be renewed.'
                );
            }

            // Get next contract number
            $nextContractNumber = $this->employeeRepository->getNextContractNumber($employeeId);

            // Create new contract record
            $newContract = [
                'employee_id' => $employeeId,
                'contract_number' => $nextContractNumber,
                'contract_name' => $previousContract['contract_name'] ?? 'Contract #' . $nextContractNumber,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'employment_type' => $employee['employment_type'] ?? 'contract',
                'employee_type' => $employee['employee_type'] ?? 'officer',
                'designation' => $employee['designation'] ?? null,
                'department_id' => isset($employee['department_id']) ? (int)$employee['department_id'] : null,
                'section_id' => isset($employee['section_id']) ? (int)$employee['section_id'] : null,
                'renewed_from_contract_id' => $previousContractId,
                'renewal_date' => date('Y-m-d H:i:s'),
            ];

            $newContractId = $this->employeeRepository->createContract($newContract);

            // If the caller requested a promotion to permanent, convert the
            // employee's employment_type to 'permanent' and clear the contract
            // date columns on the employee record (contract history is kept
            // as-is in employee_contracts Ã¢â‚¬â€ those rows become historical).
            // Otherwise keep them in sync with the newly created active contract.
            $promoteToPermanent = !empty($options['promote_to_permanent']);
            if ($promoteToPermanent) {
                $empUpdate = ['employment_type' => 'permanent'];
                $this->employeeRepository->update($employeeId, $empUpdate);
                if (method_exists($this->employeeRepository, 'clearContractDates')) {
                    $this->employeeRepository->clearContractDates($employeeId);
                } else {
                    // Fallback: set to NULL directly (safe column names).
                    $db->query(
                        "UPDATE employees SET contract_start_date = NULL, contract_end_date = NULL WHERE id = " . (int)$employeeId
                    );
                }
            } else {
                // createContract() only inserts into employee_contracts; sync
                // the employees table with the new contract dates explicitly.
                $this->employeeRepository->updateEmployeeContractDates($employeeId, $startDate, $endDate);
            }

            // Keep employees.total_contracts in sync with the history table.
            $this->syncContractCounters($employeeId);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            // Business-rule errors keep their client-safe message (Ã¢â€ â€™ HTTP 400).
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            \logger()->error('Contract renewal failed', [
                'error' => $e->getMessage(),
                'employee_id' => $employeeId,
                'contract_id' => $previousContractId,
            ]);
            throw new \RuntimeException('Failed to renew the contract. Please try again.');
        }

        // Term length for the audit trail and the UI response.
        $s = new \DateTime($startDate);
        $e = new \DateTime($endDate);
        $diff = $s->diff($e);
        $durationMonths = $diff->y * 12 + $diff->m;

        // Log the renewal (after commit).
        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_EMPLOYEES,
            \App\Services\AuditService::ACTION_UPDATE,
            'Contract renewed',
            [
                'target_type' => 'Employee',
                'target_id' => $employeeId,
                'target_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
                'old_values' => ['contract_end_date' => $previousContract['end_date'] ?? null],
                'new_values' => [
                    'contract_number' => $nextContractNumber,
                    'start_date' => $startDate,
                    'contract_end_date' => $endDate,
                    'duration_months' => $durationMonths,
                ],
            ]
        );

        // Return the new contract (+ resolved term length).
        $newContract = $this->employeeRepository->getContractById($newContractId);
        $newContract['duration_months'] = $durationMonths;
        return $newContract;
    }

    /**
     * Accept a Y-m-d date from the renewal form. Returns null when absent;
     * throws a client-safe error when present but malformed.
     */
    private function normaliseContractDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim((string)$value);
        $dt = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Invalid contract date "' . $value . '" (expected YYYY-MM-DD).');
        }
        return $dt->format('Y-m-d');
    }
}

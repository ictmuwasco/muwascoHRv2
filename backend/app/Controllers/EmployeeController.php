<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\EmployeeServiceInterface;
use App\Services\EmployeeService;

/**
 * Employee Controller - REST API for employee management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to EmployeeService.
 */
class EmployeeController extends BaseController
{
    private EmployeeServiceInterface $employeeService;

    public function __construct()
    {
        // Dependency injection - services are injected via setter methods
        $this->employeeService = new EmployeeService();
        
        // Set repository dependencies
        $this->employeeService->setEmployeeRepository(new \App\Repositories\EmployeeRepository());
        $this->employeeService->setDepartmentRepository(new \App\Repositories\DepartmentRepository());
        $this->employeeService->setSectionRepository(new \App\Repositories\SectionRepository());
        $this->employeeService->setOfficeRepository(new \App\Repositories\OfficeRepository());
        $this->employeeService->setUserRepository(new \App\Repositories\UserRepository());
    }

    /**
     * GET /api/employees - List employees with pagination and filters.
     */
    public function indexAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $filters = $this->getFilters();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            
            $result = $this->employeeService->getAllEmployees($filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id} - Get a single employee.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            $this->success($employee);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve employee. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees - Create a new employee.
     */
    public function storeAction(): void
    {
        $this->requirePermission('employees', 'create');

        $data = $this->getJsonBody();

        try {
            $employeeId = $this->employeeService->createEmployee($data);
            $this->success(['id' => $employeeId], 'Employee created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create employee. Please try again.', 500);
        }
    }

    /**
     * PUT /api/employees/{id} - Update an existing employee.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->employeeService->updateEmployee($id, $data);
            $this->success($result, 'Employee updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update employee. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/employees/{id} - Delete an employee.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('employees', 'delete');

        try {
            $result = $this->employeeService->deleteEmployee($id);
            $this->success($result, 'Employee deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete employee. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/search - Search employees.
     */
    public function searchAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $query = $_GET['q'] ?? $_GET['query'] ?? '';
            $filters = $this->getFilters();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            
            unset($filters['q'], $filters['query']);
            
            $result = $this->employeeService->searchEmployees($query, $filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee search error', ['error' => $e->getMessage()]);
            $this->error('Failed to search employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/reference - Get reference data for forms.
     */
    public function referenceAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $departments = [];
            $sections = [];
            $subsections = [];
            $offices = [];
            $hierarchy = [];

            // Fetch each data source independently so one failure doesn't break all
            try {
                $departments = $this->employeeService->getDepartments() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference departments error', ['error' => $e->getMessage()]);
            }

            try {
                $offices = $this->employeeService->getOffices() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference offices error', ['error' => $e->getMessage()]);
            }

            try {
                $hierarchy = $this->employeeService->getOrganizationHierarchy() ?? [];
                $sections = $hierarchy['sections'] ?? [];
                $subsections = $hierarchy['subsections'] ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference hierarchy error', ['error' => $e->getMessage()]);
            }

            $data = [
                'departments' => $departments,
                'sections' => $sections,
                'subsections' => $subsections,
                'offices' => $offices,
                'hierarchy' => $hierarchy,
            ];
            $this->success($data);
        } catch (\Exception $e) {
            \logger()->error('Employee reference data error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load reference data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/employees/documents - Upload a document for an employee.
     */
    public function uploadDocumentAction(): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $documentName = $_POST['document_name'] ?? '';
            $category = $_POST['category'] ?? 'other';

            if ($employeeId <= 0) {
                $this->error('Employee ID is required', 400);
                return;
            }

            if (empty($documentName)) {
                $this->error('Document name is required', 400);
                return;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('Please select a valid file to upload', 400);
                return;
            }

            $uploadDir = __DIR__ . '/../../public/uploads/employee_documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['file']['name']);
            $destination = $uploadDir . $fileName;

            if (!move_uploaded_file($_FILES['file']['tmp_name'], $destination)) {
                $this->error('Failed to save uploaded file', 500);
                return;
            }

            // Insert into employee_documents table
            $db = \App\Helpers\Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO employee_documents (employee_id, document_name, category, file_name) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isss', $employeeId, $documentName, $category, $fileName);
            $stmt->execute();
            $docId = (int)$db->insert_id;
            $stmt->close();

            $this->success(['id' => $docId, 'file_name' => $fileName], 'Document uploaded successfully', 201);
        } catch (\Exception $e) {
            \logger()->error('Document upload error', ['error' => $e->getMessage()]);
            $this->error('Failed to upload document. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/employees/documents/{id} - Delete an employee document.
     */
    public function deleteDocumentAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $db = \App\Helpers\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT file_name FROM employee_documents WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $doc = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$doc) {
                $this->notFound('Document not found');
                return;
            }

            // Delete file from disk
            $filePath = __DIR__ . '/../../public/uploads/employee_documents/' . $doc['file_name'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete database record
            $stmt = $db->prepare("DELETE FROM employee_documents WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $this->success(['id' => $id], 'Document deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Document delete error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete document. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/documents - Upload a document for the current user's profile.
     */
    public function uploadProfileDocumentAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Handle file upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('No file uploaded or upload error', 400);
                return;
            }

            $documentName = $_POST['document_name'] ?? 'Untitled';
            $category = $_POST['category'] ?? 'other';
            $uploadedFile = $_FILES['file'];

            // Validate file
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if ($uploadedFile['size'] > $maxSize) {
                $this->error('File size exceeds 5MB limit', 400);
                return;
            }

            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../../../storage/uploads/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Generate unique filename
            $fileExtension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $fileName = 'doc_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = $uploadDir . $fileName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
                \logger()->error('Failed to move uploaded file', ['temp' => $uploadedFile['tmp_name'], 'dest' => $filePath]);
                $this->error('Failed to upload file', 500);
                return;
            }

            // Save document record to database
            $documentData = [
                'employee_id' => (int)$employee['id'],
                'document_name' => $documentName,
                'category' => $category,
                'file_name' => $fileName,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $documentId = $this->employeeService->addDocument($documentData);
            $this->success(['id' => $documentId, 'message' => 'Document uploaded successfully']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Document upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to upload document. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/profile/documents/{documentId} - Delete a document from the current user's profile.
     */
    public function deleteProfileDocumentAction(int $documentId): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Get document to verify ownership
            $document = $this->employeeService->getDocumentById($documentId);
            if (!$document) {
                $this->notFound('Document not found');
            }

            if ((int)$document['employee_id'] !== (int)$employee['id']) {
                $this->forbidden('You do not have permission to delete this document');
            }

            // Delete file from storage
            if (isset($document['file_path']) && file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }

            // Delete record from database
            $this->employeeService->deleteDocument($documentId);
            $this->success(null, 'Document deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Document delete error', ['error' => $e->getMessage()]);
            $this->error('Failed to delete document. Please try again.', 500);
        }
    }

    /**
     * GET /api/profile - Get the current user's profile.
     */
    public function profileAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Build profile data structure expected by the frontend
            $profile = [
                'personal' => [
                    'first_name' => $employee['first_name'] ?? '',
                    'last_name' => $employee['last_name'] ?? '',
                    'surname' => $employee['surname'] ?? '',
                    'email' => $employee['email'] ?? '',
                    'phone' => $employee['phone'] ?? '',
                    'national_id' => $employee['national_id'] ?? '',
                    'gender' => $employee['gender'] ?? '',
                    'marital_status' => $employee['marital_status'] ?? '',
                    'address' => $employee['address'] ?? '',
                ],
                'employment' => [
                    'department' => $employee['department_name'] ?? '',
                    'section' => $employee['section_name'] ?? '',
                    'office' => $employee['office_name'] ?? '',
                    'designation' => $employee['designation'] ?? '',
                    'employee_type' => $employee['employee_type'] ?? '',
                    'employee_status' => $employee['employee_status'] ?? '',
                    'employment_date' => $employee['hire_date'] ?? $employee['employment_date'] ?? '',
                ],
                'next_of_kin' => $employee['next_of_kin_data'] ?? null,
                'dependants' => $employee['dependants_data'] ?? [],
                'documents' => $employee['documents'] ?? [],
            ];

            $this->success($profile);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile retrieval error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load profile. Please try again.', 500);
        }
    }

    /**
     * PUT /api/profile - Update the current user's profile.
     */
    public function updateProfileAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $data = $this->getJsonBody();
            $updateData = [];

            // Handle personal info updates
            if (isset($data['personal']) && is_array($data['personal'])) {
                $personal = $data['personal'];
                $allowedPersonalFields = [
                    'first_name', 'last_name', 'surname', 'email', 'phone',
                    'national_id', 'gender', 'marital_status', 'address'
                ];
                foreach ($allowedPersonalFields as $field) {
                    if (isset($personal[$field])) {
                        $updateData[$field] = $personal[$field];
                    }
                }
            }

            // Handle employment info updates
            if (isset($data['employment']) && is_array($data['employment'])) {
                $employment = $data['employment'];
                $allowedEmploymentFields = [
                    'designation', 'employee_type', 'employee_status', 'employment_date'
                ];
                foreach ($allowedEmploymentFields as $field) {
                    if (isset($employment[$field])) {
                        $updateData[$field] = $employment[$field];
                    }
                }
            }

            // Handle next of kin updates - pass array directly to service
            if (isset($data['next_of_kin'])) {
                $updateData['next_of_kin'] = $data['next_of_kin'];
            }

            // Handle dependants updates - pass array directly to service
            if (isset($data['dependants'])) {
                $updateData['dependants'] = $data['dependants'];
            }

            if (empty($updateData)) {
                $this->error('No valid fields to update', 400);
            }

            // Update the employee record (partial update, no full validation)
            $result = $this->employeeService->updateEmployeeProfile((int)$employee['id'], $updateData);
            $this->success($result, 'Profile updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile update error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update profile. Please try again.', 500);
        }
    }
}

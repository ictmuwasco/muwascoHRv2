<?php

declare(strict_types=1);

namespace App\Controllers\Employee;

use App\Controllers\BaseController;

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

        $data = $this->validateRequest(new \App\Validators\EmployeeValidator());

        try {
            $employeeId = $this->employeeService->createEmployee($data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_CREATE,
                'Created employee record',
                ['target_type' => 'Employee', 'target_id' => $employeeId, 'target_name' => ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''), 'new_values' => $data]
            );
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

        $data = $this->validateRequest(new \App\Validators\EmployeeValidator());

        try {
            $oldEmployee = $this->employeeService->getEmployeeById($id);
            $result = $this->employeeService->updateEmployee($id, $data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                'Updated employee record',
                ['target_type' => 'Employee', 'target_id' => $id, 'target_name' => ($oldEmployee['first_name'] ?? '') . ' ' . ($oldEmployee['last_name'] ?? ''), 'old_values' => $oldEmployee, 'new_values' => $data]
            );
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
            $oldEmployee = $this->employeeService->getEmployeeById($id);
            $result = $this->employeeService->deleteEmployee($id);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted employee record',
                ['target_type' => 'Employee', 'target_id' => $id, 'target_name' => ($oldEmployee['first_name'] ?? '') . ' ' . ($oldEmployee['last_name'] ?? ''), 'old_values' => $oldEmployee]
            );
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
     *
     * REMOVED (Phase 7 security remediation, finding P7-1):
     * The previous implementation of this action accepted uploads with NO
     * extension allowlist, NO MIME verification and NO size limit, and stored
     * the raw file inside the web-executable directory
     * backend/public/uploads/employee_documents/ (mkdir 0777). Any future
     * route registration pointing at it would become remote code execution.
     *
     * The authorized upload paths are:
     *   - POST /api/profile/documents      → uploadProfileDocument()
     *     (extension allowlist + finfo MIME check + 5MB cap + random filename
     *      + private STORAGE_PATH storage + audit log)
     *   - POST /api/profile/profile-image  → uploadProfileImage()
     *
     * Legacy files in backend/public/uploads/ are no longer directly
     * web-accessible (denied by backend/public/uploads/.htaccess) and are
     * served exclusively through the authorized streaming endpoint
     * GET /api/profile/documents/{id}.
     */

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
            $allowedMimeTypes = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if ($uploadedFile['size'] > $maxSize) {
                $this->error('File size exceeds 5MB limit', 400);
                return;
            }

            if ($uploadedFile['size'] === 0) {
                $this->error('Uploaded file is empty', 400);
                return;
            }

            // Verify file extension
            $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                $this->error('Invalid file type. Allowed formats: ' . implode(', ', $allowedExtensions), 400);
                return;
            }

            // Verify actual MIME type using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mimeType, $allowedMimeTypes)) {
                $this->error('Invalid file format. Uploaded file MIME type is not allowed.', 400);
                return;
            }

            // Create private upload directory outside webroot
            $uploadDir = STORAGE_PATH . '/uploads/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0750, true);
            }

            // Generate cryptographically secure filename
            $safeExtension = $allowedMimeTypes[$mimeType];
            $fileName = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
            $filePath = $uploadDir . $fileName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
                \logger()->error('Failed to move uploaded file', ['temp' => $uploadedFile['tmp_name'], 'dest' => $filePath]);
                $this->error('Failed to upload file', 500);
                return;
            }

            // Save document record to database
            $documentData = [
                'employee_id' => (int)$employee['id'],
                'document_name' => htmlspecialchars($documentName, ENT_QUOTES, 'UTF-8'),
                'category' => htmlspecialchars($category, ENT_QUOTES, 'UTF-8'),
                'file_name' => $fileName,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $documentId = $this->employeeService->addDocument($documentData);

            // Audit: log document upload
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_CREATE,
                'Uploaded document: ' . $documentName,
                [
                    'target_type' => 'Document',
                    'target_id' => $documentId,
                    'target_name' => $documentName,
                    'metadata' => ['category' => $category, 'file_name' => $fileName],
                ]
            );

            $this->success(['id' => $documentId, 'message' => 'Document uploaded successfully'], 'Document uploaded successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Document upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to upload document. Please try again.', 500);
        }
    }

    /**
     * GET /api/profile/documents/{documentId} - Securely stream / view a profile document.
     */
    public function viewProfileDocumentAction(int $documentId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        $document = $this->employeeService->getDocumentById($documentId);
        if (!$document) {
            $this->notFound('Document not found');
            return;
        }

        $employee = $this->employeeService->getEmployeeByUserId($userId);
        $isOwner = $employee && ((int)$document['employee_id'] === (int)$employee['id']);
        $hasHrPerm = $this->hasPermission('employees', 'view');

        if (!$isOwner && !$hasHrPerm) {
            $this->forbidden('You do not have permission to access this document');
            return;
        }

        $filePath = STORAGE_PATH . '/uploads/documents/' . $document['file_name'];
        if (!file_exists($filePath)) {
            // Check legacy path fallback
            $legacyPath = __DIR__ . '/../../public/uploads/employee_documents/' . $document['file_name'];
            if (file_exists($legacyPath)) {
                $filePath = $legacyPath;
            } else {
                $this->notFound('File not found on server');
                return;
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        // Phase 7 (P7-7): sandbox CSP + nosniff + no-store; inline only for
        // PDF/images (business in-browser preview), attachment otherwise.
        \App\Middleware\SecurityMiddleware::applyStreamHeaders(
            $mimeType,
            $document['document_name'] ?? 'document'
        );
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit();
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

            // Audit: log document deletion
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted document: ' . ($document['document_name'] ?? 'Document #' . $documentId),
                [
                    'target_type' => 'Document',
                    'target_id' => $documentId,
                    'target_name' => $document['document_name'] ?? null,
                    'old_values' => $document,
                ]
            );

            $this->success(null, 'Document deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Document delete error', ['error' => $e->getMessage()]);
            $this->error('Failed to delete document. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/profile-image - Upload the current user's profile picture.
     */
    public function uploadProfileImageAction(): void
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

            $this->handleProfileImageUpload((int)$employee['id']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile image upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to upload profile picture. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees/{id}/profile-image - Upload a profile picture for a specific employee (HR).
     */
    public function uploadEmployeeProfileImageAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            $this->handleProfileImageUpload($id);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee profile image upload error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to upload profile picture. Please try again.', 500);
        }
    }

    /**
     * Handle the actual profile image file upload and database update.
     */
    private function handleProfileImageUpload(int $employeeId): void
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->error('Please select a valid image file to upload', 400);
            return;
        }

        $uploadedFile = $_FILES['file'];

        // Validate file type - only images allowed
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if ($uploadedFile['size'] > $maxSize) {
            $this->error('Image size exceeds 5MB limit', 400);
            return;
        }

        if ($uploadedFile['size'] === 0) {
            $this->error('Uploaded image is empty', 400);
            return;
        }

        // Verify file extension
        $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            $this->error('Invalid file type. Allowed formats: ' . implode(', ', $allowedExtensions), 400);
            return;
        }

        // Verify actual MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, $allowedMimeTypes)) {
            $this->error('Invalid image format. Uploaded file MIME type is not allowed.', 400);
            return;
        }

        // Create upload directory in public webroot so images are accessible via URL
        $uploadDir = __DIR__ . '/../../public/uploads/profile_images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Generate unique filename
        $safeExtension = $allowedMimeTypes[$mimeType];
        $fileName = 'profile_' . $employeeId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
            \logger()->error('Failed to move uploaded profile image', ['temp' => $uploadedFile['tmp_name'], 'dest' => $filePath]);
            $this->error('Failed to upload profile picture', 500);
            return;
        }

        // Delete old profile image if it exists
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT profile_image_url FROM employees WHERE id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old && !empty($old['profile_image_url'])) {
            $oldPath = __DIR__ . '/../../public/' . $old['profile_image_url'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Store relative path in database
        $relativePath = 'uploads/profile_images/' . $fileName;
        $stmt = $db->prepare("UPDATE employees SET profile_image_url = ? WHERE id = ?");
        $stmt->bind_param('si', $relativePath, $employeeId);
        $stmt->execute();
        $stmt->close();

        // Audit: log profile image upload
        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_EMPLOYEES,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated profile picture',
            [
                'target_type' => 'Employee',
                'target_id' => $employeeId,
                'target_name' => 'Employee #' . $employeeId,
                'new_values' => ['profile_image_url' => $relativePath],
            ]
        );

        $this->success(['profile_image_url' => $relativePath], 'Profile picture uploaded successfully');
    }

    /**
     * GET /api/profile/profile-image - Stream the current user's profile picture.
     */
    public function profileImageAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $this->streamProfileImage((int)$employee['id']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile image stream error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load profile picture. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id}/profile-image - Stream an employee's profile picture (HR).
     */
    public function employeeProfileImageAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            $this->streamProfileImage($id);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee profile image stream error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load profile picture. Please try again.', 500);
        }
    }

    /**
     * Stream a profile image file from public/uploads/profile_images/.
     */
    private function streamProfileImage(int $employeeId): void
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT profile_image_url FROM employees WHERE id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee || empty($employee['profile_image_url'])) {
            \App\Helpers\ApiResponse::error('Profile picture not found', 'NOT_FOUND', [], 404);
        }

        // Support both public-webroot path and storage-relative path
        $filePath = __DIR__ . '/../../public/' . $employee['profile_image_url'];
        if (!file_exists($filePath)) {
            $storagePath = STORAGE_PATH . '/' . $employee['profile_image_url'];
            if (file_exists($storagePath)) {
                $filePath = $storagePath;
            } else {
                \App\Helpers\ApiResponse::error('Profile picture file not found on server', 'NOT_FOUND', [], 404);
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        readfile($filePath);
        exit();
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
                'profile_image_url' => $employee['profile_image_url'] ?? null,
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
                    'phone', 'address', 'marital_status'
                ];
                foreach ($allowedPersonalFields as $field) {
                    if (isset($personal[$field])) {
                        $updateData[$field] = $personal[$field];
                    }
                }
            }

            // Note: Employment fields (designation, employee_type, employee_status, employment_date)
            // cannot be modified via self-service /profile endpoint. They require HR administrator privileges.

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
                return;
            }

            // Update the employee record (partial update)
            $result = $this->employeeService->updateEmployeeProfile((int)$employee['id'], $updateData);

            // Audit: log profile update
            $auditDescription = 'Updated profile';
            if (isset($updateData['next_of_kin'])) {
                $auditDescription = 'Updated next of kin information';
            } elseif (isset($updateData['dependants'])) {
                $auditDescription = 'Updated dependants';
            } elseif (!empty($updateData)) {
                $auditDescription = 'Updated personal contact information';
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                $auditDescription,
                [
                    'target_type' => 'Employee',
                    'target_id' => (int)$employee['id'],
                    'target_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
                    'new_values' => $updateData,
                ]
            );

            $this->success($result, 'Profile updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile update error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update profile. Please try again.', 500);
        }
    }
}



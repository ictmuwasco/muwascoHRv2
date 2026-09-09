<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Services\Leave\LeaveTypePolicy;

/**
 * LeaveDocumentService
 *
 * Handles supporting documents for leave applications.
 * Enforces document requirements for Sick/Study Leave.
 */
class LeaveDocumentService
{
    private \mysqli $db;
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/jpg',
        'image/png',
    ];
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
    private const UPLOAD_DIR = STORAGE_PATH . '/uploads/leave_documents';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        // Lazy-create upload directory; suppress errors so a failed mkdir
        // does not crash endpoints that do not use document uploads.
        if (!is_dir(self::UPLOAD_DIR)) {
            @mkdir(self::UPLOAD_DIR, 0700, true);
        }
    }

    /**
     * Check if a leave type requires a supporting document.
     */
    public function requiresDocument(int $leaveTypeId): bool
    {
        // Requirement owned by LeaveTypePolicy (single source of truth).
        return LeaveTypePolicy::requiresDocument($leaveTypeId);
    }

    /**
     * Get required document type label for a leave type.
     */
    public function getRequiredDocumentType(int $leaveTypeId): ?string
    {
        return LeaveTypePolicy::documentType($leaveTypeId);
    }

    /**
     * Validate uploaded file.
     */
    public function validateDocument(array $file): array
    {
        $errors = [];

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed.';
            return ['valid' => false, 'errors' => $errors];
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            $errors[] = 'File size exceeds 5MB limit.';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            $errors[] = 'Invalid file type. Allowed: PDF, JPG, PNG.';
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $errors[] = 'Invalid file extension.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mime_type' => $mimeType,
            'extension' => $extension,
        ];
    }

    /**
     * Store uploaded document securely.
     */
    public function storeDocument(int $leaveApplicationId, array $file, string $documentType, int $uploadedBy): array
    {
        $validation = $this->validateDocument($file);
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $storedFilename = bin2hex(random_bytes(16)) . '.' . $validation['extension'];
        $filePath = self::UPLOAD_DIR . '/' . $storedFilename;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'errors' => ['Failed to store file.']];
        }

        $stmt = $this->db->prepare("
            INSERT INTO leave_application_documents
                (leave_application_id, document_type, original_filename, stored_filename, file_path, mime_type, file_size, uploaded_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param(
            'isssssii',
            $leaveApplicationId,
            $documentType,
            $file['name'],
            $storedFilename,
            $filePath,
            $validation['mime_type'],
            $file['size'],
            $uploadedBy
        );
        $stmt->execute();

        return [
            'success' => true,
            'document_id' => (int) $stmt->insert_id,
        ];
    }

    /**
     * Get document metadata for an application.
     */
    public function getDocuments(int $leaveApplicationId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, document_type, original_filename, mime_type, file_size, created_at
            FROM leave_application_documents
            WHERE leave_application_id = ?
            ORDER BY created_at ASC
        ");
        $stmt->bind_param('i', $leaveApplicationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $documents = [];
        while ($row = $result->fetch_assoc()) {
            $documents[] = $row;
        }
        return $documents;
    }

    /**
     * Get a single document with permission check.
     */
    public function getDocument(int $documentId, int $requestingUserId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT lad.*, la.employee_id, la.status
            FROM leave_application_documents lad
            JOIN leave_applications la ON la.id = lad.leave_application_id
            WHERE lad.id = ?
        ");
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();

        if (!$document) {
            return null;
        }

        // Permission check: applicant or authorized approver/hr/admin
        $employeeId = (int) $document['employee_id'];
        $userRoleStmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $userRoleStmt->bind_param('i', $requestingUserId);
        $userRoleStmt->execute();
        $userRoleRow = $userRoleStmt->get_result()->fetch_assoc();
        $role = $userRoleRow['role'] ?? '';

        $allowedRoles = ['hr_manager', 'super_admin', 'managing_director', 'bod_chair'];
        if ($role === 'hr_manager' || $role === 'super_admin') {
            return $document;
        }

        // Check if user is the applicant
        $applicantStmt = $this->db->prepare("SELECT user_id FROM employees WHERE id = ?");
        $applicantStmt->bind_param('i', $employeeId);
        $applicantStmt->execute();
        $applicantRow = $applicantStmt->get_result()->fetch_assoc();
        if ($applicantRow && (int) $applicantRow['user_id'] === $requestingUserId) {
            return $document;
        }

        return null;
    }

    /**
     * Delete document.
     */
    public function deleteDocument(int $documentId, int $requestingUserId): bool
    {
        $document = $this->getDocument($documentId, $requestingUserId);
        if (!$document) {
            return false;
        }

        $filePath = $document['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $stmt = $this->db->prepare("DELETE FROM leave_application_documents WHERE id = ?");
        $stmt->bind_param('i', $documentId);
        return $stmt->execute();
    }
}
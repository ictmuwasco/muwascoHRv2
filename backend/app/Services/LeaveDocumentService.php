<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\StorageEncryption;
use App\Services\Leave\LeaveTypePolicy;
use App\Services\Security\DocumentAccessService;

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
     * May this user see the supporting documents of an application?
     *
     * Three legitimate paths, each reusing the SAME check the rest of the leave
     * module already trusts (no second, weaker authorization model):
     *
     *   1. Applicant       — it is their own data (users.employee_id → the
     *                        application's employee).
     *   2. Profile scope   — LeaveProfileService::canViewProfile(): HR / MD /
     *                        super admin see anyone, heads see their own unit,
     *                        officers their own record. This is the check that
     *                        guards GET /leave/profile/{id}, so anyone who may
     *                        read the profile may read its evidence too.
     *   3. Decision scope  — LeaveApprovalService::isAuthorisedApprover():
     *                        the exact gate that guards approve/reject, so a
     *                        supervisor (incl. delegated / duty-cover approvers
     *                        and the BOD chair at the final stage) can review
     *                        sick/study-leave attachments BEFORE deciding.
     *
     * NOTE: the scope checks evaluate the authenticated SESSION user, which is
     * exactly $requestingUserId — both controllers resolve it from Auth and
     * refuse unauthenticated calls first.
     */
    public function canViewDocuments(int $leaveApplicationId, int $requestingUserId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id, employee_id, status
            FROM leave_applications
            WHERE id = ?
        ");
        $stmt->bind_param('i', $leaveApplicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$app) {
            return false;
        }

        // 1. Applicant.
        $ownerStmt = $this->db->prepare("
            SELECT 1
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ? AND e.id = ?
            LIMIT 1
        ");
        $pUser = $requestingUserId;
        $pEmp  = (int) $app['employee_id'];
        $ownerStmt->bind_param('ii', $pUser, $pEmp);
        $ownerStmt->execute();
        $isApplicant = (bool) $ownerStmt->get_result()->fetch_assoc();
        $ownerStmt->close();

        if ($isApplicant) {
            return true;
        }

        // 2. Profile scope (heads their unit, HR/MD/super admin anyone).
        try {
            if ((new LeaveProfileService())->canViewProfile((int) $app['employee_id'])) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[LeaveDocumentService] profile scope check failed: ' . $e->getMessage());
        }

        // 3. Decision scope (the approver who may decide this application now).
        try {
            if ((new LeaveApprovalService())->isAuthorisedApproverForApplication($requestingUserId, $leaveApplicationId)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[LeaveDocumentService] approver scope check failed: ' . $e->getMessage());
        }

        return false;
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
        $stmt->close();

        if (!$document) {
            return null;
        }

        // Same gate as the list endpoint: applicant, profile scope or the
        // authorised approver. (The previous inline role check was broken —
        // $allowedRoles was built but never tested, so supervisors, MD and BOD
        // all got a 404 on applications they are allowed to decide.)
        if (!$this->canViewDocuments((int) $document['leave_application_id'], $requestingUserId)) {
            return null;
        }

        return $document;
    }

    /**
     * Resolve a document row to bytes that are safe to stream.
     *
     * Files in this table can be encrypted at rest — the storage-encryption
     * sweep converts leave documents too — but leave_application_documents has
     * no is_encrypted stamp column, so the on-disk magic (MWSC1) is the only
     * reliable signal. A container is decrypted to a temp file the caller MUST
     * unlink; a legacy plaintext file is returned as-is.
     *
     * @return array{path:string, mime:string, bytes:int, temporary:bool}
     * @throws \RuntimeException when the file is missing or encrypted but
     *         unrecoverable — the caller must never fall back to streaming the
     *         container, because a browser would render MWSC1 bytes as a PDF.
     */
    public function resolveReadableFile(array $document): array
    {
        $path = (string) ($document['file_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Leave document file is missing from storage.');
        }

        // The stored mime is the ORIGINAL type, captured at upload — finfo on
        // an encrypted container can only ever report MWSC1.
        $mime = (string) ($document['mime_type'] ?? '');

        if (!StorageEncryption::isEncryptedFile($path)) {
            return [
                'path'      => $path,
                'mime'      => $mime !== '' ? $mime : 'application/octet-stream',
                'bytes'     => (int) filesize($path),
                'temporary' => false,
            ];
        }

        $key = (new DocumentAccessService())->loadFileKey('leave_application_documents', (int) $document['id']);
        if ($key === null) {
            // Encrypted with no key row is unrecoverable. Report it rather than
            // streaming a container as if it were the document.
            throw new \RuntimeException(
                'This document is encrypted but its decryption key is missing, so it cannot be opened. Please contact IT.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'leave_doc');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file for decryption.');
        }

        try {
            StorageEncryption::decryptFile($path, $tmp, $key);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new \RuntimeException('The document could not be decrypted: ' . $e->getMessage(), 0, $e);
        }

        if ($mime === '') {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = @$finfo->file($tmp);
            $mime = ($detected !== false && $detected !== null) ? $detected : 'application/octet-stream';
        }

        return [
            'path'      => $tmp,
            'mime'      => $mime,
            'bytes'     => (int) filesize($tmp),
            'temporary' => true,
        ];
    }

    /**
     * Deletion is NOT part of "view": only the applicant who filed the
     * application (or HR / super admin) may remove supporting evidence —
     * never a mere approver who was only allowed to read it.
     */
    private function isOwnerOrDocumentAdmin(int $leaveApplicationId, int $requestingUserId): bool
    {
        $stmt = $this->db->prepare("
            SELECT u.role
            FROM leave_applications la
            JOIN employees e ON e.id = la.employee_id
            JOIN users u ON u.employee_id = e.employee_id
            WHERE la.id = ? AND u.id = ?
            LIMIT 1
        ");
        $pApp = $leaveApplicationId;
        $pUsr = $requestingUserId;
        $stmt->bind_param('ii', $pApp, $pUsr);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return in_array($row['role'], ['hr_manager', 'super_admin'], true);
    }

    /**
     * Delete document.
     */
    public function deleteDocument(int $documentId, int $requestingUserId): bool
    {
        $stmt = $this->db->prepare("
            SELECT leave_application_id, file_path
            FROM leave_application_documents
            WHERE id = ?
        ");
        $stmt->bind_param('i', $documentId);
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$document) {
            return false;
        }

        if (!$this->isOwnerOrDocumentAdmin((int) $document['leave_application_id'], $requestingUserId)) {
            return false;
        }

        $filePath = (string) $document['file_path'];
        if ($filePath !== '' && file_exists($filePath)) {
            unlink($filePath);
        }

        $delStmt = $this->db->prepare("DELETE FROM leave_application_documents WHERE id = ?");
        $delStmt->bind_param('i', $documentId);
        return $delStmt->execute();
    }
}
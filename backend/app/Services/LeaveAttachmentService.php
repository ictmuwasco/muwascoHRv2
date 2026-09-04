<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Leave Attachment Service
 * Handles file uploads, validation, storage, and retrieval for leave applications.
 */
class LeaveAttachmentService
{
    private static ?LeaveAttachmentService $instance = null;
    private array $config;
    private AuditService $audit;

    // Allowed MIME types for documents
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
    ];

    // Allowed file extensions
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    // Maximum file size (5MB)
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    // Upload directory
    private string $uploadDir;

    private function __construct()
    {
        $this->audit = AuditService::getInstance();
        $this->uploadDir = __DIR__ . '/../../../uploads/leave_attachments/';
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Validate uploaded file
     */
    public function validateFile(array $file): array
    {
        $errors = [];

        // Check if file was uploaded
        if (!isset($file['tmp_name']) || $file['tmp_name'] === '') {
            return ['valid' => false, 'errors' => ['No file uploaded']];
        }

        // Check for upload errors
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'errors' => ['File upload failed with error code: ' . $file['error']]];
        }

        // Check file size
        $fileSize = $file['size'] ?? 0;
        if ($fileSize > self::MAX_FILE_SIZE) {
            $errors[] = 'File size must not exceed 5MB.';
        }

        if ($fileSize === 0) {
            $errors[] = 'Uploaded file is empty.';
        }

        // Validate file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $errors[] = 'Invalid file extension. Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS);
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            $errors[] = 'Invalid file type. Only PDF, DOC, DOCX, JPG, and PNG files are allowed.';
        }

        // Check for potentially malicious content
        if (!$this->isSafeFile($file['tmp_name'], $mimeType)) {
            $errors[] = 'File contains potentially malicious content.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ];
    }

    /**
     * Check if file is safe
     */
    private function isSafeFile(string $filePath, string $mimeType): bool
    {
        // Read file content
        $content = file_get_contents($filePath);
        if ($content === false) {
            return false;
        }

        // Check for PHP tags or other potentially dangerous content
        $dangerousPatterns = [
            '<?php', '<?=', '<script', '<?xml',
            'eval(', 'exec(', 'system(', 'passthru(',
            'base64_decode(', 'shell_exec(', 'popen(',
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate unique filename
     */
    public function generateFileName(string $originalName, string $extension): string
    {
        $timestamp = date('Ymd_His');
        $uniqueId = bin2hex(random_bytes(8));
        $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '', pathinfo($originalName, PATHINFO_FILENAME));
        $baseName = substr($baseName, 0, 50); // Limit length
        
        return "{$timestamp}_{$uniqueId}_{$baseName}.{$extension}";
    }

    /**
     * Save uploaded file
     */
    public function saveFile(array $file, string $originalName, string $leaveApplicationId, string $documentType, int $uploadedBy): array
    {
        $validation = $this->validateFile($file);
        
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $extension = $validation['extension'];
        $mimeType = $validation['mime_type'];
        $fileName = $this->generateFileName($originalName, $extension);
        $destination = $this->uploadDir . $fileName;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'errors' => ['Failed to save file.']];
        }

        // Get file size
        $fileSize = filesize($destination);

        // Save to database
        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("
            INSERT INTO leave_attachments 
            (leave_application_id, document_type, file_name, file_path, file_size, file_extension, mime_type, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $relativePath = 'uploads/leave_attachments/' . $fileName;
        $stmt->bind_param('isssisii', 
            $leaveApplicationId, 
            $documentType, 
            $originalName, 
            $relativePath, 
            $fileSize, 
            $extension, 
            $mimeType, 
            $uploadedBy
        );

        if ($stmt->execute()) {
            $attachmentId = $stmt->insert_id;
            
            // Log audit event
            $this->audit->log(
                'UPLOAD',
                "Supporting document uploaded for leave application #{$leaveApplicationId}",
                [
                    'table_name' => 'leave_attachments',
                    'record_id' => $attachmentId,
                    'new_values' => [
                        'document_type' => $documentType,
                        'file_name' => $originalName,
                        'file_size' => $fileSize,
                    ],
                ]
            );

            return [
                'success' => true,
                'id' => $attachmentId,
                'file_name' => $originalName,
                'file_path' => $relativePath,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
            ];
        }

        // Clean up file if database insert fails
        unlink($destination);
        return ['success' => false, 'errors' => ['Database error: ' . $stmt->error]];
    }

    /**
     * Get attachments for a leave application
     */
    public function getAttachmentsByLeaveApplication(int $leaveApplicationId): array
    {
        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("
            SELECT * FROM leave_attachments 
            WHERE leave_application_id = ? 
            ORDER BY uploaded_at DESC
        ");
        $stmt->bind_param('i', $leaveApplicationId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get attachment by ID
     */
    public function getAttachment(int $attachmentId): ?array
    {
        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("
            SELECT * FROM leave_attachments 
            WHERE id = ?
        ");
        $stmt->bind_param('i', $attachmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $attachment = $result->fetch_assoc();
        return $attachment ?: null;
    }

    /**
     * Delete attachment
     */
    public function deleteAttachment(int $attachmentId, int $userId, string $action = 'deleted'): array
    {
        $attachment = $this->getAttachment($attachmentId);
        
        if (!$attachment) {
            return ['success' => false, 'errors' => ['Attachment not found.']];
        }

        $filePath = __DIR__ . '/../../..' . $attachment['file_path'];
        
        // Delete file from disk
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete from database
        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("DELETE FROM leave_attachments WHERE id = ?");
        $stmt->bind_param('i', $attachmentId);
        
        if ($stmt->execute()) {
            // Log audit event
            $this->audit->log(
                strtoupper($action),
                "Supporting document {$action} for leave application #{$attachment['leave_application_id']}",
                [
                    'table_name' => 'leave_attachments',
                    'record_id' => $attachmentId,
                    'old_values' => [
                        'document_type' => $attachment['document_type'],
                        'file_name' => $attachment['file_name'],
                    ],
                    'new_values' => [
                        'action' => $action,
                        'user_id' => $userId,
                    ],
                ]
            );

            return ['success' => true];
        }

        return ['success' => false, 'errors' => ['Database error: ' . $stmt->error]];
    }

    /**
     * Download attachment
     */
    public function downloadAttachment(int $attachmentId): void
    {
        $attachment = $this->getAttachment($attachmentId);
        
        if (!$attachment) {
            http_response_code(404);
            echo 'Attachment not found';
            return;
        }

        $filePath = __DIR__ . '/../../..' . $attachment['file_path'];
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        // Log download in audit
        $this->audit->log(
            'DOWNLOAD',
            "Supporting document downloaded for leave application #{$attachment['leave_application_id']}",
            [
                'table_name' => 'leave_attachments',
                'record_id' => $attachmentId,
                'new_values' => [
                    'document_type' => $attachment['document_type'],
                    'file_name' => $attachment['file_name'],
                ],
            ]
        );

        // Send file — Phase 7 (P7-7): sandbox CSP + nosniff + no-store,
        // forced download to preserve existing leave-attachment behavior.
        \App\Middleware\SecurityMiddleware::applyStreamHeaders(
            $attachment['mime_type'] ?? 'application/octet-stream',
            $attachment['file_name'] ?? 'attachment',
            true
        );
        header('Content-Length: ' . $attachment['file_size']);
        readfile($filePath);
        exit;
    }

    /**
     * Get database connection
     */
    private function getDbConnection(): \mysqli
    {
        $config = require __DIR__ . '/../../config/database.php';
        $dbConfig = $config['connections']['mysql'];
        
        $conn = new \mysqli(
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database']
        );

        if ($conn->connect_error) {
            throw new \RuntimeException('Database connection failed: ' . $conn->connect_error);
        }

        return $conn;
    }
}
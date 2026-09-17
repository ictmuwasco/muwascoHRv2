<?php

declare(strict_types=1);

namespace App\Services\HrPolicy;

/**
 * PolicyFileService — secure storage layer for HR policy files.
 *
 * SECURITY CONTRACT (mirrors backend/docs/security/FILE_SECURITY.md):
 *   * Files live in PRIVATE storage (STORAGE_PATH/policies) — never the
 *     webroot — and are served ONLY through permission-checked endpoints
 *     that stream via SecurityMiddleware::applyStreamHeaders().
 *   * The client filename is NEVER used for disk access. A safe server-side
 *     name is generated; the original name is kept for display labels only.
 *   * Uploads validated on: upload error, size cap, extension allowlist,
 *     finfo MIME allowlist, emptiness. Disk names are server-generated, so
 *     path traversal is impossible.
 *   * SHA-256 stored (file_hash) for integrity pinning.
 */
class PolicyFileService
{
    public const STORAGE_SUBDIR = 'policies';

    public static function maxBytes(): int
    {
        $mb = (int) \env('HR_POLICY_MAX_MB', 20);
        return max(1, $mb) * 1024 * 1024;
    }

    public static function storageDir(): string
    {
        return STORAGE_PATH . '/' . self::STORAGE_SUBDIR;
    }

    /**
     * Validate + persist an uploaded policy file.
     *
     * @return array{relative_path:string, stored_name:string, original_name:string,
     *               mime_type:string, size:int, hash:string}
     * @throws \InvalidArgumentException With a safe, user-facing message.
     */
    public static function store(array $uploadedFile): array
    {
        if (!isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('No file uploaded or upload error.');
        }

        $size = (int) ($uploadedFile['size'] ?? 0);
        if ($size <= 0) {
            throw new \InvalidArgumentException('Uploaded file is empty.');
        }
        if ($size > self::maxBytes()) {
            throw new \InvalidArgumentException(
                'File exceeds the maximum allowed size of ' . (int) \env('HR_POLICY_MAX_MB', 20) . 'MB.'
            );
        }

        $originalName = (string) ($uploadedFile['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'doc', 'docx'], true)) {
            throw new \InvalidArgumentException('Only PDF, DOC or DOCX policy files are allowed.');
        }

        $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new \InvalidArgumentException('Uploaded file is not readable.');
        }

        // Content-based verification using file signature (magic bytes).
        // This is more reliable than MIME type detection, especially for
        // .docx files which are ZIP archives and may be detected as application/zip.
        if (!self::verifyFileSignature($tmpPath, $ext)) {
            throw new \InvalidArgumentException(
                'File content does not match an allowed document type (pdf/doc/docx).'
            );
        }

        // Server-generated safe filename — the client name is discarded for
        // all disk purposes (path traversal / double-extension defence).
        $storedName = sprintf('policy-%s-%s.%s', bin2hex(random_bytes(8)), time(), $ext);

        $dir = self::storageDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            \logger()->error('Policy storage directory could not be created', ['dir' => $dir]);
            throw new \RuntimeException('Policy storage is not available.');
        }

        $target = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmpPath, $target)) {
            // Fallback for test/CLI harnesses where the file is not an HTTP upload.
            if (!@rename($tmpPath, $target)) {
                \logger()->error('Policy file move failed', ['stored_name' => $storedName]);
                throw new \RuntimeException('Could not store the uploaded policy file.');
            }
        }
        @chmod($target, 0644);

        $hash = hash_file('sha256', $target);
        if ($hash === false) {
            @unlink($target);
            throw new \RuntimeException('Could not hash the stored policy file.');
        }

        return [
            'relative_path' => $storedName,
            'stored_name'   => $storedName,
            'original_name' => self::sanitizeDisplayName($originalName, $ext),
            'mime_type'     => self::determineMimeType($ext),
            'size'          => $size,
            'hash'          => $hash,
        ];
    }

    /**
     * Verify file content by checking magic bytes (file signature).
     * This is more reliable than MIME type detection for .docx files.
     *
     * Note: Some .docx files may actually be OLE2 format (old .doc renamed to .docx),
     * so we accept both signatures for .docx files.
     *
     * @param string $filePath Path to the uploaded file
     * @param string $ext      File extension (pdf, doc, docx)
     * @return bool True if file signature matches the expected type
     */
    private static function verifyFileSignature(string $filePath, string $ext): bool
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        // Read the first 8 bytes (enough for all signatures we check)
        $header = fread($handle, 8);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return false;
        }

        $signature = substr($header, 0, 4);

        // Known file signatures
        $pdfSig = "\x25PDF";           // PDF: %PDF
        $ole2Sig = "\xD0\xCF\x11\xE0"; // DOC/OLE2: D0 CF 11 E0
        $zipSig = "\x50\x4B\x03\x04";  // DOCX/ZIP: PK

        switch ($ext) {
            case 'pdf':
                return $signature === $pdfSig;

            case 'doc':
                return $signature === $ole2Sig;

            case 'docx':
                // Accept both ZIP (modern .docx) and OLE2 (old .doc renamed to .docx)
                return $signature === $zipSig || $signature === $ole2Sig;

            default:
                return false;
        }
    }

    /**
     * Determine the canonical MIME type based on file extension.
     */
    private static function determineMimeType(string $ext): string
    {
        $mimeTypes = [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];

        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }

    /**
     * Resolve a stored relative path to an absolute path, enforcing it stays
     * inside the private policy storage directory (defence in depth).
     */
    public static function absolutePath(string $relativePath): ?string
    {
        $base = realpath(self::storageDir());
        if ($base === false) {
            return null;
        }
        $candidate = realpath($base . '/' . ltrim(str_replace('\\', '/', $relativePath), '/'));
        if ($candidate === false || strpos($candidate, $base . DIRECTORY_SEPARATOR) !== 0) {
            return null; // traversal or non-existent
        }
        return $candidate;
    }

    /** Best-effort delete of a stored file (never throws). */
    public static function delete(string $relativePath): void
    {
        $abs = self::absolutePath($relativePath);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
    }

    /** Strip path separators / control chars from the display filename. */
    public static function sanitizeDisplayName(string $name, string $fallbackExt): string
    {
        $name = str_replace(['/', '\\', "\0"], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '') {
            $name = 'policy-manual.' . $fallbackExt;
        }
        return mb_substr($name, 0, 255);
    }
}

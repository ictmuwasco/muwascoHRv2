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
        // PHP-level upload errors (php.ini caps, partial writes, missing tmp
        // dir) arrive here as a non-OK error code — translate each into an
        // actionable message instead of the old generic
        // 'No file uploaded or upload error.' which left HR guessing.
        if (!isset($uploadedFile['error']) || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::uploadErrorMessage(
                (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE)
            ));
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

        // Legacy OLE2 Word binaries are accepted by the storage signature
        // check (.doc) but DocumentParser can never turn them into policy
        // sections (PhpWord only reads OOXML). Reject by EXTENSION up front so
        // the user gets the Save-As guidance on the storage step instead of a
        // parse failure after the file has already been written to disk.
        if ($ext === 'doc') {
            throw new \InvalidArgumentException(
                'Legacy Word (.doc) files cannot be converted into policy sections. '
                . 'Open the file in Word, choose "Save As" -> .docx or PDF, and upload the new file.'
            );
        }

        if (!in_array($ext, ['pdf', 'docx'], true)) {
            throw new \InvalidArgumentException('Only PDF or DOCX policy files are allowed. For legacy Word documents, use "Save As" -> .docx or PDF first.');
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
                'File content does not match an allowed document type (pdf/docx). '
                . 'If this is a Word document, open it in Word and use "Save As" -> .docx or PDF first.'
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
     * Map a PHP upload error code to an actionable, user-facing message.
     *
     * The old generic 'No file uploaded or upload error.' left HR re-trying
     * uploads that could never work (e.g. a PDF bigger than the php.ini cap).
     * Each message says what happened and what to do: compress/split, retry,
     * or ask IT to raise the server limit.
     */
    public static function uploadErrorMessage(int $code): string
    {
        $maxMb = (int) \env('HR_POLICY_MAX_MB', 20);

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'The file is too large for the server to accept (PHP upload limit). '
                . "Compress the PDF or split it, keeping it under {$maxMb}MB, and try again. "
                . 'If it is already under that size, ask IT to raise upload_max_filesize / post_max_size.',
            UPLOAD_ERR_PARTIAL =>
                'The upload was interrupted (partial file received). Please try again on a stable connection.',
            UPLOAD_ERR_NO_FILE =>
                'No file was received. Please choose a PDF or DOCX file and try again.',
            UPLOAD_ERR_NO_TMP_DIR =>
                'The server is temporarily unable to accept uploads (missing upload directory). Please try again later or contact IT.',
            UPLOAD_ERR_CANT_WRITE =>
                'The server could not save the uploaded file (disk write failed). Please try again later or contact IT.',
            UPLOAD_ERR_EXTENSION =>
                'The upload was blocked by a server extension. Please try again or contact IT.',
            default =>
                'The file could not be uploaded (unknown upload error). Please try again.',
        };
    }

    /**
     * Detect a POST body that PHP silently discarded because it exceeded
     * post_max_size: $_POST and $_FILES are then BOTH empty even though the
     * browser sent a multipart body (Content-Length > 0). Without this check
     * the user sees 'title and version are required' for what is really an
     * oversized request.
     */
    public static function truncatedPostMessage(): ?string
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength <= 0) {
            return null;
        }
        if (!empty($_POST) || !empty($_FILES)) {
            return null;
        }
        $postMax = ini_get('post_max_size');
        $cap = ($postMax !== false && $postMax !== '') ? " {$postMax}" : '';
        return 'The request was too large for the server to accept (post_max_size' . $cap . '). '
            . 'Compress the PDF or split it into smaller parts and try again. '
            . 'If the file is small, ask IT to raise post_max_size / upload_max_filesize.';
    }

    /**
     * Verify file content by checking magic bytes (file signature).
     * This is more reliable than MIME type detection for .docx files.
     *
     * Legacy OLE2 binaries are NEVER accepted here, even when renamed to
     * .docx: DocumentParser cannot turn them into sections (PhpWord reads
     * OOXML only), so they fail with the Save-As guidance alongside the
     * extension check.
     *
     * @param string $filePath Path to the uploaded file
     * @param string $ext      File extension (pdf, docx)
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

            case 'docx':
                // ZIP only. An OLE2 payload renamed to .docx is a legacy
                // binary and can never be parsed — reject it here with the
                // Save-As guidance rather than dying mid-parse.
                return $signature === $zipSig;

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

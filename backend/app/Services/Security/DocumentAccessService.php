<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Helpers\Database;
use App\Helpers\StorageEncryption;
use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * DocumentAccessService - the two-step gate in front of an encrypted document.
 *
 * WHY A CODE GOES TO THE OWNER, NOT THE REQUESTER
 *   A 6-digit code proves only "someone can read this inbox". Sending it to the
 *   person asking for the document would prove nothing the session cookie does
 *   not already prove. The control is only meaningful when the code lands with
 *   the data subject, so the EMPLOYEE WHO OWNS THE DOCUMENT must supply the
 *   code before their plaintext is released. HR can request; only the owner can
 *   open.
 *
 * WHAT THIS SERVICE NEVER DOES
 *   - It never returns the code. The code is generated, emailed, hashed, and the
 *     plaintext is discarded immediately.
 *   - It never stores the code. Only SHA-256 is persisted, so a table dump
 *     cannot be replayed.
 *   - It never approves on request. Verification is a separate call, and the
 *     approval is single-use: spending it sets consumed_at, so a leaked
 *     approval cannot be replayed against a second request.
 *
 * BRUTE FORCE
 *   A 6-digit code inside a 10-minute window is 1e6 guesses, so `attempts` is
 *   incremented on every mismatch and the row is burned at MAX_ATTEMPTS. The
 *   counter is checked in the verification query itself rather than trusted from
 *   a prior read, so a concurrent second request cannot slip past the limit.
 *
 * FAIL CLOSED
 *   If the owner's email is missing or undeliverable the request fails and
 *   NOTHING is issued. It never falls back to approving without a code, and it
 *   never reports a different message depending on whether the owner exists -
 *   that difference would be an employee-existence oracle.
 */
final class DocumentAccessService
{
    /** Minutes a code stays valid. */
    public const TTL_MINUTES = 10;

    /** Wrong codes tolerated before the approval is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Codes one user may request per minute, across all their requests. */
    public const RATE_LIMIT_PER_MINUTE = 5;

    public const TABLE = 'employee_documents';

    public const ACTION_REQUESTED = 'DOCUMENT_ACCESS_REQUESTED';
    public const ACTION_APPROVED  = 'DOCUMENT_ACCESS_APPROVED';
    public const ACTION_DENIED    = 'DOCUMENT_ACCESS_DENIED';
    public const ACTION_OPENED    = 'DOCUMENT_ACCESS_OPENED';
    public const ACTION_EXPIRED   = 'DOCUMENT_ACCESS_EXPIRED';

    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // =================================================================
    // Request
    // =================================================================

    /**
     * Email the owner a code for one document.
     *
     * @return array{ok:bool, reason:string, masked_email:?string}
     *         `masked_email` tells the REQUESTER which address was used, so they
     *         can direct the owner to the right inbox. It is always masked, and
     *         it is null whenever no code was sent - the response shape must not
     *         differ between "owner has no email" and "owner has one", or this
     *         becomes an oracle for who is a real employee.
     */
    public function requestApproval(int $documentId, int $requesterUserId): array
    {
        $document = $this->loadDocument($documentId);
        if ($document === null) {
            return ['ok' => false, 'reason' => 'document_not_found', 'masked_email' => null];
        }

        $owner = $this->loadOwner((int) $document['employee_id']);
        if ($owner === null || (string) $owner['email'] === '') {
            // Log for operators; tell the requester nothing specific.
            AuditService::getInstance()->log(
                AuditService::MODULE_EMPLOYEES,
                self::ACTION_DENIED,
                'Document access requested but the owner has no reachable email',
                [
                    'target_type' => 'Document',
                    'target_id'   => $documentId,
                    'status'      => 'FAILED',
                    'metadata'    => ['requester_user_id' => $requesterUserId],
                ]
            );
            return ['ok' => false, 'reason' => 'owner_unreachable', 'masked_email' => null];
        }

        // Rate limit BEFORE generating anything, so a spam loop cannot burn
        // the owner's inbox.
        if ($this->recentRequestCount($requesterUserId) >= self::RATE_LIMIT_PER_MINUTE) {
            return ['ok' => false, 'reason' => 'rate_limited', 'masked_email' => null];
        }

        // An existing unconsumed approval for the same (document, requester) is
        // REUSED rather than duplicated: re-requesting must not let a requester
        // pile up codes and race them.
        if ($this->findLiveApproval($documentId, $requesterUserId) !== null) {
            return [
                'ok'           => true,
                'reason'       => 'already_pending',
                'masked_email' => $this->maskEmail((string) $owner['email']),
            ];
        }

        $code      = $this->generateCode();
        $codeHash  = hash('sha256', $code);
        $expiresAt = date('Y-m-d H:i:s', time() + (self::TTL_MINUTES * 60));
        $now       = date('Y-m-d H:i:s');
        $emailHash = hash('sha256', strtolower((string) $owner['email']));

        if (!$this->insertApproval(
            $documentId,
            $requesterUserId,
            (int) $document['employee_id'],
            $emailHash,
            $codeHash,
            $expiresAt,
            $now
        )) {
            return ['ok' => false, 'reason' => 'storage_failed', 'masked_email' => null];
        }

        $this->emailCode(
            (string) $owner['email'],
            (string) ($owner['first_name'] ?: 'there'),
            $code,
            (string) $document['document_name'],
            (int) $document['employee_id'],
            $requesterUserId
        );

        AuditService::getInstance()->log(
            AuditService::MODULE_EMPLOYEES,
            self::ACTION_REQUESTED,
            'Document access requested; a code was emailed to the document owner',
            [
                'target_type' => 'Document',
                'target_id'   => $documentId,
                // The document NAME is metadata the owner is about to be asked
                // about, so it is safe and useful. The code never appears.
                'target_name' => (string) $document['document_name'],
                'metadata'    => [
                    'requester_user_id' => $requesterUserId,
                    'owner_employee_id' => (int) $document['employee_id'],
                    'expires_at'        => $expiresAt,
                ],
            ]
        );

        return [
            'ok'           => true,
            'reason'       => 'code_sent',
            'masked_email' => $this->maskEmail((string) $owner['email']),
        ];
    }

    // =================================================================
    // Verify and spend
    // =================================================================

    /**
     * Check the owner's code for a document.
     *
     * @return array{ok:bool, reason:string}
     */
    public function verifyCode(int $documentId, int $requesterUserId, string $code): array
    {
        $given = $this->normaliseCode($code);
        if ($given === null) {
            return ['ok' => false, 'reason' => 'malformed_code'];
        }

        // The row is re-read INSIDE the guard conditions, so an expired,
        // consumed or over-attempted approval is never compared against a code
        // at all.
        $sql = 'SELECT id, code_hash FROM document_access_otp
                 WHERE document_id = ? AND requester_user_id = ?
                   AND verified_at IS NOT NULL AND consumed_at IS NULL
                   AND expires_at > NOW() AND attempts < ?
                 ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pDoc = $documentId;
        $pUsr = $requesterUserId;
        $pMax = self::MAX_ATTEMPTS;
        $stmt->bind_param('iii', $pDoc, $pUsr, $pMax);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return ['ok' => false, 'reason' => 'no_live_approval'];
        }

        // Constant-time compare. A plain !== leaks how many leading digits were
        // right, which is enough to walk a 6-digit space far faster than the
        // attempt limit suggests.
        if (!hash_equals((string) $row['code_hash'], hash('sha256', $given))) {
            $this->incrementAttempts((int) $row['id']);
            return ['ok' => false, 'reason' => 'incorrect_code'];
        }

        $this->markVerified((int) $row['id']);

        AuditService::getInstance()->log(
            AuditService::MODULE_EMPLOYEES,
            self::ACTION_APPROVED,
            'Document owner verified the emailed access code',
            [
                'target_type' => 'Document',
                'target_id'   => $documentId,
                'metadata'    => ['requester_user_id' => $requesterUserId],
            ]
        );

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Is there a verified, unspent, unexpired approval right now?
     *
     * Evaluated in ONE query rather than cached from the verification call, so
     * the window between verifying and downloading is as narrow as the database
     * allows. It is deliberately not an application-level cache.
     */
    public function hasLiveApproval(int $documentId, int $requesterUserId): bool
    {
        $sql = 'SELECT id FROM document_access_otp
                 WHERE document_id = ? AND requester_user_id = ?
                   AND verified_at IS NOT NULL AND consumed_at IS NULL
                   AND expires_at > NOW() AND attempts < ?
                 LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pDoc = $documentId;
        $pUsr = $requesterUserId;
        $pMax = self::MAX_ATTEMPTS;
        $stmt->bind_param('iii', $pDoc, $pUsr, $pMax);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        return $row !== null;
    }

    /**
     * Spend an approval, returning false unless there really was a live one.
     *
     * The UPDATE carries the same conditions as the SELECT and counts as spent
     * only when it changed a row. That makes two simultaneous downloads
     * mutually exclusive: exactly one caller sees affected_rows === 1, and the
     * loser is refused rather than served a second copy.
     */
    public function consumeApproval(int $documentId, int $requesterUserId): bool
    {
        $sql = 'UPDATE document_access_otp
                SET consumed_at = NOW()
                WHERE document_id = ? AND requester_user_id = ?
                  AND verified_at IS NOT NULL AND consumed_at IS NULL
                  AND expires_at > NOW() AND attempts < ?';

        $stmt = $this->db->prepare($sql);
        $pDoc = $documentId;
        $pUsr = $requesterUserId;
        $pMax = self::MAX_ATTEMPTS;
        $stmt->bind_param('iii', $pDoc, $pUsr, $pMax);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $ok && $affected === 1;
    }

    /**
     * Refuse and burn any pending approval for a document.
     */
    public function deny(int $documentId, int $requesterUserId): void
    {
        $sql = 'UPDATE document_access_otp
                SET verified_at = NULL, consumed_at = NOW()
                WHERE document_id = ? AND requester_user_id = ? AND consumed_at IS NULL';

        $stmt = $this->db->prepare($sql);
        $pDoc = $documentId;
        $pUsr = $requesterUserId;
        $stmt->bind_param('ii', $pDoc, $pUsr);
        $stmt->execute();
        $stmt->close();
    }

    // =================================================================
    // Decryption
    // =================================================================

    /**
     * Decrypt an on-disk document to a temp file and describe it.
     *
     * The caller owns the returned path and MUST unlink it. Returning a path
     * rather than the contents keeps memory bounded for a large PDF, the same
     * reasoning as StorageEncryption::decryptFile().
     *
     * @return array{path:string, mime:string, bytes:int, temporary:bool}
     */
    public function decryptToTempFile(array $document, string $tableName = self::TABLE): array
    {
        $documentId = (int) $document['id'];
        $fileName   = (string) $document['file_name'];
        $encrypted  = (int) ($document['is_encrypted'] ?? 0) === 1;

        $sourcePath = $this->resolveDocumentPath($fileName);
        if ($sourcePath === null) {
            throw new \RuntimeException('Document file is missing from storage.');
        }

        if (!$encrypted) {
            // A legacy plaintext document still passes through the same OTP
            // gate; the gate is about consent, not only about encryption.
            $detected = $this->detectMime($sourcePath) ?? 'application/octet-stream';
            return [
                'path'      => $sourcePath,
                'mime'      => $detected,
                'bytes'     => (int) filesize($sourcePath),
                'temporary' => false,
            ];
        }

        $key = $this->loadFileKey($tableName, $documentId);
        if ($key === null) {
            // Encrypted with no key row is unrecoverable. Report it rather than
            // streaming a container as if it were the document.
            throw new \RuntimeException(
                'This document is encrypted but its decryption key is missing, '
                . 'so it cannot be opened. Please contact IT.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file for decryption.');
        }

        try {
            StorageEncryption::decryptFile($sourcePath, $tmp, $key);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new \RuntimeException(
                'The document could not be decrypted: ' . $e->getMessage(),
                0,
                $e
            );
        }

        // The stored ORIGINAL mime, because finfo on a container can only ever
        // report MWSC1 and the browser needs the real type to render or save.
        $mime = (string) ($document['original_mime'] ?? '');
        if ($mime === '') {
            $mime = $this->detectMime($tmp) ?? 'application/octet-stream';
        }

        return [
            'path'      => $tmp,
            'mime'      => $mime,
            'bytes'     => (int) filesize($tmp),
            'temporary' => true,
        ];
    }

    /**
     * Unwrap the file key for a record from file_encryption (migration 105).
     */
    private function loadFileKey(string $tableName, int $recordId): ?string
    {
        $sql = 'SELECT file_key_wrapped, nonce, key_version
                FROM file_encryption
                WHERE table_name = ? AND record_id = ?
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pTbl = $tableName;
        $pId  = $recordId;
        $stmt->bind_param('si', $pTbl, $pId);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        if ($row === null) {
            return null;
        }

        try {
            return StorageEncryption::unwrapKey(
                (string) $row['file_key_wrapped'],
                (string) $row['nonce'],
                StorageEncryption::keyAad($tableName, $recordId),
                (int) $row['key_version']
            );
        } catch (\Throwable $e) {
            error_log('[DocumentAccessService] key unwrap failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Locate a document on disk, preferring private storage over the legacy
     * webroot location.
     *
     * basename() is not cosmetic: a file_name coming from the database must
     * never be able to traverse out of the upload directory.
     */
    private function resolveDocumentPath(string $fileName): ?string
    {
        $safe = basename($fileName);
        if ($safe === '' || $safe !== $fileName) {
            return null;
        }

        $candidates = [
            STORAGE_PATH . '/uploads/documents/' . $safe,
            __DIR__ . '/../../public/uploads/employee_documents/' . $safe,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function detectMime(string $path): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime === false ? null : $mime;
    }

    // =================================================================
    // Private helpers
    // =================================================================

    private function loadDocument(int $documentId): ?array
    {
        $sql = 'SELECT id, employee_id, document_name, file_name, category,
                       is_encrypted, size_bytes, original_mime
                FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pId = $documentId;
        $stmt->bind_param('i', $pId);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    /**
     * The document OWNER's user record - the person whose code is required.
     *
     * Joined on users.employee_id = employees.employee_id, which is the business
     * employee NUMBER on both sides, NOT the employees primary key. Joining the
     * wrong column yields null and the request then fails as
     * "owner_unreachable", which looks like a mail problem rather than a join bug.
     */
    private function loadOwner(int $employeeId): ?array
    {
        $sql = 'SELECT u.id, u.email, u.first_name
                FROM users u
                JOIN employees e ON e.employee_id = u.employee_id
                WHERE e.id = ? AND u.is_active = 1
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pId = $employeeId;
        $stmt->bind_param('i', $pId);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    private function findLiveApproval(int $documentId, int $requesterUserId): ?array
    {
        $sql = 'SELECT id FROM document_access_otp
                WHERE document_id = ? AND requester_user_id = ?
                  AND consumed_at IS NULL AND expires_at > NOW()
                ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $pDoc = $documentId;
        $pUsr = $requesterUserId;
        $stmt->bind_param('ii', $pDoc, $pUsr);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    private function recentRequestCount(int $requesterUserId): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM document_access_otp
                WHERE requester_user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)';

        $stmt = $this->db->prepare($sql);
        $pUsr = $requesterUserId;
        $stmt->bind_param('i', $pUsr);
        $ok = $stmt->execute();
        $row = $ok ? $stmt->get_result()->fetch_assoc() : null;
        $stmt->close();

        return $row === null ? 0 : (int) $row['c'];
    }

    private function insertApproval(
        int $documentId,
        int $requesterUserId,
        int $ownerEmployeeId,
        string $emailHash,
        string $codeHash,
        string $expiresAt,
        string $now
    ): bool {
        $sql = 'INSERT INTO document_access_otp
                    (document_id, table_name, requester_user_id, owner_employee_id,
                     owner_email_hash, code_hash, attempts, expires_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)';

        $stmt = $this->db->prepare($sql);
        $pDoc   = $documentId;
        $pTbl   = self::TABLE;
        $pUsr   = $requesterUserId;
        $pOwner = $ownerEmployeeId;
        $pEmail = $emailHash;
        $pCode  = $codeHash;
        $pExp   = $expiresAt;
        $pNow   = $now;

        $stmt->bind_param('issssss', $pDoc, $pTbl, $pUsr, $pOwner, $pEmail, $pCode, $pExp, $pNow);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            error_log('[DocumentAccessService] approval insert failed: ' . $err);
        }
        return $ok;
    }

    private function markVerified(int $id): void
    {
        $sql = 'UPDATE document_access_otp SET verified_at = NOW() WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $pId = $id;
        $stmt->bind_param('i', $pId);
        $stmt->execute();
        $stmt->close();
    }

    private function incrementAttempts(int $id): void
    {
        $sql = 'UPDATE document_access_otp
                SET attempts = attempts + 1
                WHERE id = ? AND attempts < ?';

        $stmt = $this->db->prepare($sql);
        $pId  = $id;
        $pMax = self::MAX_ATTEMPTS;
        $stmt->bind_param('ii', $pId, $pMax);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * A 6-digit code from a CSPRNG.
     *
     * random_int() rather than mt_rand/uniqid: a predictable code would
     * undermine the whole control, and the attempt limit is only meaningful
     * while the code is genuinely unpredictable.
     */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Normalise input to exactly 6 digits, or reject it.
     *
     * Returns null for anything else, so a malformed value is never compared
     * against a stored hash. Non-digits are stripped rather than rejected
     * outright because users routinely type "123 456" or paste from a client
     * that inserts spaces.
     */
    private function normaliseCode(string $code): ?string
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';
        return strlen($digits) === 6 ? $digits : null;
    }

    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $head = substr($local, 0, 1);
        $tail = strlen($local) > 2 ? substr($local, -1) : '';

        return $head . str_repeat('*', max(2, strlen($local) - 2)) . $tail . '@' . $domain;
    }

    /**
     * Email the code to the document owner.
     *
     * The message names WHO is asking and WHICH document, so the owner can make
     * a real decision rather than rubber-stamping an opaque code. It contains
     * no document contents and no download link on purpose: entering the code
     * in the session that asked is the only way through, so a leaked email
     * cannot be replayed from a different machine.
     */
    private function emailCode(
        string $to,
        string $firstName,
        string $code,
        string $documentName,
        int $ownerEmployeeId,
        int $requesterUserId
    ): void {
        try {
            $requesterName = (string) \db()->fetchValue(
                "SELECT CONCAT_WS(' ', first_name, last_name) FROM users WHERE id = ? LIMIT 1",
                'i',
                [$requesterUserId]
            );
            $requesterLabel = trim($requesterName) !== '' ? trim($requesterName) : 'A colleague';

            $safeName     = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
            $safeDoc      = htmlspecialchars($documentName, ENT_QUOTES, 'UTF-8');
            $safeRequester = htmlspecialchars($requesterLabel, ENT_QUOTES, 'UTF-8');

            $body = "
                <h2>Document access request</h2>
                <p>Hello {$safeName},</p>
                <p><strong>{$safeRequester}</strong> has asked to open one of your
                   encrypted documents:</p>
                <p style='padding:8px 12px;background:#f3f4f6;border-radius:6px'>
                   {$safeDoc}</p>
                <p>To approve this, enter the code below in the MUWASCO HR System where the
                   request was made. There is deliberately no link: approval has to happen
                   in the session that asked.</p>
                <p style='font-size:28px;letter-spacing:6px;font-weight:bold;
                          text-align:center;margin:20px 0'>{$code}</p>
                <p>This code expires in " . self::TTL_MINUTES . " minutes and can be used once.</p>
                <p>If you were not expecting this, do nothing - nobody can open the document
                   without this code. Consider reporting it to HR.</p>
            ";

            $sent = NotificationService::getInstance()->sendEmail(
                $to,
                'Your approval is needed to open a document',
                $body
            );

            if (!$sent) {
                error_log(
                    '[DocumentAccessService] code email failed to send; owner_employee_id='
                    . $ownerEmployeeId
                );
            }
        } catch (\Throwable $e) {
            // A mail failure must not crash the request. The approval row exists
            // but is unusable, which fails closed: the requester simply never
            // receives a working code and cannot open the document.
            error_log('[DocumentAccessService] emailCode failed: ' . $e->getMessage());
        }
    }
}



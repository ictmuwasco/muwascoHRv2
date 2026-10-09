<?php

declare(strict_types=1);

namespace App\Models;

/**
 * HrPolicyDocument — versioned HR policy documents (migration 081).
 *
 * One row per policy VERSION. Workflow: draft → review → published → archived;
 * only ONE row is ever the active official policy (DB-guaranteed by the
 * nullable UNIQUE active_token). Files live in PRIVATE storage
 * (backend/storage/policies); file_name is display-only, disk access always
 * uses file_path + stored_name generated server-side by PolicyFileService.
 */
class HrPolicyDocument extends BaseModel
{
    protected static string $table = 'hr_policy_documents';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_REVIEW, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /**
     * Section-extraction state (migration 108) — ORTHOGONAL to the publishing
     * `status` above and deliberately not merged into it.
     *
     * A version is stored and returned to HR instantly as a draft; the
     * expensive PDF/DOCX -> section tree parse runs in a CLI worker, because
     * production FPM is capped at 128M/30s and a fatal there is uncatchable
     * (the 500-with-no-log failure this split fixes). `pending` means "file is
     * stored, sections not extracted yet".
     */
    public const PARSE_PENDING    = 'pending';
    public const PARSE_PROCESSING = 'processing';
    public const PARSE_DONE       = 'done';
    public const PARSE_FAILED     = 'failed';

    public const PARSE_STATUSES = [
        self::PARSE_PENDING, self::PARSE_PROCESSING, self::PARSE_DONE, self::PARSE_FAILED,
    ];

    /** Max extraction attempts before the worker gives up on a file. */
    public const PARSE_MAX_ATTEMPTS = 3;

    /** AI source hierarchy (manual > CBA > circular > law > procedure). */
    public const SOURCE_MANUAL    = 'manual';
    public const SOURCE_CBA       = 'cba';
    public const SOURCE_CIRCULAR  = 'circular';
    public const SOURCE_LAW       = 'law';
    public const SOURCE_PROCEDURE = 'procedure';
    public const SOURCE_HANDBOOK  = 'handbook';

    public const SOURCE_TYPES = [
        self::SOURCE_MANUAL, self::SOURCE_CBA, self::SOURCE_CIRCULAR,
        self::SOURCE_LAW, self::SOURCE_PROCEDURE, self::SOURCE_HANDBOOK,
    ];

    protected static array $fillable = [
        'title', 'description', 'version', 'status', 'source_type',
        'effective_date', 'published_at', 'archived_at',
        'file_path', 'file_name', 'stored_name', 'file_hash',
        'mime_type', 'file_size', 'page_count', 'section_count',
        'uploaded_by', 'is_active', 'active_token',
        'acknowledgement_message', 'deleted_at',
        'parse_status', 'parse_error', 'parse_attempts', 'parsed_at',
    ];

    /**
     * Claim a document for section extraction.
     *
     * A conditional UPDATE, not SELECT-then-UPDATE: two overlapping worker
     * runs both see the same 'pending' row, but only the one whose UPDATE
     * actually matched gets affected_rows = 1. The loser sees 0 and moves on,
     * so a document is never parsed twice concurrently and no lock table is
     * needed. Same pattern as the notification worker's queue drain.
     *
     * Rows already at PARSE_MAX_ATTEMPTS are left alone: a file that failed
     * three times is permanently broken (encrypted PDF, image-only scan) and
     * retrying it every cron tick would spin forever.
     */
    public static function claimForParsing(int $id): bool
    {
        // Raw SQL, not \db()->update(): that helper binds EVERY value as a
        // parameter, so 'parse_attempts + 1' would be stored as the literal
        // string and coerced to 0 by MySQL — the counter would never advance
        // and a broken file would be retried forever.
        $stmt = \db()->query(
            "UPDATE " . static::$table . "
                SET parse_status = '" . self::PARSE_PROCESSING . "',
                    parse_attempts = parse_attempts + 1
              WHERE id = ?
                AND parse_status = '" . self::PARSE_PENDING . "'
                AND parse_attempts < " . self::PARSE_MAX_ATTEMPTS,
            'i',
            [$id]
        );

        return $stmt->affected_rows === 1;
    }

    /**
     * Documents still awaiting section extraction, oldest first, capped so one
     * cron tick cannot open an unbounded amount of work.
     */
    public static function pendingParsing(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        return \db()->fetchAll(
            "SELECT * FROM " . static::$table . "
              WHERE parse_status = '" . self::PARSE_PENDING . "'
                AND parse_attempts < " . self::PARSE_MAX_ATTEMPTS . "
                AND deleted_at IS NULL
              ORDER BY id ASC
              LIMIT {$limit}"
        );
    }

    /** The single active, published official policy (employee-facing). */
    public static function findActive(): ?array
    {
        return \db()->fetchOne(
            "SELECT * FROM " . static::$table . "
              WHERE is_active = 1 AND status = 'published' AND deleted_at IS NULL
              LIMIT 1"
        );
    }

    /** All versions for HR administration (any status, newest first). */
    public static function allVersions(): array
    {
        return \db()->fetchAll(
            "SELECT d.*,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name, u.surname)) AS uploaded_by_name
               FROM " . static::$table . " d
               LEFT JOIN users u ON u.id = d.uploaded_by
              WHERE d.deleted_at IS NULL
              ORDER BY d.is_active DESC, d.created_at DESC"
        );
    }

    /** Published documents only (employee-visible). */
    public static function published(): array
    {
        return \db()->fetchAll(
            "SELECT id, title, version, source_type, effective_date, published_at,
                    section_count, page_count, file_name, is_active
               FROM " . static::$table . "
              WHERE status = 'published' AND deleted_at IS NULL
              ORDER BY is_active DESC, effective_date DESC, created_at DESC"
        );
    }

    /**
     * Permission-scoped read: employees may only load PUBLISHED documents;
     * managers may load any non-deleted document. Unknown/restricted → null.
     */
    public static function findForReader(int $id, bool $isManager): ?array
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE id = ? AND deleted_at IS NULL";
        if (!$isManager) {
            $sql .= " AND status = 'published'";
        }
        return \db()->fetchOne($sql . " LIMIT 1", 'i', [$id]);
    }

    /**
     * Promote a document to the single active version and archive the previous
     * active one. MUST run inside a transaction (PolicyService::publish).
     */
    public static function activate(int $id): void
    {
        $now = date('Y-m-d H:i:s');

        // Free the unique active token of any previous active version.
        \db()->query(
            "UPDATE " . static::$table . "
                SET is_active = 0, active_token = NULL,
                    status = 'archived', archived_at = ?
              WHERE is_active = 1 AND id <> ?",
            'si', [$now, $id]
        );

        \db()->query(
            "UPDATE " . static::$table . "
                SET is_active = 1, active_token = ?,
                    status = 'published', published_at = COALESCE(published_at, ?)
              WHERE id = ?",
            'ssi', [self::generateActiveToken(), $now, $id]
        );
    }

    /** Clear the active flag (archive path). MUST run inside a transaction. */
    public static function deactivate(int $id, string $at): void
    {
        \db()->query(
            "UPDATE " . static::$table . "
                SET is_active = 0, active_token = NULL, status = 'archived', archived_at = ?
              WHERE id = ?",
            'si', [$at, $id]
        );
    }

    public static function generateActiveToken(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

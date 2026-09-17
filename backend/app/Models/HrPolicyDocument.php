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
    ];

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

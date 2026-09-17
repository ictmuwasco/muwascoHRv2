<?php

declare(strict_types=1);

namespace App\Models;

/**
 * HrPolicyBookmark — user-specific bookmarks of policy sections (migration 081).
 *
 * Bookmarks NEVER modify the official policy content; they are per-user
 * pointers only. Recently-viewed tracking (hr_policy_recent_views) is a
 * related per-user table pruned by the service layer.
 */
class HrPolicyBookmark extends BaseModel
{
    protected static string $table = 'hr_policy_bookmarks';

    protected static array $fillable = ['user_id', 'section_id'];

    /** The user's bookmarks with section metadata (for the reader sidebar). */
    public static function forUser(int $userId): array
    {
        return \db()->fetchAll(
            "SELECT b.id AS bookmark_id, b.created_at AS bookmarked_at,
                    s.id AS section_id, s.section_number, s.title, s.page_start,
                    d.id AS document_id, d.title AS document_title
               FROM " . static::$table . " b
               JOIN hr_policy_sections s ON s.id = b.section_id
               JOIN hr_policy_documents d ON d.id = s.policy_document_id
              WHERE b.user_id = ? AND d.status = 'published' AND d.deleted_at IS NULL
              ORDER BY b.created_at DESC",
            'i', [$userId]
        );
    }

    public static function isBookmarked(int $userId, int $sectionId): bool
    {
        return (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE user_id = ? AND section_id = ?",
            'ii', [$userId, $sectionId]
        ) > 0;
    }

    public static function add(int $userId, int $sectionId): int
    {
        return \db()->insert(static::$table, [
            'user_id' => $userId,
            'section_id' => $sectionId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function remove(int $userId, int $sectionId): int
    {
        return \db()->delete(
            static::$table,
            'user_id = ? AND section_id = ?',
            'ii', [$userId, $sectionId]
        );
    }
}

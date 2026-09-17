<?php

declare(strict_types=1);

namespace App\Models;

/**
 * HrPolicySection — structured section tree of a policy document (migration 081).
 *
 * Content is the OFFICIAL policy wording ingested from the manual and is
 * preserved verbatim (never reworded by the application). parent_id = NULL
 * means a top-level chapter.
 */
class HrPolicySection extends BaseModel
{
    protected static string $table = 'hr_policy_sections';

    protected static array $fillable = [
        'policy_document_id', 'parent_id', 'section_number', 'title',
        'content', 'page_start', 'page_end', 'sort_order',
    ];

    /**
     * Ordered chapter/section tree for the reader's left navigation
     * (metadata only — no content payloads).
     */
    public static function tree(int $documentId): array
    {
        return \db()->fetchAll(
            "SELECT id, parent_id, section_number, title, page_start, page_end, sort_order
               FROM " . static::$table . "
              WHERE policy_document_id = ?
              ORDER BY sort_order ASC, id ASC",
            'i', [$documentId]
        );
    }

    /**
     * Section with full content. Only served for PUBLISHED documents to
     * employees (document status re-checked by the service — IDOR-safe).
     */
    public static function findInSection(int $documentId, int $id): ?array
    {
        return \db()->fetchOne(
            "SELECT * FROM " . static::$table . "
              WHERE id = ? AND policy_document_id = ? LIMIT 1",
            'ii', [$id, $documentId]
        );
    }

    /** Flatten a chapter tree into a breadcrumb chain (chapter → … → section). */
    public static function ancestors(array $section): array
    {
        if (empty($section['parent_id'])) {
            return [];
        }
        $chain = [];
        $parentId = (int) $section['parent_id'];
        for ($i = 0; $i < 10 && $parentId > 0; $i++) { // depth guard
            $row = \db()->fetchOne(
                "SELECT id, parent_id, section_number, title
                   FROM " . static::$table . " WHERE id = ? LIMIT 1",
                'i', [$parentId]
            );
            if (!$row) {
                break;
            }
            array_unshift($chain, $row);
            $parentId = (int) ($row['parent_id'] ?? 0);
        }
        return $chain;
    }
}

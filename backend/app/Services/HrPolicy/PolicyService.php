<?php

declare(strict_types=1);

namespace App\Services\HrPolicy;

use App\Models\HrPolicyAcknowledgement;
use App\Models\HrPolicyBookmark;
use App\Models\HrPolicyDocument;
use App\Models\HrPolicySection;
use App\Services\AuditService;
use App\Services\Document\DocumentParser;
use App\Services\NotificationService;

/**
 * PolicyService — business layer for the HR Policy & Procedures module.
 *
 * Owns the publishing workflow (draft → review → published → archived),
 * version control (exactly ONE active policy — DB-guaranteed), audit
 * logging (via the central AuditService — no second audit system),
 * publishing announcements (via the central NotificationService),
 * employee acknowledgement, bookmarks and recently-viewed tracking.
 *
 * Validation of input SHAPE lives in HrPolicyValidator; business rules
 * (status transitions, uniqueness, active-version constraints) live here.
 */
class PolicyService
{
    /** Default HR-approved acknowledgement wording (explicit, non-legal). */
    public const DEFAULT_ACK_MESSAGE =
        'I confirm that I have accessed and read the MUWASCO HR Policy & Procedures Manual.';

    /** Max "recently viewed" entries kept per user. */
    private const RECENT_LIMIT = 8;

    // ------------------------------------------------------------------
    // Administration: upload / metadata / workflow
    // ------------------------------------------------------------------

    /**
     * Create a new policy version from an uploaded file. The document is
     * ALWAYS created as DRAFT — publishing is a separate, explicit action.
     *
     * @return int New document id.
     * @throws \InvalidArgumentException Validation / duplicate errors.
     */
    public static function upload(array $uploadedFile, array $meta, int $userId): int
    {
        $title = trim((string) ($meta['title'] ?? ''));
        $version = trim((string) ($meta['version'] ?? ''));
        if ($title === '' || $version === '') {
            throw new \InvalidArgumentException('Title and version are required.');
        }
        if (mb_strlen($version) > 30) {
            throw new \InvalidArgumentException('Version label must be 30 characters or fewer.');
        }

        $sourceType = (string) ($meta['source_type'] ?? HrPolicyDocument::SOURCE_MANUAL);
        if (!in_array($sourceType, HrPolicyDocument::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid source type.');
        }

        if ((int) \db()->fetchValue(
            "SELECT COUNT(*) FROM hr_policy_documents
              WHERE title = ? AND version = ? AND deleted_at IS NULL",
            'ss', [$title, $version]
        ) > 0) {
            throw new \InvalidArgumentException("Version \"{$version}\" already exists for this title.");
        }

        $stored = PolicyFileService::store($uploadedFile);

        // Get file extension for parsing
        $ext = strtolower(pathinfo($stored['original_name'], PATHINFO_EXTENSION));

        $ackMessage = trim((string) ($meta['acknowledgement_message'] ?? ''));

        $id = \db()->insert('hr_policy_documents', [
            'title'                   => mb_substr($title, 0, 200),
            'description'             => self::nullableText($meta['description'] ?? null, 2000),
            'version'                 => $version,
            'status'                  => HrPolicyDocument::STATUS_DRAFT,
            'source_type'             => $sourceType,
            'effective_date'          => self::nullableDate($meta['effective_date'] ?? null),
            'file_path'               => $stored['relative_path'],
            'file_name'               => $stored['original_name'],
            'stored_name'             => $stored['stored_name'],
            'file_hash'               => $stored['hash'],
            'mime_type'               => $stored['mime_type'],
            'file_size'               => $stored['size'],
            'uploaded_by'             => $userId,
            'is_active'               => 0,
            'acknowledgement_message' => $ackMessage !== ''
                ? mb_substr($ackMessage, 0, 1000)
                : self::DEFAULT_ACK_MESSAGE,
        ]);

        // Parse the document and create sections automatically
        try {
            $absPath = PolicyFileService::absolutePath($stored['relative_path']);
            \logger()->info('Document parsing started', [
                'document_id' => $id,
                'relative_path' => $stored['relative_path'],
                'abs_path' => $absPath,
                'extension' => $ext,
            ]);
            if ($absPath !== null && is_file($absPath)) {
                $parsedSections = DocumentParser::parse($absPath, $ext);
                \logger()->info('Document parsing completed', [
                    'document_id' => $id,
                    'sections_found' => count($parsedSections),
                ]);
                foreach ($parsedSections as $idx => $section) {
                    \db()->insert('hr_policy_sections', [
                        'policy_document_id' => $id,
                        'section_number'     => $section['section_number'],
                        'title'              => mb_substr($section['title'], 0, 200),
                        'content'            => $section['content'],
                        'page_start'         => $section['page_start'],
                        'page_end'           => $section['page_end'],
                        'sort_order'         => $idx + 1,
                    ]);
                }
                // Update section count
                \db()->update('hr_policy_documents',
                    ['section_count' => count($parsedSections)],
                    'id = ?', 'i', [$id]
                );
            } else {
                \logger()->warning('Document file not found for parsing', [
                    'document_id' => $id,
                    'abs_path' => $absPath,
                ]);
            }
        } catch (\Throwable $e) {
            // Parsing failed - log but don't fail the upload
            \logger()->warning('Document parsing failed', [
                'document_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_CREATE,
            "Uploaded HR policy version \"{$title}\" v{$version} (draft)",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'target_name' => "{$title} v{$version}",
                'new_values'  => [
                    'title' => $title, 'version' => $version,
                    'source_type' => $sourceType, 'status' => 'draft',
                    'file_name' => $stored['original_name'], 'size' => $stored['size'],
                ],
            ]
        );

        return $id;
    }

    /**
     * Edit metadata. Published/archived versions are immutable official
     * records — only DRAFT and REVIEW documents may be edited.
     */
    public static function updateMetadata(int $id, array $data, int $userId): void
    {
        $doc = HrPolicyDocument::find($id);
        if (!$doc || $doc['deleted_at'] !== null) {
            throw new \InvalidArgumentException('Policy document not found.');
        }
        if (in_array($doc['status'], [HrPolicyDocument::STATUS_PUBLISHED, HrPolicyDocument::STATUS_ARCHIVED], true)) {
            throw new \InvalidArgumentException(
                'Published or archived policy versions are immutable. Upload a new version instead.'
            );
        }

        $updates = [];
        if (array_key_exists('title', $data) && trim((string) ($data['title'] ?? '')) !== '') {
            $updates['title'] = mb_substr(trim((string) $data['title']), 0, 200);
        }
        if (array_key_exists('description', $data)) {
            $updates['description'] = self::nullableText($data['description'], 2000);
        }
        if (!empty($data['source_type'])) {
            if (!in_array((string) $data['source_type'], HrPolicyDocument::SOURCE_TYPES, true)) {
                throw new \InvalidArgumentException('Invalid source type.');
            }
            $updates['source_type'] = (string) $data['source_type'];
        }
        if (array_key_exists('effective_date', $data)) {
            $updates['effective_date'] = self::nullableDate($data['effective_date']);
        }
        if (array_key_exists('acknowledgement_message', $data)) {
            $msg = trim((string) ($data['acknowledgement_message'] ?? ''));
            $updates['acknowledgement_message'] = $msg !== ''
                ? mb_substr($msg, 0, 1000) : self::DEFAULT_ACK_MESSAGE;
        }

        if (empty($updates)) {
            throw new \InvalidArgumentException('No editable fields supplied.');
        }

        \db()->update('hr_policy_documents', $updates, 'id = ?', 'i', [$id]);

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_UPDATE,
            "Edited HR policy metadata \"{$doc['title']}\" v{$doc['version']}",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'old_values'  => array_intersect_key($doc, $updates),
                'new_values'  => $updates,
            ]
        );
    }

    /** draft ⇄ review transitions (never into published — use publish()). */
    public static function setStatus(int $id, string $status, int $userId): void
    {
        if (!in_array($status, [HrPolicyDocument::STATUS_DRAFT, HrPolicyDocument::STATUS_REVIEW], true)) {
            throw new \InvalidArgumentException('Only draft ⇄ review transitions are allowed here.');
        }

        $doc = HrPolicyDocument::find($id);
        if (!$doc || $doc['deleted_at'] !== null) {
            throw new \InvalidArgumentException('Policy document not found.');
        }
        if (!in_array($doc['status'], [HrPolicyDocument::STATUS_DRAFT, HrPolicyDocument::STATUS_REVIEW], true)) {
            throw new \InvalidArgumentException('This document is no longer editable.');
        }

        \db()->update('hr_policy_documents', ['status' => $status], 'id = ?', 'i', [$id]);

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_STATUS_CHANGE,
            "HR policy \"{$doc['title']}\" v{$doc['version']} moved to {$status}",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'old_values'  => ['status' => $doc['status']],
                'new_values'  => ['status' => $status],
            ]
        );
    }

    /**
     * Publish: the transactional heart of version control.
     *
     * Promotes the document to PUBLISHED + the single ACTIVE version; the
     * previously active version is ARCHIVED in the SAME transaction (the
     * nullable-UNIQUE active_token makes double-activation impossible).
     * Post-commit: audit, AI knowledge mirror, employee announcement.
     */
    public static function publish(int $id, int $userId): void
    {
        $doc = HrPolicyDocument::find($id);
        if (!$doc || $doc['deleted_at'] !== null) {
            throw new \InvalidArgumentException('Policy document not found.');
        }
        if ((int) $doc['is_active'] === 1 && $doc['status'] === HrPolicyDocument::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('This version is already the active policy.');
        }

        \db()->beginTransaction();
        try {
            HrPolicyDocument::activate($id);
            \db()->commit();
        } catch (\Throwable $e) {
            \db()->rollback();
            \logger()->error('Policy publish failed', ['id' => $id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Publishing failed — no changes were made.');
        }

        $fresh = HrPolicyDocument::find($id) ?? $doc;
        $previousArchived = $doc['status'] !== HrPolicyDocument::STATUS_ARCHIVED
            && (int) $doc['is_active'] === 0
            && self::hadPreviousActive($id);

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_PUBLISH,
            "Published HR policy \"{$fresh['title']}\" v{$fresh['version']}" .
            ($previousArchived ? ' (previous active version archived)' : ''),
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'target_name' => "{$fresh['title']} v{$fresh['version']}",
                'old_values'  => ['status' => $doc['status'], 'is_active' => (int) $doc['is_active']],
                'new_values'  => ['status' => 'published', 'is_active' => 1],
            ]
        );

        // Best-effort side effects — never fail the publish itself.
        try {
            self::mirrorToKnowledgeBase($fresh);
            self::announce($fresh);
        } catch (\Throwable $e) {
            \logger()->error('Policy publish side effects failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Whether another (now-archived) active version existed before publish. */
    private static function hadPreviousActive(int $id): bool
    {
        return (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM hr_policy_documents
              WHERE is_active = 0 AND status = 'archived' AND archived_at IS NOT NULL AND id <> ?",
            'i', [$id]
        ) > 0;
    }

    /** Archive a version (removes it as the active policy if it was active). */
    public static function archive(int $id, int $userId): void
    {
        $doc = HrPolicyDocument::find($id);
        if (!$doc || $doc['deleted_at'] !== null) {
            throw new \InvalidArgumentException('Policy document not found.');
        }
        if ($doc['status'] === HrPolicyDocument::STATUS_ARCHIVED && (int) $doc['is_active'] === 0) {
            throw new \InvalidArgumentException('This version is already archived.');
        }

        $wasActive = (int) $doc['is_active'] === 1;

        \db()->beginTransaction();
        try {
            HrPolicyDocument::deactivate($id, date('Y-m-d H:i:s'));
            \db()->commit();
        } catch (\Throwable $e) {
            \db()->rollback();
            \logger()->error('Policy archive failed', ['id' => $id, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Archiving failed — no changes were made.');
        }

        try {
            self::demoteKnowledgeBase($id);
        } catch (\Throwable $e) {
            \logger()->error('Policy archive AI demote failed', ['id' => $id, 'error' => $e->getMessage()]);
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_STATUS_CHANGE,
            ($wasActive ? 'Archived the ACTIVE HR policy ' : 'Archived HR policy version ') .
            "\"{$doc['title']}\" v{$doc['version']}",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'old_values'  => ['status' => $doc['status'], 'is_active' => (int) $doc['is_active']],
                'new_values'  => ['status' => 'archived', 'is_active' => 0],
            ]
        );
    }

    /**
     * Soft delete an old version (never silently — always audited, never the
     * active policy, and the original file is KEPT on disk).
     */
    public static function deleteDocument(int $id, int $userId): void
    {
        $doc = HrPolicyDocument::find($id);
        if (!$doc || $doc['deleted_at'] !== null) {
            throw new \InvalidArgumentException('Policy document not found.');
        }
        if ((int) $doc['is_active'] === 1) {
            throw new \InvalidArgumentException(
                'The active policy cannot be deleted. Archive it first or publish a replacement.'
            );
        }

        \db()->update(
            'hr_policy_documents',
            ['deleted_at' => date('Y-m-d H:i:s'), 'active_token' => null],
            'id = ?', 'i', [$id]
        );

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_DELETE,
            "Soft-deleted HR policy version \"{$doc['title']}\" v{$doc['version']} (file retained on disk)",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => $id,
                'old_values'  => ['status' => $doc['status'], 'deleted_at' => null],
                'new_values'  => ['deleted_at' => $doc['deleted_at'] ?? date('Y-m-d H:i:s')],
            ]
        );
    }

    // ------------------------------------------------------------------
    // Employee-facing: acknowledgement / bookmarks / recently viewed
    // ------------------------------------------------------------------

    /**
     * Record the employee's acknowledgement of the ACTIVE published policy.
     * Idempotent: re-acknowledging keeps the FIRST receipt.
     */
    public static function acknowledge(int $documentId, int $userId, string $ip, string $userAgent): array
    {
        $doc = HrPolicyDocument::findForReader($documentId, false);
        if (!$doc) {
            throw new \InvalidArgumentException('Policy document not found or not published.');
        }

        $employeeId = self::employeeRowId($userId);

        $inserted = HrPolicyAcknowledgement::record(
            $documentId, $userId, $employeeId, $ip, mb_substr($userAgent, 0, 512)
        );

        if ($inserted) {
            AuditService::getInstance()->log(
                AuditService::MODULE_SETTINGS,
                AuditService::ACTION_CONFIRM,
                "Acknowledged HR policy \"{$doc['title']}\" v{$doc['version']}",
                [
                    'target_type' => 'hr_policy_documents',
                    'target_id'   => $documentId,
                    'target_name' => "{$doc['title']} v{$doc['version']}",
                    'metadata'    => ['kind' => 'policy_acknowledgement'],
                ]
            );
        }

        return [
            'acknowledged' => true,
            'first_time'   => $inserted,
            'message'      => (string) ($doc['acknowledgement_message'] ?: self::DEFAULT_ACK_MESSAGE),
        ];
    }

    public static function acknowledgementFor(int $documentId, int $userId): ?array
    {
        return HrPolicyAcknowledgement::findFor($documentId, $userId);
    }

    /** employees.id (internal PK) for the user, or null when none. */
    private static function employeeRowId(int $userId): ?int
    {
        $id = \db()->fetchValue(
            "SELECT e.id FROM employees e
               JOIN users u ON u.employee_id = e.employee_id
              WHERE u.id = ? LIMIT 1",
            'i', [$userId]
        );
        return $id !== null ? (int) $id : null;
    }

    /** Toggle-friendly bookmark add (idempotent). */
    public static function addBookmark(int $userId, int $sectionId): void
    {
        $section = self::publishedSection($sectionId);
        if (!$section) {
            throw new \InvalidArgumentException('Section not found in the published policy.');
        }
        if (!HrPolicyBookmark::isBookmarked($userId, $sectionId)) {
            HrPolicyBookmark::add($userId, $sectionId);
        }
    }

    public static function removeBookmark(int $userId, int $sectionId): void
    {
        HrPolicyBookmark::remove($userId, $sectionId);
    }

    public static function bookmarksFor(int $userId): array
    {
        return HrPolicyBookmark::forUser($userId);
    }

    /** Record a section view + prune the per-user recent list. */
    public static function trackView(int $userId, int $sectionId): void
    {
        try {
            $existing = \db()->fetchValue(
                "SELECT id FROM hr_policy_recent_views WHERE user_id = ? AND section_id = ?",
                'ii', [$userId, $sectionId]
            );
            if ($existing !== null) {
                \db()->update(
                    'hr_policy_recent_views',
                    ['viewed_at' => date('Y-m-d H:i:s')],
                    'id = ?', 'i', [(int) $existing]
                );
            } else {
                \db()->insert('hr_policy_recent_views', [
                    'user_id' => $userId, 'section_id' => $sectionId,
                    'viewed_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // Prune beyond RECENT_LIMIT most-recent entries.
            \db()->query(
                "DELETE r FROM hr_policy_recent_views r
                  JOIN (
                        SELECT id FROM hr_policy_recent_views
                         WHERE user_id = ?
                         ORDER BY viewed_at DESC, id DESC
                         LIMIT 18446744073709551615 OFFSET ?
                       ) keep ON keep.id = r.id",
                'ii', [$userId, self::RECENT_LIMIT]
            );
        } catch (\Throwable $e) {
            // Tracking is best-effort — never block reads.
            \logger()->error('Policy view tracking failed', ['error' => $e->getMessage()]);
        }
    }

    public static function recentFor(int $userId, int $limit = self::RECENT_LIMIT): array
    {
        return \db()->fetchAll(
            "SELECT r.section_id, r.viewed_at,
                    s.section_number, s.title, s.page_start,
                    d.id AS document_id, d.title AS document_title
               FROM hr_policy_recent_views r
               JOIN hr_policy_sections s ON s.id = r.section_id
               JOIN hr_policy_documents d ON d.id = s.policy_document_id
              WHERE r.user_id = ? AND d.status = 'published' AND d.deleted_at IS NULL
              ORDER BY r.viewed_at DESC, r.id DESC
              LIMIT " . max(1, min(self::RECENT_LIMIT, $limit)),
            'i', [$userId]
        );
    }

    /** Section readable by an employee (published document only — IDOR-safe). */
    public static function publishedSection(int $sectionId): ?array
    {
        return \db()->fetchOne(
            "SELECT s.* FROM hr_policy_sections s
               JOIN hr_policy_documents d ON d.id = s.policy_document_id
              WHERE s.id = ? AND d.status = 'published' AND d.deleted_at IS NULL
              LIMIT 1",
            'i', [$sectionId]
        );
    }

    // ------------------------------------------------------------------
    // AI knowledge-base mirror (publish/archive side effects)
    // ------------------------------------------------------------------

    /**
     * Mirror the published policy into the AI knowledge store (migration 041
     * tables) so the HR AI assistant retrieves the APPROVED policy text —
     * never invented content. Best-effort: never breaks publishing.
     */
    private static function mirrorToKnowledgeBase(array $doc): void
    {
        $existing = \db()->fetchOne(
            "SELECT id FROM ai_knowledge_documents
              WHERE file_hash = ? AND version = ? LIMIT 1",
            'ss', [$doc['file_hash'], mb_substr((string) $doc['version'], 0, 20)]
        );

        if ($existing) {
            $kbDocId = (int) $existing['id'];
            \db()->update('ai_knowledge_documents', [
                'title'      => mb_substr((string) $doc['title'], 0, 200),
                'status'     => 'active',
                'file_path'  => (string) $doc['file_path'],
                'mime_type'  => (string) $doc['mime_type'],
            ], 'id = ?', 'i', [$kbDocId]);
        } else {
            $kbDocId = \db()->insert('ai_knowledge_documents', [
                'title'      => mb_substr((string) $doc['title'], 0, 200),
                'doc_type'   => 'policy',
                'version'    => mb_substr((string) $doc['version'], 0, 20),
                'file_path'  => (string) $doc['file_path'],
                'file_hash'  => (string) $doc['file_hash'],
                'mime_type'  => (string) $doc['mime_type'],
                'status'     => 'active',
                'uploaded_by' => (int) $doc['uploaded_by'],
            ]);
        }

        // Only ONE policy mirror should be active — demote the others.
        \db()->query(
            "UPDATE ai_knowledge_documents SET status = 'archived'
              WHERE doc_type = 'policy' AND id <> ?",
            'i', [$kbDocId]
        );

        // Replace chunks with the structured sections (plain text, no markup).
        \db()->query("DELETE FROM ai_knowledge_chunks WHERE document_id = ?", 'i', [$kbDocId]);

        $sections = \db()->fetchAll(
            "SELECT section_number, title, content
               FROM hr_policy_sections
              WHERE policy_document_id = ?
              ORDER BY sort_order ASC, id ASC",
            'i', [(int) $doc['id']]
        );

        $index = 0;
        foreach ($sections as $s) {
            $label = trim((string) ($s['section_number'] ?? '')) !== ''
                ? 'Section ' . $s['section_number'] . ' — ' : '';
            $text = trim($label . (string) $s['title'] . "\n\n" . strip_tags((string) ($s['content'] ?? '')));
            if ($text === '') {
                continue;
            }
            $text = mb_substr($text, 0, 60000);
            \db()->query(
                "INSERT INTO ai_knowledge_chunks (document_id, chunk_index, content, token_count)
                 VALUES (?, ?, ?, ?)",
                'iisi', [$kbDocId, $index++, $text, str_word_count($text)]
            );
        }

        \logger()->info('Policy mirrored to AI knowledge base', [
            'policy_id' => $doc['id'], 'kb_document_id' => $kbDocId, 'chunks' => $index,
        ]);
    }

    /** Demote (archive) the knowledge mirror of an archived policy. */
    private static function demoteKnowledgeBase(int $policyId): void
    {
        $doc = \db()->fetchOne(
            "SELECT file_hash, version FROM hr_policy_documents WHERE id = ?",
            'i', [$policyId]
        );
        if (!$doc) {
            return;
        }
        \db()->query(
            "UPDATE ai_knowledge_documents SET status = 'archived'
              WHERE file_hash = ? AND version = ? AND doc_type = 'policy'",
            'ss', [$doc['file_hash'], mb_substr((string) $doc['version'], 0, 20)]
        );
    }

    // ------------------------------------------------------------------
    // Announcement + small helpers
    // ------------------------------------------------------------------

    /**
     * "New HR Policy Manual Published" announcement — reuses the central
     * NotificationService (in-app notifications). NO second notification
     * architecture is created. Best-effort and batched.
     */
    private static function announce(array $doc): void
    {
        $title = 'New HR Policy & Procedures Manual Published';
        $message = sprintf(
            '%s (version %s) is now the official HR reference. Open HR Policies to read it.',
            (string) $doc['title'],
            (string) $doc['version']
        );
        $link = '/hr/policies';

        $userIds = \db()->fetchAll("SELECT id FROM users WHERE is_active = 1");
        $count = 0;
        foreach ($userIds as $u) {
            try {
                NotificationService::getInstance()->sendInApp(
                    (int) $u['id'], $title, $message, 'info', $link
                );
                $count++;
            } catch (\Throwable $e) {
                \logger()->error('Policy announcement failed for user', [
                    'user_id' => $u['id'], 'error' => $e->getMessage(),
                ]);
            }
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_CREATE,
            "Announced publication of HR policy v{$doc['version']} to {$count} active users",
            [
                'target_type' => 'hr_policy_documents',
                'target_id'   => (int) $doc['id'],
                'metadata'    => ['recipients' => $count, 'kind' => 'policy_publication'],
            ]
        );
    }

    private static function nullableText(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, $maxLen);
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $date = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false) {
            throw new \InvalidArgumentException('Effective date must be a valid YYYY-MM-DD date.');
        }
        return $date;
    }

    /** Best-effort client IP (proxy-aware), for acknowledgement records. */
    public static function clientIp(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        foreach ($candidates as $header) {
            foreach (explode(',', (string) $header) as $ip) {
                $ip = trim($ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}

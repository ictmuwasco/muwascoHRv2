<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Models\HrPolicyDocument;
use App\Models\HrPolicySection;
use App\Services\HrPolicy\HrPolicySearchService;
use App\Services\HrPolicy\PolicyFileService;
use App\Services\HrPolicy\PolicyService;

/**
 * HrPolicyController — employee-facing HR Policy & Procedures endpoints.
 *
 * READ-ONLY surface: employees can view/search/read PUBLISHED policies,
 * acknowledge them, bookmark sections and see their recently viewed list.
 * All admin actions (upload/publish/archive/delete) live in
 * HrPolicyAdminController and require hr_policies:manage / :publish.
 *
 * IDOR safety: every fetch is scoped server-side to published documents for
 * non-managers — foreign or draft ids resolve to a clean 404, never a leak.
 */
class HrPolicyController extends BaseController
{
    /** True when the current user may see unpublished versions (HR admin). */
    private function isPolicyManager(): bool
    {
        return $this->hasPermission('hr_policies', 'manage');
    }

    /**
     * GET /api/hr-policies/current — the active official policy + reader context.
     * Powers the dashboard card (version, effective date, section count) and
     * the reader landing page.
     */
    public function currentAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $doc = HrPolicyDocument::findActive();
            if (!$doc) {
                $this->success(['policy' => null], 'No active policy published yet');
            }

            $ack = PolicyService::acknowledgementFor((int) $doc['id'], $userId);

            $this->success([
                'policy' => [
                    'id'               => (int) $doc['id'],
                    'title'            => $doc['title'],
                    'version'          => $doc['version'],
                    'source_type'      => $doc['source_type'],
                    'effective_date'   => $doc['effective_date'],
                    'published_at'     => $doc['published_at'],
                    'section_count'    => (int) $doc['section_count'],
                    'page_count'       => $doc['page_count'] !== null ? (int) $doc['page_count'] : null,
                    'file_name'        => $doc['file_name'],
                    'mime_type'        => $doc['mime_type'],
                    'acknowledgement_message' => $doc['acknowledgement_message']
                        ?: PolicyService::DEFAULT_ACK_MESSAGE,
                ],
                'acknowledged_at' => $ack['acknowledged_at'] ?? null,
                'bookmarks'       => PolicyService::bookmarksFor($userId),
                'recent'          => PolicyService::recentFor($userId),
            ]);
        } catch (\Exception $e) {
            \logger()->error('Policy current error', ['error' => $e->getMessage()]);
            $this->error('Failed to load the current policy. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies — published policies (employee view).
     */
    public function indexAction(): void
    {
        try {
            $this->success(['items' => HrPolicyDocument::published()]);
        } catch (\Exception $e) {
            \logger()->error('Policy list error', ['error' => $e->getMessage()]);
            $this->error('Failed to list policies. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/search?q= — server-side policy search.
     */
    public function searchAction(): void
    {
        try {
            $q = trim((string) ($_GET['q'] ?? ''));
            $limit = max(1, min(25, (int) ($_GET['limit'] ?? 25)));
            $this->success([
                'query' => $q,
                'items' => $q === '' ? [] : HrPolicySearchService::search($q, $limit),
            ]);
        } catch (\Exception $e) {
            \logger()->error('Policy search error', ['error' => $e->getMessage()]);
            $this->error('Policy search failed. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/bookmarks — the user's bookmarked sections.
     */
    public function bookmarksAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }
        try {
            $this->success(['items' => PolicyService::bookmarksFor($userId)]);
        } catch (\Exception $e) {
            \logger()->error('Policy bookmarks error', ['error' => $e->getMessage()]);
            $this->error('Failed to load bookmarks. Please try again.', 500);
        }
    }

    /** POST /api/hr-policies/bookmarks  body: { section_id } */
    public function addBookmarkAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }
        $data = $this->getJsonBody();
        $sectionId = (int) ($data['section_id'] ?? 0);
        if ($sectionId <= 0) {
            $this->error('section_id is required.', 422, 'VALIDATION_ERROR');
        }
        try {
            PolicyService::addBookmark($userId, $sectionId);
            $this->success(['bookmarked' => true, 'section_id' => $sectionId], 'Section bookmarked');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy bookmark add error', ['error' => $e->getMessage()]);
            $this->error('Failed to bookmark the section. Please try again.', 500);
        }
    }

    /** DELETE /api/hr-policies/bookmarks/{sectionId} */
    public function removeBookmarkAction(int $sectionId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }
        try {
            PolicyService::removeBookmark($userId, $sectionId);
            $this->success(['bookmarked' => false, 'section_id' => $sectionId], 'Bookmark removed');
        } catch (\Exception $e) {
            \logger()->error('Policy bookmark remove error', ['error' => $e->getMessage()]);
            $this->error('Failed to remove the bookmark. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/recent — the user's recently viewed sections.
     */
    public function recentAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }
        try {
            $this->success(['items' => PolicyService::recentFor($userId)]);
        } catch (\Exception $e) {
            \logger()->error('Policy recent error', ['error' => $e->getMessage()]);
            $this->error('Failed to load recent sections. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/sections/{id} — full section content + breadcrumbs.
     * Employees: published documents only. Records a "recently viewed" entry.
     */
    public function sectionAction(int $id): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $isManager = $this->isPolicyManager();
            $section = $isManager
                ? HrPolicySection::find($id)
                : PolicyService::publishedSection($id);

            if (!$section) {
                $this->notFound('Policy section not found');
            }

            $doc = HrPolicyDocument::findForReader((int) $section['policy_document_id'], $isManager);
            if (!$doc) {
                $this->notFound('Policy section not found');
            }

            if (!$isManager) {
                PolicyService::trackView($userId, $id);
            }

            $this->success([
                'section' => [
                    'id'               => (int) $section['id'],
                    'document_id'      => (int) $section['policy_document_id'],
                    'parent_id'        => $section['parent_id'] !== null ? (int) $section['parent_id'] : null,
                    'section_number'   => $section['section_number'],
                    'title'            => $section['title'],
                    'content'          => $section['content'],
                    'page_start'       => $section['page_start'] !== null ? (int) $section['page_start'] : null,
                    'page_end'         => $section['page_end'] !== null ? (int) $section['page_end'] : null,
                ],
                'breadcrumbs' => HrPolicySection::ancestors($section),
                'document'    => [
                    'id'      => (int) $doc['id'],
                    'title'   => $doc['title'],
                    'version' => $doc['version'],
                    'status'  => $doc['status'],
                ],
                'bookmarked' => !$isManager && \App\Models\HrPolicyBookmark::isBookmarked($userId, $id),
            ]);
        } catch (\Exception $e) {
            \logger()->error('Policy section error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load the policy section. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/{id} — document detail (published for employees;
     * any status for policy managers). Registered AFTER static paths.
     */
    public function showAction(int $id): void
    {
        try {
            $isManager = $this->isPolicyManager();
            $doc = HrPolicyDocument::findForReader($id, $isManager);
            if (!$doc) {
                $this->notFound('Policy document not found');
            }
            $this->success(['policy' => $doc]);
        } catch (\Exception $e) {
            \logger()->error('Policy detail error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load the policy. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/{id}/sections — section tree (metadata only).
     */
    public function sectionsAction(int $id): void
    {
        try {
            $isManager = $this->isPolicyManager();
            $doc = HrPolicyDocument::findForReader($id, $isManager);
            if (!$doc) {
                $this->notFound('Policy document not found');
            }
            $this->success(['items' => HrPolicySection::tree($id)]);
        } catch (\Exception $e) {
            \logger()->error('Policy sections error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load the policy sections. Please try again.', 500);
        }
    }

    /**
     * GET /api/hr-policies/{id}/file?download=1 — stream the original manual
     * from PRIVATE storage (never the webroot). inline for in-browser reading,
     * attachment for download. Managers may stream unpublished versions.
     */
    public function fileAction(int $id): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $isManager = $this->isPolicyManager();
            $doc = HrPolicyDocument::findForReader($id, $isManager);
            if (!$doc) {
                $this->notFound('Policy document not found');
            }

            $abs = PolicyFileService::absolutePath((string) $doc['file_path']);
            if ($abs === null || !is_file($abs)) {
                $this->notFound('Policy file not found on server');
            }

            $mime = (string) ($doc['mime_type'] ?: 'application/octet-stream');
            $download = !empty($_GET['download']);

            // Sandboxed CSP + nosniff + no-store; inline/attachment per intent.
            \App\Middleware\SecurityMiddleware::applyStreamHeaders(
                $mime,
                (string) ($doc['file_name'] ?: 'policy-manual'),
                $download
            );
            header('Content-Length: ' . filesize($abs));

            readfile($abs);
            exit();
        } catch (\Exception $e) {
            \logger()->error('Policy file stream error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to stream the policy file. Please try again.', 500);
        }
    }

    /**
     * POST /api/hr-policies/{id}/acknowledge — record the employee's receipt.
     * Wording is explicit and non-legal ("accessed and read").
     */
    public function acknowledgeAction(int $id): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $result = PolicyService::acknowledge(
                $id,
                $userId,
                PolicyService::clientIp(),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
            $this->success($result, 'Acknowledgement recorded');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy acknowledge error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to record the acknowledgement. Please try again.', 500);
        }
    }
}

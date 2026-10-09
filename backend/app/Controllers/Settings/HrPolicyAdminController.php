<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Models\HrPolicyDocument;
use App\Services\HrPolicy\PolicyFileService;
use App\Services\HrPolicy\PolicyService;
use App\Validators\HrPolicyValidator;

/**
 * HrPolicyAdminController — HR administration for the policy module
 * (/settings/hr-policies). Requires hr_policies:manage (upload/edit/archive/
 * delete/history/acknowledgements) or hr_policies:publish (publishing) —
 * enforced by the route permission gate BEFORE the controller runs, and
 * re-checked here for defence in depth.
 *
 * Every mutating action is audited through the central AuditService.
 * Publishing follows the strict workflow: upload (DRAFT) → review → publish
 * → previous active version archived. Section CONTENT is never editable via
 * the API — official text changes only through re-ingestion of a new version.
 */
class HrPolicyAdminController extends BaseController
{
    /**
     * GET /api/settings/hr-policies — all versions (HR list + version history).
     */
    public function indexAction(): void
    {
        $this->requirePermission('hr_policies', 'manage');
        try {
            $this->success(['items' => HrPolicyDocument::allVersions()]);
        } catch (\Exception $e) {
            \logger()->error('Policy admin list error', ['error' => $e->getMessage()]);
            $this->error('Failed to list policy versions. Please try again.', 500);
        }
    }

    /**
     * POST /api/settings/hr-policies — upload a new version (multipart).
     * Fields: file (pdf/docx), title, version, source_type?,
     *         description?, effective_date?, acknowledgement_message?
     * Legacy .doc is rejected with Save-As guidance (it can never be parsed
     * into sections). The new version is ALWAYS created as DRAFT.
     */
    public function storeAction(): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            // post_max_size overflow: PHP empties BOTH $_POST and $_FILES, so
            // without this the user gets a misleading 'title required' or
            // 'upload error' message for what is really an oversized request.
            $truncated = PolicyFileService::truncatedPostMessage();
            if ($truncated !== null) {
                $this->error($truncated, 400);
            }

            $data = $this->validateRequest(new HrPolicyValidator(), [
                'title'                  => trim((string) ($_POST['title'] ?? '')),
                'version'                => trim((string) ($_POST['version'] ?? '')),
                'description'            => $_POST['description'] ?? null,
                'source_type'            => $_POST['source_type'] ?? null,
                'effective_date'         => $_POST['effective_date'] ?? null,
                'acknowledgement_message' => $_POST['acknowledgement_message'] ?? null,
            ]);

            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                $this->error('A policy file (PDF or DOCX) is required. For legacy Word documents, use "Save As" -> .docx or PDF first.', 422, 'VALIDATION_ERROR');
            }

            $id = PolicyService::upload($file, $data, $userId);

            // Kick the CLI worker so sections appear within seconds rather than
            // at the next cron tick. Best-effort: if spawning fails (exec
            // disabled, restricted host) the row simply stays 'pending' and the
            // scheduled worker picks it up. A spawn failure must NEVER turn a
            // successful upload into an error - that is the class of bug this
            // whole change exists to remove.
            self::spawnParseWorker($id);

            $this->success(
                [
                    'id'           => $id,
                    'parse_status' => HrPolicyDocument::PARSE_PENDING,
                ],
                'Policy version uploaded as draft. Sections are being extracted in the background.',
                201
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            // \Throwable, not \Exception — parser failures can surface as
            // \Error (e.g. a missing dependency class) and must not escape to
            // the global handler leaving the caller without a reason.
            // class + file:line are logged because the user-facing message is
            // deliberately generic: on production the ONLY way to tell a
            // storage failure (unwritable policies/ dir) from a schema failure
            // (missing migration) from a missing class is this line. The log is
            // sealed at rest — read it with scripts/storage/read_log.php.
            \logger()->error('Policy upload error', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            $this->error('Failed to upload the policy. Please try again.', 500);
        }
    }

    /**
     * Spawn the CLI section-extraction worker for a freshly stored document.
     *
     * Best-effort and non-blocking: the worker is detached (output to the null
     * device) so the HTTP response is not held open by a multi-minute parse.
     * Any failure is swallowed on purpose - the scheduled cron worker is the
     * real guarantee, and this is only a latency optimisation.
     */
    private static function spawnParseWorker(int $id): void
    {
        $worker = dirname(__DIR__, 3) . '/cron/policy_parse_worker.php';
        if (!is_file($worker) || !function_exists('proc_open')) {
            return;
        }

        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

        // Windows needs the whole thing wrapped for start /B; POSIX needs the
        // trailing & to detach. Both redirect stdout/stderr so nothing leaks
        // into the response body.
        $isWindows = stripos(PHP_OS_FAMILY, 'win') === 0;
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker)
            . ' ' . escapeshellarg('--id=' . $id) . ' --quiet'
            . ($isWindows ? '' : ' > /dev/null 2>&1 &');

        try {
            if ($isWindows) {
                @pclose(@popen('start /B ' . $cmd, 'r'));
            } else {
                @exec($cmd);
            }
        } catch (\Throwable $e) {
            \logger()->warning('Policy parse worker spawn failed', [
                'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/settings/hr-policies/{id}/reparse — retry section extraction.
     *
     * The recovery path for a document whose parse failed (encrypted PDF,
     * image-only scan, a file that legitimately could not be read). HR sees the
     * reason in the list and can retry after replacing the file.
     */
    public function reparseAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $result = PolicyService::retryExtraction($id, $userId);

            if ($result['status'] === HrPolicyDocument::PARSE_DONE) {
                $this->success(
                    ['id' => $id, 'parse_status' => $result['status'], 'sections' => $result['sections']],
                    "Extracted {$result['sections']} sections."
                );
                return;
            }

            // Extraction genuinely failed. The row now records why, so this is
            // a real answer, not a server error: 422 with the reason attached.
            $this->error((string) $result['error'], 422, 'PARSE_FAILED', [
                'id' => $id,
                'parse_status' => $result['status'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy reparse error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to re-process the policy. Please try again.', 500);
        }
    }

    /**
     * PUT /api/settings/hr-policies/{id} — edit metadata (draft/review only).
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            $data = $this->validateRequest(new HrPolicyValidator(), $this->getJsonBody());
            PolicyService::updateMetadata($id, $data, $userId);
            $this->success(['id' => $id], 'Policy metadata updated');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy metadata update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update the policy. Please try again.', 500);
        }
    }

    /**
     * POST /api/settings/hr-policies/{id}/status  body: { status: draft|review }
     * Moves a document along the pre-publish workflow.
     */
    public function setStatusAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        $data = $this->getJsonBody();
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['draft', 'review'], true)) {
            $this->error('Status must be draft or review. Publishing uses /publish.', 422, 'VALIDATION_ERROR');
        }

        try {
            PolicyService::setStatus($id, $status, $userId);
            $this->success(['id' => $id, 'status' => $status], 'Policy status updated');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy status change error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update the policy status. Please try again.', 500);
        }
    }

    /**
     * POST /api/settings/hr-policies/{id}/publish — publish workflow.
     * Archives the previous active version in the same transaction, mirrors
     * the policy to the AI knowledge base and announces it to all employees.
     */
    public function publishAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'publish');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            PolicyService::publish($id, $userId);
            $doc = HrPolicyDocument::find($id);
            $this->success([
                'id'      => $id,
                'status'  => $doc['status'] ?? 'published',
                'message' => (string) ($doc['acknowledgement_message'] ?? PolicyService::DEFAULT_ACK_MESSAGE),
            ], 'Policy published — previous version archived');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy publish error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to publish the policy. Please try again.', 500);
        }
    }

    /**
     * POST /api/settings/hr-policies/{id}/archive — archive a version.
     */
    public function archiveAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            PolicyService::archive($id, $userId);
            $this->success(['id' => $id, 'status' => 'archived'], 'Policy version archived');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy archive error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to archive the policy. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/settings/hr-policies/{id} — SOFT delete of an old version
     * (never the active policy; the original file is retained on disk).
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
            PolicyService::deleteDocument($id, $userId);
            $this->success(['id' => $id], 'Policy version removed (soft delete, file retained)');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy delete error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to remove the policy version. Please try again.', 500);
        }
    }

    /**
     * GET /api/settings/hr-policies/{id}/history — versions + audit trail
     * for this document (integrates with the EXISTING audit_logs table).
     */
    public function historyAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        try {
            $doc = HrPolicyDocument::find($id);
            if (!$doc || $doc['deleted_at'] !== null) {
                $this->notFound('Policy document not found');
            }

            $audit = \db()->fetchAll(
                "SELECT a.id, a.action, a.description, a.status, a.user_name_snapshot,
                        a.user_role_snapshot, a.old_values, a.new_values, a.created_at
                   FROM audit_logs a
                  WHERE a.target_type = 'hr_policy_documents' AND a.target_id = ?
                  ORDER BY a.created_at DESC
                  LIMIT 100",
                'i', [$id]
            );

            $this->success([
                'document' => $doc,
                'versions' => HrPolicyDocument::historyForTitle((string) $doc['title']),
                'audit'    => $audit,
            ]);
        } catch (\Exception $e) {
            \logger()->error('Policy history error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load the policy history. Please try again.', 500);
        }
    }

    /**
     * GET /api/settings/hr-policies/{id}/acknowledgements — compliance view.
     */
    public function acknowledgementsAction(int $id): void
    {
        $this->requirePermission('hr_policies', 'manage');
        try {
            $doc = HrPolicyDocument::find($id);
            if (!$doc || $doc['deleted_at'] !== null) {
                $this->notFound('Policy document not found');
            }

            $items = \App\Models\HrPolicyAcknowledgement::forDocument($id);
            $totalUsers = (int) \db()->fetchValue(
                "SELECT COUNT(*) FROM users WHERE is_active = 1"
            );

            $this->success([
                'document'     => ['id' => (int) $doc['id'], 'title' => $doc['title'], 'version' => $doc['version']],
                'items'        => $items,
                'total_acks'   => count($items),
                'active_users' => $totalUsers,
            ]);
        } catch (\Exception $e) {
            \logger()->error('Policy acknowledgements error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load acknowledgements. Please try again.', 500);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Settings;

use App\Controllers\BaseController;
use App\Models\HrPolicyDocument;
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
     * Fields: file (pdf/doc/docx), title, version, source_type?,
     *         description?, effective_date?, acknowledgement_message?
     * The new version is ALWAYS created as DRAFT — never auto-published.
     */
    public function storeAction(): void
    {
        $this->requirePermission('hr_policies', 'manage');
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
        }

        try {
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
                $this->error('A policy file (PDF, DOC or DOCX) is required.', 422, 'VALIDATION_ERROR');
            }

            $id = PolicyService::upload($file, $data, $userId);

            $this->success(['id' => $id], 'Policy version uploaded as draft', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Policy upload error', ['error' => $e->getMessage()]);
            $this->error('Failed to upload the policy. Please try again.', 500);
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

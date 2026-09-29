<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Services\Appraisal\AppraisalWorkflowService;

/** HTTP adapter for the role-scoped performance appraisal workflow. */
class AppraisalWorkflowController extends BaseController
{
    private AppraisalWorkflowService $workflow;

    public function __construct()
    {
        $this->workflow = new AppraisalWorkflowService();
    }

    public function workspaceAction(): void
    {
        $this->requirePermission('performance', 'supervise');
        $this->respond(fn () => $this->workflow->workspace());
    }

    public function regularListAction(): void
    {
        $this->requirePermission('performance', 'supervise');
        $this->respond(fn () => $this->workflow->list('regular'));
    }

    /**
     * Review queues each get their own action.
     *
     * These deliberately take NO queue-name parameter: the router binds URI
     * placeholders positionally, so a `string $tab` argument would either be
     * fed NULL (fatal under strict_types) or, on the detail routes, silently
     * swapped with the appraisal id. Each route now maps to a method whose only
     * parameter is the id it actually captures.
     */
    public function pendingListAction(): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->list('pending'));
    }

    public function escalatedListAction(): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->list('escalated'));
    }

    public function rejectedListAction(): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->list('rejected'));
    }

    public function regularDetailAction(int $id): void
    {
        $this->requirePermission('performance', 'supervise');
        $this->respond(fn () => $this->workflow->detail($id, 'regular'));
    }

    public function pendingDetailAction(int $id): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->detail($id, 'pending'));
    }

    public function escalatedDetailAction(int $id): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->detail($id, 'escalated'));
    }

    public function rejectedDetailAction(int $id): void
    {
        $this->requirePermission('performance', 'approve');
        $this->respond(fn () => $this->workflow->detail($id, 'rejected'));
    }

    public function createAction(): void
    {
        $this->requirePermission('performance', 'score');
        $data = $this->getJsonBody();
        $this->respond(fn () => ['id' => $this->workflow->create($data)], 'Appraisal created successfully.', 201);
    }

    public function saveScoresAction(int $id): void
    {
        $this->requirePermission('performance', 'score');
        $data = $this->getJsonBody();
        $this->respond(function () use ($id, $data) {
            $this->workflow->saveScores($id, $data);
            return null;
        }, 'Scores saved successfully.');
    }

    public function myAction(): void
    {
        $this->requirePermission('performance', 'feedback');
        $this->respond(fn () => $this->workflow->myAppraisals());
    }

    public function myDetailAction(int $id): void
    {
        $this->requirePermission('performance', 'feedback');
        $this->respond(fn () => $this->workflow->myDetail($id));
    }

    public function feedbackAction(int $id): void
    {
        $this->requirePermission('performance', 'feedback');
        $data = $this->getJsonBody();
        $this->respond(function () use ($id, $data) {
            $this->workflow->submitEmployeeFeedback($id, $data);
            return null;
        }, 'Feedback submitted successfully.');
    }

    public function decisionAction(int $id): void
    {
        $this->requirePermission('performance', 'approve');
        $data = $this->getJsonBody();
        $this->respond(function () use ($id, $data) {
            $this->workflow->decide($id, $data);
            return null;
        }, 'Appraisal decision recorded successfully.');
    }

    /** @param callable():mixed $operation */
    private function respond(callable $operation, string $message = 'Success', int $status = 200): void
    {
        try {
            $this->success($operation(), $message, $status);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\DomainException $e) {
            $this->notFound($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 409, 'APPRAISAL_CONFLICT');
        } catch (\Throwable $e) {
            \logger()->error('Performance appraisal workflow failed', ['error' => $e->getMessage()]);
            $this->error('The appraisal operation could not be completed.', 500, 'APPRAISAL_OPERATION_FAILED');
        }
    }
}

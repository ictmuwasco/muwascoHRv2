<?php

declare(strict_types=1);

namespace App\Controllers\Employee;

use App\Controllers\BaseController;

use App\Services\Contracts\EmployeeServiceInterface;
use App\Services\EmployeeService;
use App\Services\Security\EmployeePolicy;
use App\Services\Security\SecurityEventService;

/**
 * Employee Controller - REST API for employee management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to EmployeeService.
 */
class EmployeeController extends BaseController
{
    private EmployeeServiceInterface $employeeService;

    /**
     * Constructor with dependency injection.
     * The DI container automatically resolves EmployeeServiceInterface.
     */
    public function __construct(EmployeeServiceInterface $employeeService)
    {
        $this->employeeService = $employeeService;
    }

    /**
     * GET /api/employees - List employees with pagination and filters.
     */
    public function indexAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $filters = $this->getFilters();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            
            $result = $this->employeeService->getAllEmployees($filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id} - Get a single employee.
     */
        public function showAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            // OBJECT-LEVEL AUTHORIZATION (IDOR/BOLA protection)
            // The permission check above verifies the user CAN view employees,
            // but this check verifies they can view THIS specific employee.
            if (!\App\Services\Security\EmployeePolicy::canView($this->getAuthUserId(), $employee)) {
                // Log the security event before denying
                \App\Services\Security\SecurityEventService::getInstance()->record(
                    \App\Services\Security\SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS,
                    \App\Services\Security\SecurityEventService::SEVERITY_HIGH,
                    65,
                    [
                        'user_id' => $this->getAuthUserId(),
                        'resource_type' => 'employee',
                        'resource_id' => $id,
                        'response_status' => 403,
                        'action_taken' => \App\Services\Security\SecurityEventService::ACTION_DENIED,
                        'description' => "Unauthorized access attempt to employee#{$id}",
                        'route' => $_SERVER['REQUEST_URI'] ?? null,
                    ]
                );
                $this->forbidden('You are not authorized to view this employee');
            }

            // DOCUMENT METADATA REDACTION (migration 106)
            //
            // The repository attaches every document's NAME and CATEGORY to the
            // employee payload. Those are themselves sensitive - "Certified
            // Certificate SPU.pdf / undergraduate" discloses a qualification, and
            // a national-ID filename discloses that they have one - so gating
            // only the file bytes would leave the more telling part readable.
            //
            // The owner always sees their own list. Anyone else sees placeholders
            // unless they hold a live, verified OTP approval. Hiding this in the
            // frontend would be cosmetic: the data has already left the server.
            $this->redactDocumentsForViewer($employee);

            $this->success($employee);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve employee. Please try again.', 500);
        }
    }

    /**
     * Replace an employee's document list with placeholders unless the viewer is
     * the owner or holds a live, verified approval.
     *
     * Mutates $employee in place and records the resolved state under
     * `documents_access` so the UI can render a "Request access" affordance
     * without a second round trip.
     *
     * @param array<string,mixed> $employee
     */
    private function redactDocumentsForViewer(array &$employee): void
    {
        $documents = is_array($employee['documents'] ?? null) ? $employee['documents'] : [];
        $employeeId = (int) ($employee['id'] ?? 0);
        $viewerId = $this->getAuthUserId();

        if ($documents === []) {
            $employee['documents'] = [];
            $employee['documents_access'] = 'none';
            return;
        }

        // Owner: always full access, no approval needed.
        if ($employeeIdForViewer = $this->viewerOwnEmployeeId()) {
            if ($employeeIdForViewer === $employeeId) {
                $employee['documents_access'] = 'owner';
                return;
            }
        }

        // Non-owner with a verified, unspent, unexpired approval for at least one
        // of these documents: the real list is legitimate at this point, and each
        // individual open still consumes its own approval.
        try {
            $service = new \App\Services\Security\DocumentAccessService();
            if ($this->hasAnyVerifiedApproval($service, $documents, $viewerId)) {
                $employee['documents_access'] = 'granted';
                return;
            }
        } catch (\Throwable $e) {
            // Fail CLOSED: if the check cannot be performed, treat the viewer as
            // unverified rather than falling back to the full list.
            \logger()->error('Document access check failed; redacting', [
                'error' => $e->getMessage(),
                'employee_id' => $employeeId,
            ]);
        }

        // Resolved inline rather than injected: EmployeeServiceInterface has no
        // redaction method, and widening the application's service contract to
        // expose one presentational helper would be the wrong trade for a single
        // call site. Instantiated lazily so the cost is only paid when someone
        // actually hits the locked path.
        $repository = new \App\Repositories\EmployeeRepository();
        $employee['documents'] = $repository->redactDocumentsFor($employeeId);
        $employee['documents_access'] = 'locked';
    }

    /**
     * The caller's own employees.id, or null.
     */
    private function viewerOwnEmployeeId(): ?int
    {
        try {
            $me = $this->employeeService->getEmployeeByUserId($this->getAuthUserId());
            $id = $me ? (int) ($me['id'] ?? 0) : 0;
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Does the viewer hold a live approval for ANY of these documents?
     *
     * @param array<int,array<string,mixed>> $documents
     */
    private function hasAnyVerifiedApproval(
        \App\Services\Security\DocumentAccessService $service,
        array $documents,
        int $viewerId
    ): bool {
        foreach ($documents as $doc) {
            $docId = (int) ($doc['id'] ?? 0);
            if ($docId > 0 && $service->hasLiveApproval($docId, $viewerId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * POST /api/employees - Create a new employee.
     */
    public function storeAction(): void
    {
        $this->requirePermission('employees', 'create');

        $data = $this->validateRequest(new \App\Validators\EmployeeValidator());

        try {
            $employeeId = $this->employeeService->createEmployee($data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_CREATE,
                'Created employee record',
                ['target_type' => 'Employee', 'target_id' => $employeeId, 'target_name' => ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''), 'new_values' => $data]
            );
            $this->success(['id' => $employeeId], 'Employee created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create employee. Please try again.', 500);
        }
    }

    /**
     * PUT /api/employees/{id} - Update an existing employee.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        $data = $this->validateRequest(new \App\Validators\EmployeeValidator());

        try {
            $oldEmployee = $this->employeeService->getEmployeeById($id);
            $result = $this->employeeService->updateEmployee($id, $data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                'Updated employee record',
                ['target_type' => 'Employee', 'target_id' => $id, 'target_name' => ($oldEmployee['first_name'] ?? '') . ' ' . ($oldEmployee['last_name'] ?? ''), 'old_values' => $oldEmployee, 'new_values' => $data]
            );
            $this->success($result, 'Employee updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update employee. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/employees/{id} - Delete an employee.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('employees', 'delete');

        try {
            $oldEmployee = $this->employeeService->getEmployeeById($id);
            $result = $this->employeeService->deleteEmployee($id);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted employee record',
                ['target_type' => 'Employee', 'target_id' => $id, 'target_name' => ($oldEmployee['first_name'] ?? '') . ' ' . ($oldEmployee['last_name'] ?? ''), 'old_values' => $oldEmployee]
            );
            $this->success($result, 'Employee deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete employee. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/search - Search employees.
     */
    public function searchAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $query = $_GET['q'] ?? $_GET['query'] ?? '';
            $filters = $this->getFilters();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
            
            unset($filters['q'], $filters['query']);
            
            $result = $this->employeeService->searchEmployees($query, $filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee search error', ['error' => $e->getMessage()]);
            $this->error('Failed to search employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/reference - Get reference data for forms.
     */
    public function referenceAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $departments = [];
            $sections = [];
            $subsections = [];
            $offices = [];
            $hierarchy = [];

            // Fetch each data source independently so one failure doesn't break all
            try {
                $departments = $this->employeeService->getDepartments() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference departments error', ['error' => $e->getMessage()]);
            }

            try {
                $offices = $this->employeeService->getOffices() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference offices error', ['error' => $e->getMessage()]);
            }

            try {
                $hierarchy = $this->employeeService->getOrganizationHierarchy() ?? [];
                $sections = $hierarchy['sections'] ?? [];
                $subsections = $hierarchy['subsections'] ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference hierarchy error', ['error' => $e->getMessage()]);
            }

            $data = [
                'departments' => $departments,
                'sections' => $sections,
                'subsections' => $subsections,
                'offices' => $offices,
                'hierarchy' => $hierarchy,
            ];
            $this->success($data);
        } catch (\Exception $e) {
            \logger()->error('Employee reference data error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load reference data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/employees/documents - Upload a document for an employee.
     *
     * REMOVED (Phase 7 security remediation, finding P7-1):
     * The previous implementation of this action accepted uploads with NO
     * extension allowlist, NO MIME verification and NO size limit, and stored
     * the raw file inside the web-executable directory
     * backend/public/uploads/employee_documents/ (mkdir 0777). Any future
     * route registration pointing at it would become remote code execution.
     *
     * The authorized upload paths are:
     *   - POST /api/profile/documents      → uploadProfileDocument()
     *     (extension allowlist + finfo MIME check + 5MB cap + random filename
     *      + private STORAGE_PATH storage + audit log)
     *   - POST /api/profile/profile-image  → uploadProfileImage()
     *
     * Legacy files in backend/public/uploads/ are no longer directly
     * web-accessible (denied by backend/public/uploads/.htaccess) and are
     * served exclusively through the authorized streaming endpoint
     * GET /api/profile/documents/{id}.
     */

    /**
     * POST /api/profile/documents/{documentId}/request-access
     *
     * Step 1 of opening a document: email the OWNER a 6-digit code.
     *
     * The code goes to the employee the document belongs to, not to whoever is
     * asking. A code sent to the requester would prove nothing the session
     * cookie does not already prove; sending it to the data subject is what
     * makes it an actual consent step.
     *
     * The response deliberately does not vary in SHAPE depending on whether the
     * owner has a usable email - `masked_email` is simply null when no code was
     * sent. A difference would let a caller probe which employee ids belong to
     * real, reachable people.
     */
    public function requestDocumentAccessAction(int $documentId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        try {
            // Authorization is decided BEFORE anything is emailed, using the
            // same rule as viewing: owner, or holder of employees:view. A
            // request that cannot lead to a download must not generate mail.
            if (!$this->mayAccessDocument($documentId, $userId)) {
                $this->forbidden('You do not have permission to request this document');
                return;
            }

            $service = new \App\Services\Security\DocumentAccessService();
            $result = $service->requestApproval($documentId, $userId);

            if ($result['reason'] === 'document_not_found') {
                $this->notFound('Document not found');
                return;
            }
            if ($result['reason'] === 'rate_limited') {
                $this->error('Too many requests. Please wait a minute and try again.', 429, 'RATE_LIMITED');
                return;
            }
            if (!$result['ok']) {
                // "owner_unreachable" is reported generically: the requester
                // learns the request could not be delivered, not whether the
                // employee exists or has an email on file.
                $this->error(
                    'This document cannot be shared right now. Please contact HR.',
                    409,
                    'DOCUMENT_UNAVAILABLE'
                );
                return;
            }

            $this->success([
                'sent'            => true,
                'masked_email'    => $result['masked_email'],
                'ttl_minutes'     => \App\Services\Security\DocumentAccessService::TTL_MINUTES,
                'already_pending' => $result['reason'] === 'already_pending',
            ], 'A code has been emailed to the document owner.');

        } catch (\Throwable $e) {
            \logger()->error('Document access request failed', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
            ]);
            $this->error('Could not start document access. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/documents/{documentId}/verify
     *
     * Step 2: the owner types the code they received.
     *
     * Success marks the approval verified but does NOT download anything; the
     * file is fetched by the next call, which spends the approval. Keeping the
     * two apart is what makes a correct code non-replayable for a second
     * download.
     */
    public function verifyDocumentAccessAction(int $documentId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        try {
            if (!$this->mayAccessDocument($documentId, $userId)) {
                $this->forbidden('You do not have permission to access this document');
                return;
            }

            $body = $this->getJsonBody();
            $code = (string) ($body['code'] ?? '');

            $service = new \App\Services\Security\DocumentAccessService();
            $result = $service->verifyCode($documentId, $userId, $code);

            if ($result['ok']) {
                $this->success(['verified' => true], 'Code accepted.');
                return;
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\Security\DocumentAccessService::ACTION_DENIED,
                'Document access code verification failed',
                [
                    'target_type' => 'Document',
                    'target_id'   => $documentId,
                    'status'      => 'FAILED',
                    // The reason is a fixed enum. The code itself is NEVER
                    // logged, not even hashed, in case it is still live.
                    'metadata'    => ['reason' => $result['reason'], 'user_id' => $userId],
                ]
            );

            // Reported differently per reason because this endpoint is only
            // reachable after the permission check, so it is not an oracle for
            // which documents exist.
            $message = $result['reason'] === 'incorrect_code'
                ? 'That code is not correct. Check the email and try again.'
                : 'There is no pending request for this document. Request a new code.';

            $status = $result['reason'] === 'incorrect_code' ? 400 : 409;

            $this->error($message, $status, 'DOCUMENT_CODE_INVALID');

        } catch (\Throwable $e) {
            \logger()->error('Document access verification failed', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
            ]);
            $this->error('Could not verify the code. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/employees/{employeeId}/documents/request-access
     *
     * The EMPLOYEE-SCOPED request, used when the document list is still locked.
     *
     * This exists because redacted placeholders carry no document id, and a
     * per-document request would require the caller to already know which
     * document they want - which is precisely what redaction prevents. The
     * owner receives ONE email covering their documents, rather than being
     * flooded by someone probing each one separately.
     *
     * On approval the list unlocks; each individual document open is still
     * separately gated by the per-document flow.
     */
    public function requestEmployeeDocumentsAccessAction(int $employeeId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        try {
            $employee = $this->employeeService->getEmployeeById($employeeId);
            if (!$employee) {
                $this->notFound('Employee not found');
                return;
            }

            // Same authorization as viewing the profile itself. Requesting
            // access must not be a way to probe employees you cannot see.
            if (!\App\Services\Security\EmployeePolicy::canView($userId, $employee)) {
                $this->forbidden('You do not have permission to view this employee');
                return;
            }

            $service = new \App\Services\Security\DocumentAccessService();
            $result = $service->requestEmployeeAccess($employeeId, $userId);

            if ($result['reason'] === 'no_documents') {
                $this->notFound('This employee has no documents');
                return;
            }
            if ($result['reason'] === 'rate_limited') {
                $this->error('Too many requests. Please wait a minute and try again.', 429, 'RATE_LIMITED');
                return;
            }
            if (!$result['ok']) {
                $this->error(
                    'These documents cannot be shared right now. Please contact HR.',
                    409,
                    'DOCUMENT_UNAVAILABLE'
                );
                return;
            }

            $this->success([
                'sent'            => true,
                'masked_email'    => $result['masked_email'],
                'ttl_minutes'     => \App\Services\Security\DocumentAccessService::TTL_MINUTES,
                'already_pending' => $result['reason'] === 'already_pending',
            ], 'A code has been emailed to the document owner.');

        } catch (\Throwable $e) {
            \logger()->error('Employee document access request failed', [
                'error' => $e->getMessage(),
                'employee_id' => $employeeId,
            ]);
            $this->error('Could not start document access. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/employees/{employeeId}/documents/verify
     *
     * Verifies the owner's code for an employee-scoped approval, unlocking the
     * document LIST. Opening an individual file still requires the per-document
     * approval, so this does not hand out a blanket decryption capability.
     */
    public function verifyEmployeeDocumentsAccessAction(int $employeeId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        try {
            $employee = $this->employeeService->getEmployeeById($employeeId);
            if (!$employee) {
                $this->notFound('Employee not found');
                return;
            }
            if (!\App\Services\Security\EmployeePolicy::canView($userId, $employee)) {
                $this->forbidden('You do not have permission to view this employee');
                return;
            }

            $body = $this->getJsonBody();
            $code = (string) ($body['code'] ?? '');

            $service = new \App\Services\Security\DocumentAccessService();
            $result = $service->verifyEmployeeCode($employeeId, $userId, $code);

            if ($result['ok']) {
                $this->success(['verified' => true], 'Code accepted.');
                return;
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\Security\DocumentAccessService::ACTION_DENIED,
                'Employee document access code verification failed',
                [
                    'target_type' => 'Employee',
                    'target_id'   => $employeeId,
                    'status'      => 'FAILED',
                    // The reason is a fixed enum. The code is never logged, not
                    // even hashed, in case it is still live.
                    'metadata'    => ['reason' => $result['reason'], 'user_id' => $userId],
                ]
            );

            $message = $result['reason'] === 'incorrect_code'
                ? 'That code is not correct. Check the email and try again.'
                : 'There is no pending request for these documents. Request access again.';

            $this->error(
                $message,
                $result['reason'] === 'incorrect_code' ? 400 : 409,
                'DOCUMENT_CODE_INVALID'
            );

        } catch (\Throwable $e) {
            \logger()->error('Employee document verify failed', [
                'error' => $e->getMessage(),
                'employee_id' => $employeeId,
            ]);
            $this->error('Could not verify the code. Please try again.', 500);
        }
    }

    /**
     * May this user request or open this document at all?
     *
     * Same rule as viewing: the document owner, or a holder of employees:view.
     * A missing document returns false, so this cannot be used to enumerate
     * document ids via the difference between 403 and 404.
     */
    private function mayAccessDocument(int $documentId, int $userId): bool
    {
        $document = $this->employeeService->getDocumentById($documentId);
        if (!$document) {
            return false;
        }

        $employee = $this->employeeService->getEmployeeByUserId($userId);
        $isOwner = $employee && ((int) $document['employee_id'] === (int) $employee['id']);

        return $isOwner || $this->hasPermission('employees', 'view');
    }

    /**
     * GET /api/profile/documents/{documentId}/open
     *
     * Step 3: spend the verified approval and stream the plaintext.
     *
     * The approval is consumed BEFORE any bytes are served, and only if the
     * consume actually claimed a row. That ordering is what makes it single-use
     * under concurrency: exactly one caller wins the UPDATE and every other is
     * refused rather than receiving a second copy.
     *
     * ?download=1 forces a save rather than an inline preview, for the
     * formats applyStreamHeaders() would otherwise render in the browser.
     */
    public function openDocumentAction(int $documentId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        $tempPath = null;
        $isTemporary = false;

        try {
            if (!$this->mayAccessDocument($documentId, $userId)) {
                $this->forbidden('You do not have permission to access this document');
                return;
            }

            $document = $this->employeeService->getDocumentById($documentId);
            if (!$document) {
                $this->notFound('Document not found');
                return;
            }

            $service = new \App\Services\Security\DocumentAccessService();

            // No live approval means no file, whatever the RBAC check allowed.
            if (!$service->hasLiveApproval($documentId, $userId)) {
                $this->error(
                    'You need a verified code from the document owner to open this. '
                    . 'Request access first.',
                    403,
                    'DOCUMENT_OTP_REQUIRED'
                );
                return;
            }

            // Claim the approval BEFORE decrypting. If decryption then fails the
            // approval is spent and the user must request again, which is the
            // right trade: a spent code is far cheaper than a reusable one.
            if (!$service->consumeApproval($documentId, $userId)) {
                $this->error(
                    'That approval has already been used or has expired. Request a new code.',
                    403,
                    'DOCUMENT_OTP_CONSUMED'
                );
                return;
            }

            $file = $service->decryptToTempFile($document);
            $tempPath = $file['path'];
            $isTemporary = (bool) $file['temporary'];

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\Security\DocumentAccessService::ACTION_OPENED,
                'Document opened after owner verification',
                [
                    'target_type' => 'Document',
                    'target_id'   => $documentId,
                    'target_name' => (string) ($document['document_name'] ?? ''),
                    'metadata'    => [
                        'user_id'       => $userId,
                        'was_encrypted' => (int) ($document['is_encrypted'] ?? 0) === 1,
                        'bytes'         => $file['bytes'],
                    ],
                ]
            );

            \App\Middleware\SecurityMiddleware::applyStreamHeaders(
                $file['mime'],
                (string) ($document['document_name'] ?? 'document'),
                (string) ($_GET['download'] ?? '') === '1'
            );
            header('Content-Length: ' . $file['bytes']);

            readfile($file['path']);

            // A legacy plaintext document is the ORIGINAL file and must
            // survive; only the decrypted temp copy is removed.
            if ($isTemporary) {
                @unlink($file['path']);
            }
            exit();

        } catch (\Throwable $e) {
            if ($tempPath !== null && $isTemporary) {
                @unlink($tempPath);
            }
            \logger()->error('Document open failed', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
            ]);
            $this->error('This document could not be opened. Please contact IT.', 500);
        }
    }

    /**
     * DELETE /api/employees/documents/{id} - Delete an employee document.
     */
    public function deleteDocumentAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $db = \App\Helpers\Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT file_name FROM employee_documents WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $doc = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$doc) {
                $this->notFound('Document not found');
                return;
            }

            // Delete file from disk
            $filePath = __DIR__ . '/../../public/uploads/employee_documents/' . $doc['file_name'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete database record
            $stmt = $db->prepare("DELETE FROM employee_documents WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $this->success(['id' => $id], 'Document deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Document delete error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete document. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/documents - Upload a document for the current user's profile.
     */
    public function uploadProfileDocumentAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Handle file upload
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->error('No file uploaded or upload error', 400);
                return;
            }

            $documentName = $_POST['document_name'] ?? 'Untitled';
            $category = $_POST['category'] ?? 'other';
            $uploadedFile = $_FILES['file'];

            // Validate file
            $allowedMimeTypes = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            if ($uploadedFile['size'] > $maxSize) {
                $this->error('File size exceeds 5MB limit', 400);
                return;
            }

            if ($uploadedFile['size'] === 0) {
                $this->error('Uploaded file is empty', 400);
                return;
            }

            // Verify file extension
            $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExtension, $allowedExtensions, true)) {
                $this->error('Invalid file type. Allowed formats: ' . implode(', ', $allowedExtensions), 400);
                return;
            }

            // Verify actual MIME type using finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
            finfo_close($finfo);

            if (!array_key_exists($mimeType, $allowedMimeTypes)) {
                $this->error('Invalid file format. Uploaded file MIME type is not allowed.', 400);
                return;
            }

            // Create private upload directory outside webroot
            $uploadDir = STORAGE_PATH . '/uploads/documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0750, true);
            }

            // Generate cryptographically secure filename
            $safeExtension = $allowedMimeTypes[$mimeType];
            $fileName = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
            $filePath = $uploadDir . $fileName;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
                \logger()->error('Failed to move uploaded file', ['temp' => $uploadedFile['tmp_name'], 'dest' => $filePath]);
                $this->error('Failed to upload file', 500);
                return;
            }

            // Save document record to database
            $documentData = [
                'employee_id' => (int)$employee['id'],
                'document_name' => htmlspecialchars($documentName, ENT_QUOTES, 'UTF-8'),
                'category' => htmlspecialchars($category, ENT_QUOTES, 'UTF-8'),
                'file_name' => $fileName,
                'uploaded_at' => date('Y-m-d H:i:s'),
            ];

            $documentId = $this->employeeService->addDocument($documentData);

            // Audit: log document upload
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_CREATE,
                'Uploaded document: ' . $documentName,
                [
                    'target_type' => 'Document',
                    'target_id' => $documentId,
                    'target_name' => $documentName,
                    'metadata' => ['category' => $category, 'file_name' => $fileName],
                ]
            );

            $this->success(['id' => $documentId, 'message' => 'Document uploaded successfully'], 'Document uploaded successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Document upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to upload document. Please try again.', 500);
        }
    }

    /**
     * GET /api/profile/documents/{documentId} - Securely stream / view a profile document.
     */
    public function viewProfileDocumentAction(int $documentId): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required');
            return;
        }

        $document = $this->employeeService->getDocumentById($documentId);
        if (!$document) {
            $this->notFound('Document not found');
            return;
        }

        $employee = $this->employeeService->getEmployeeByUserId($userId);
        $isOwner = $employee && ((int)$document['employee_id'] === (int)$employee['id']);
        $hasHrPerm = $this->hasPermission('employees', 'view');

        if (!$isOwner && !$hasHrPerm) {
            $this->forbidden('You do not have permission to access this document');
            return;
        }

        $filePath = STORAGE_PATH . '/uploads/documents/' . $document['file_name'];
        if (!file_exists($filePath)) {
            // Check legacy path fallback
            $legacyPath = __DIR__ . '/../../public/uploads/employee_documents/' . $document['file_name'];
            if (file_exists($legacyPath)) {
                $filePath = $legacyPath;
            } else {
                $this->notFound('File not found on server');
                return;
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        // Phase 7 (P7-7): sandbox CSP + nosniff + no-store; inline only for
        // PDF/images (business in-browser preview), attachment otherwise.
        \App\Middleware\SecurityMiddleware::applyStreamHeaders(
            $mimeType,
            $document['document_name'] ?? 'document'
        );
        header('Content-Length: ' . filesize($filePath));

        readfile($filePath);
        exit();
    }

    /**
     * DELETE /api/profile/documents/{documentId} - Delete a document from the current user's profile.
     */
    public function deleteProfileDocumentAction(int $documentId): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Get document to verify ownership
            $document = $this->employeeService->getDocumentById($documentId);
            if (!$document) {
                $this->notFound('Document not found');
            }

            if ((int)$document['employee_id'] !== (int)$employee['id']) {
                $this->forbidden('You do not have permission to delete this document');
            }

            // Delete file from storage
            if (isset($document['file_path']) && file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }

            // Delete record from database
            $this->employeeService->deleteDocument($documentId);

            // Audit: log document deletion
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted document: ' . ($document['document_name'] ?? 'Document #' . $documentId),
                [
                    'target_type' => 'Document',
                    'target_id' => $documentId,
                    'target_name' => $document['document_name'] ?? null,
                    'old_values' => $document,
                ]
            );

            $this->success(null, 'Document deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Document delete error', ['error' => $e->getMessage()]);
            $this->error('Failed to delete document. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/profile-image - Upload the current user's profile picture.
     */
    public function uploadProfileImageAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $this->handleProfileImageUpload((int)$employee['id']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile image upload error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to upload profile picture. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees/{id}/profile-image - Upload a profile picture for a specific employee (HR).
     */
    public function uploadEmployeeProfileImageAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            // OBJECT-LEVEL AUTHORIZATION (IDOR/BOLA protection) — matches
            // updateAction(): only HR/admin may modify another employee's
            // profile image; everyone else is denied and the attempt recorded.
            if (!EmployeePolicy::canEdit($this->getAuthUserId(), $employee)) {
                SecurityEventService::getInstance()->record(
                    SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS,
                    SecurityEventService::SEVERITY_MEDIUM,
                    65,
                    [
                        'user_id' => $this->getAuthUserId(),
                        'resource_type' => 'employee',
                        'resource_id' => $id,
                        'response_status' => 403,
                        'action_taken' => SecurityEventService::ACTION_DENIED,
                        'description' => "Unauthorized profile image edit attempt for employee#{$id}",
                        'route' => $_SERVER['REQUEST_URI'] ?? null,
                    ]
                );
                $this->forbidden('You are not authorized to edit this employee');
            }

            $this->handleProfileImageUpload($id);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee profile image upload error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to upload profile picture. Please try again.', 500);
        }
    }

    /**
     * Handle the actual profile image file upload and database update.
     */
    private function handleProfileImageUpload(int $employeeId): void
    {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->error('Please select a valid image file to upload', 400);
            return;
        }

        $uploadedFile = $_FILES['file'];

        // Validate file type - only images allowed
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if ($uploadedFile['size'] > $maxSize) {
            $this->error('Image size exceeds 5MB limit', 400);
            return;
        }

        if ($uploadedFile['size'] === 0) {
            $this->error('Uploaded image is empty', 400);
            return;
        }

        // Verify file extension
        $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedExtensions, true)) {
            $this->error('Invalid file type. Allowed formats: ' . implode(', ', $allowedExtensions), 400);
            return;
        }

        // Verify actual MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $uploadedFile['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, $allowedMimeTypes)) {
            $this->error('Invalid image format. Uploaded file MIME type is not allowed.', 400);
            return;
        }

        // Create upload directory in public webroot so images are accessible via URL
        $uploadDir = __DIR__ . '/../../public/uploads/profile_images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Generate unique filename
        $safeExtension = $allowedMimeTypes[$mimeType];
        $fileName = 'profile_' . $employeeId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
        $filePath = $uploadDir . $fileName;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $filePath)) {
            \logger()->error('Failed to move uploaded profile image', ['temp' => $uploadedFile['tmp_name'], 'dest' => $filePath]);
            $this->error('Failed to upload profile picture', 500);
            return;
        }

        // Delete old profile image if it exists
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT profile_image_url FROM employees WHERE id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($old && !empty($old['profile_image_url'])) {
            $oldPath = __DIR__ . '/../../public/' . $old['profile_image_url'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Store relative path in database
        $relativePath = 'uploads/profile_images/' . $fileName;
        $stmt = $db->prepare("UPDATE employees SET profile_image_url = ? WHERE id = ?");
        $stmt->bind_param('si', $relativePath, $employeeId);
        $stmt->execute();
        $stmt->close();

        // Audit: log profile image upload
        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_EMPLOYEES,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated profile picture',
            [
                'target_type' => 'Employee',
                'target_id' => $employeeId,
                'target_name' => 'Employee #' . $employeeId,
                'new_values' => ['profile_image_url' => $relativePath],
            ]
        );

        $this->success(['profile_image_url' => $relativePath], 'Profile picture uploaded successfully');
    }

    /**
     * GET /api/profile/profile-image - Stream the current user's profile picture.
     */
    public function profileImageAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $this->streamProfileImage((int)$employee['id']);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile image stream error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load profile picture. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id}/profile-image - Stream an employee's profile picture (HR).
     */
    public function employeeProfileImageAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            // OBJECT-LEVEL AUTHORIZATION (IDOR/BOLA protection) — mirrors
            // showAction(): the permission gate above verifies the caller can
            // view employees at all, but this verifies they can view THIS
            // specific employee's profile image (dept heads, HR scope, etc.).
            if (!EmployeePolicy::canView($this->getAuthUserId(), $employee)) {
                SecurityEventService::getInstance()->record(
                    SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS,
                    SecurityEventService::SEVERITY_MEDIUM,
                    60,
                    [
                        'user_id' => $this->getAuthUserId(),
                        'resource_type' => 'employee',
                        'resource_id' => $id,
                        'response_status' => 403,
                        'action_taken' => SecurityEventService::ACTION_DENIED,
                        'description' => "Unauthorized access attempt to employee#{$id} profile image",
                        'route' => $_SERVER['REQUEST_URI'] ?? null,
                    ]
                );
                $this->forbidden('You are not authorized to view this employee');
            }

            $this->streamProfileImage($id);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee profile image stream error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to load profile picture. Please try again.', 500);
        }
    }

    /**
     * Stream a profile image file from public/uploads/profile_images/.
     */
    private function streamProfileImage(int $employeeId): void
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT profile_image_url FROM employees WHERE id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee || empty($employee['profile_image_url'])) {
            \App\Helpers\ApiResponse::error('Profile picture not found', 'NOT_FOUND', [], 404);
        }

        // Support both public-webroot path and storage-relative path
        $filePath = __DIR__ . '/../../public/' . $employee['profile_image_url'];
        if (!file_exists($filePath)) {
            $storagePath = STORAGE_PATH . '/' . $employee['profile_image_url'];
            if (file_exists($storagePath)) {
                $filePath = $storagePath;
            } else {
                \App\Helpers\ApiResponse::error('Profile picture file not found on server', 'NOT_FOUND', [], 404);
            }
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
        finfo_close($finfo);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');

        readfile($filePath);
        exit();
    }

    /**
     * GET /api/profile - Get the current user's profile.
     */
    public function profileAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Build profile data structure expected by the frontend
            $profile = [
                'profile_image_url' => $employee['profile_image_url'] ?? null,
                'personal' => [
                    'first_name' => $employee['first_name'] ?? '',
                    'last_name' => $employee['last_name'] ?? '',
                    'surname' => $employee['surname'] ?? '',
                    'email' => $employee['email'] ?? '',
                    'phone' => $employee['phone'] ?? '',
                    'national_id' => $employee['national_id'] ?? '',
                    'gender' => $employee['gender'] ?? '',
                    'marital_status' => $employee['marital_status'] ?? '',
                    'address' => $employee['address'] ?? '',
                ],
                'employment' => [
                    'department' => $employee['department_name'] ?? '',
                    'section' => $employee['section_name'] ?? '',
                    'office' => $employee['office_name'] ?? '',
                    'designation' => $employee['designation'] ?? '',
                    'employment_type' => $employee['employment_type'] ?? '',
                    'employee_type' => $employee['employee_type'] ?? '',
                    'employee_status' => $employee['employee_status'] ?? '',
                    'employment_date' => $employee['hire_date'] ?? $employee['employment_date'] ?? '',
                ],
                'next_of_kin' => $employee['next_of_kin_data'] ?? null,
                'dependants' => $employee['dependants_data'] ?? [],
                'documents' => $employee['documents'] ?? [],
            ];

            $this->success($profile);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile retrieval error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load profile. Please try again.', 500);
        }
    }

    /**
     * PUT /api/profile - Update the current user's profile.
     */
    public function updateProfileAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $data = $this->getJsonBody();
            $updateData = [];

            // Handle personal info updates
            if (isset($data['personal']) && is_array($data['personal'])) {
                $personal = $data['personal'];
                $allowedPersonalFields = [
                    'phone', 'address', 'marital_status'
                ];
                foreach ($allowedPersonalFields as $field) {
                    if (isset($personal[$field])) {
                        $updateData[$field] = $personal[$field];
                    }
                }
            }

            // Note: Employment fields (designation, employee_type, employee_status, employment_date)
            // cannot be modified via self-service /profile endpoint. They require HR administrator privileges.

            // Handle next of kin updates - pass array directly to service
            if (isset($data['next_of_kin'])) {
                $updateData['next_of_kin'] = $data['next_of_kin'];
            }

            // Handle dependants updates - pass array directly to service
            if (isset($data['dependants'])) {
                $updateData['dependants'] = $data['dependants'];
            }

            if (empty($updateData)) {
                $this->error('No valid fields to update', 400);
                return;
            }

            // Update the employee record (partial update)
            $result = $this->employeeService->updateEmployeeProfile((int)$employee['id'], $updateData);

            // Audit: log profile update
            $auditDescription = 'Updated profile';
            if (isset($updateData['next_of_kin'])) {
                $auditDescription = 'Updated next of kin information';
            } elseif (isset($updateData['dependants'])) {
                $auditDescription = 'Updated dependants';
            } elseif (!empty($updateData)) {
                $auditDescription = 'Updated personal contact information';
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                $auditDescription,
                [
                    'target_type' => 'Employee',
                    'target_id' => (int)$employee['id'],
                    'target_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
                    'new_values' => $updateData,
                ]
            );

            $this->success($result, 'Profile updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile update error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update profile. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id}/contracts - Get all contracts for an employee (HR).
     */
    public function getEmployeeContractsAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            if (!\App\Services\Security\EmployeePolicy::canView($this->getAuthUserId(), $employee)) {
                $this->forbidden('You are not authorized to view this employee');
            }

            $contracts = $this->employeeService->getEmployeeContracts($id);
            $totalCount = $this->employeeService->getEmployeeContractCount($id);

            $this->success([
                'contracts' => $contracts,
                'count' => $totalCount,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee contracts retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve contracts. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees/{id}/contracts/{contractId}/renew - Renew a contract (HR).
     */
    public function renewEmployeeContractAction(int $id, int $contractId): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            if (!\App\Services\Security\EmployeePolicy::canEdit($this->getAuthUserId(), $employee)) {
                $this->forbidden('You are not authorized to edit this employee');
            }

            $body = $this->getJsonBody() ?: [];
            $result = $this->employeeService->renewEmployeeContract($id, $contractId, [
                'start_date' => $body['start_date'] ?? null,
                'end_date' => $body['end_date'] ?? null,
                'duration_months' => $body['duration_months'] ?? null,
            ]);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                'Renewed contract for employee',
                ['target_type' => 'Contract', 'target_id' => $contractId, 'target_name' => 'Employee #' . $id]
            );
            $this->success($result, 'Contract renewed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Contract renewal error', ['error' => $e->getMessage(), 'id' => $id, 'contractId' => $contractId]);
            $this->error('Failed to renew contract. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees/{id}/convert-to-permanent - Convert a contract
     * employee to permanent employment (HR). Sets employment_type to
     * 'permanent', clears the active contract dates and preserves the
     * contract history. The UI follows up with leave allocation in the
     * Financial Year module.
     */
    public function convertToPermanentAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            if (!\App\Services\Security\EmployeePolicy::canEdit($this->getAuthUserId(), $employee)) {
                $this->forbidden('You are not authorized to edit this employee');
            }

            $updated = $this->employeeService->convertEmployeeToPermanent($id);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                'Converted employee to permanent employment',
                [
                    'target_type' => 'Employee',
                    'target_id' => $id,
                    'target_name' => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')) ?: ('Employee #' . $id),
                ]
            );
            $this->success(['employee' => $updated], 'Employee converted to permanent successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee conversion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to convert employee to permanent. Please try again.', 500);
        }
    }

    /**
     * GET /api/profile/contracts - Get contracts for the current user.
     */
    public function getProfileContractsAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $contracts = $this->employeeService->getEmployeeContracts((int)$employee['id']);
            $totalCount = $this->employeeService->getEmployeeContractCount((int)$employee['id']);

            $this->success([
                'contracts' => $contracts,
                'count' => $totalCount,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile contracts retrieval error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve contracts. Please try again.', 500);
        }
    }

    /**
     * POST /api/profile/contracts/{contractId}/renew - Renew a contract for current user.
     *
     * Self-service route, but NOT self-service *authority*: renewing mutates the
     * employment record, so it is reserved for HR/admin exactly like
     * POST /employees/{id}/contracts/{contractId}/renew. Officers and other staff
     * may read their contract history here (GET /profile/contracts) but must go
     * through HR to extend a term. The route is gated on employees:edit; this is
     * the object-level check that survives any route-table change.
     */
    public function renewProfileContractAction(int $contractId): void
    {
        $this->requirePermission('profile', 'edit');

        if (!\App\Services\Security\EmployeePolicy::canRenewContract($this->getAuthUserId())) {
            $this->forbidden('Contract renewal is handled by HR. Please contact HR to renew your contract.');
        }

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $result = $this->employeeService->renewEmployeeContract((int)$employee['id'], $contractId);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_EMPLOYEES,
                \App\Services\AuditService::ACTION_UPDATE,
                'Renewed own contract',
                ['target_type' => 'Contract', 'target_id' => $contractId, 'target_name' => 'Employee #' . $employee['id']]
            );
            $this->success($result, 'Contract renewed successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile contract renewal error', ['error' => $e->getMessage(), 'contractId' => $contractId]);
            $this->error('Failed to renew contract. Please try again.', 500);
        }
    }
}



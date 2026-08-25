<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;

use App\Models\Consent;

/**
 * ConsentController
 *
 * REST API for employee data protection consent.
 * Follows the existing controller pattern (BaseController + Auth helper).
 */
class ConsentController extends BaseController
{
    private Consent $consentModel;

    public function __construct()
    {
        $this->consentModel = new Consent();
    }

    /**
     * GET /api/consent/status
     * Returns whether the authenticated user has accepted the current consent version.
     */
    public function statusAction(): void
    {
        try {
            $userId = $this->getAuthUserId();

            // If not authenticated yet, return consented=false (no error, just no consent)
            // ProtectedRoute.jsx already checks isAuthenticated separately, but sometimes
            // the session cookie hasn't propagated by the time this endpoint is called after login.
            if ($userId <= 0) {
                $this->json([
                    'success' => true,
                    'consented' => false,
                    'consent_version' => Consent::CURRENT_VERSION ?? 1,
                    'message' => 'Not authenticated',
                ]);
                return;
            }

            try {
                $consented = $this->consentModel->hasAcceptedVersion($userId, Consent::CURRENT_VERSION);
                $this->json([
                    'success' => true,
                    'consented' => $consented,
                    'consent_version' => Consent::CURRENT_VERSION,
                ]);
            } catch (\Exception $dbError) {
                // Database error - log and return false (require consent)
                error_log("Consent check database error: " . $dbError->getMessage());
                http_response_code(500);
                $this->json([
                    'success' => false,
                    'consented' => false,
                    'consent_version' => Consent::CURRENT_VERSION,
                    'error' => 'Consent check failed - please try again',
                ], 500);
            }
        } catch (\Throwable $e) {
            error_log("Consent Status Error: " . $e->getMessage());
            // On critical error, require consent (safer approach)
            http_response_code(500);
            $this->json([
                'success' => false,
                'consented' => false,
            ]);
        }
    }

    /**
     * POST /api/consent/verify-employee
     * Verifies the supplied Employee ID against the authenticated user's linked employee record.
     */
    public function verifyEmployeeIdAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            error_log("Employee verification attempt for user ID: $userId");
            
            if ($userId <= 0) {
                error_log("Unauthenticated - no user ID");
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $employeeId = trim((string) ($input['employee_id'] ?? ''));
            error_log("Employee ID to verify: '$employeeId' for user ID: $userId");

            if ($employeeId === '') {
                $this->json([
                    'success' => false,
                    'message' => 'Employee ID is required',
                ], 422);
                return;
            }

            // Server-side verification - verify using National ID
            $result = $this->consentModel->verifyNationalId($userId, $employeeId);
            error_log("Verification result: " . json_encode($result));

            if (!$result['success']) {
                error_log("Verification failed: " . $result['error']);
                $this->json([
                    'success' => false,
                    'message' => $result['error'],
                ], 422);
                return;
            }

            $this->json([
                'success' => true,
                'message' => 'Employee verified successfully',
                'data' => [
                    'employee_id' => $result['employee_id'],
                    'employee_name' => trim(($result['employee_data']['first_name'] ?? '') . ' ' . ($result['employee_data']['last_name'] ?? '')),
                ],
            ]);
        } catch (\Exception $e) {
            error_log("Consent Employee Verify Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            $this->json([
                'success' => false,
                'message' => 'Failed to verify employee ID: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/consent/dashboard
     * Returns dashboard statistics for HR consent management.
     */
    public function dashboardAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            
            if ($userId <= 0) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            // Check if user has HR permissions
            if (!$this->hasPermission('consent', 'view')) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
                return;
            }

            $stats = $this->consentModel->getDashboardStats();
            
            $this->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            error_log("Consent Dashboard Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to load dashboard statistics',
            ], 500);
        }
    }

    /**
     * GET /api/consent/employees
     * Returns paginated employee consent list with filters.
     */
    public function employeesAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            
            if ($userId <= 0) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            // Check if user has HR permissions
            if (!$this->hasPermission('consent', 'view')) {
                $this->json(['success' => false, 'message' => 'Unauthorized'], 403);
                return;
            }

            // Get query parameters
            $status = $_GET['status'] ?? 'all';
            $departmentId = $_GET['department_id'] ?? null;
            $sectionId = $_GET['section_id'] ?? null;
            $search = $_GET['search'] ?? '';
            $dateFrom = $_GET['date_from'] ?? null;
            $dateTo = $_GET['date_to'] ?? null;
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));

            $filters = [
                'status' => $status,
                'department_id' => $departmentId ? (int)$departmentId : null,
                'section_id' => $sectionId ? (int)$sectionId : null,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'page' => $page,
                'per_page' => $perPage,
            ];

            $result = $this->consentModel->getEmployeeConsentList($filters);
            
            $this->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            error_log("Consent Employees Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to load employee consent data',
            ], 500);
        }
    }

    /**
     * Helper method to check user permissions
     */
    protected function hasPermission(string $module, string $action): bool
    {
        try {
            $userId = $this->getAuthUserId();
            if ($userId <= 0) {
                return false;
            }

            // Check if user is super admin
            $userModel = new \App\Models\User();
            $user = $userModel->findById($userId);
            if (!$user) {
                return false;
            }

            // Super admin has all permissions
            if (isset($user['role']) && $user['role'] === 'super_admin') {
                return true;
            }

            // Check specific permission from RBAC system
            $auth = \App\Helpers\Auth::getInstance();
            return $auth->hasPermission($module, $action);
        } catch (\Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * POST /api/consent
     * Submits consent for the authenticated employee for the current consent version.
     */
    public function storeConsentAction(): void
    {
        try {
            $userId = $this->getAuthUserId();
            if ($userId <= 0) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $employeeId = trim((string) ($input['employee_id'] ?? ''));
            $agreed = !empty($input['agreed']);

            if ($employeeId === '') {
                $this->json([
                    'success' => false,
                    'message' => 'Employee ID is required',
                ], 422);
                return;
            }

            if (!$agreed) {
                $this->json([
                    'success' => false,
                    'message' => 'You must agree to the Data Protection Notice to continue',
                ], 422);
                return;
            }

            // 1. Verify the National ID belongs to the authenticated user
            $verify = $this->consentModel->verifyNationalId($userId, $employeeId);
            if (!$verify['success']) {
                $this->json([
                    'success' => false,
                    'message' => $verify['error'],
                ], 403);
                return;
            }

            // 2. Prevent duplicate consent for the current version
            if ($this->consentModel->hasAcceptedVersion($userId, Consent::CURRENT_VERSION)) {
                $this->json([
                    'success' => true,
                    'message' => 'Consent already provided for this version',
                    'data' => [
                        'consent_version' => Consent::CURRENT_VERSION,
                        'already_consented' => true,
                    ],
                ]);
                return;
            }

            // 3. Store consent with version + audit info
            $name = trim(($verify['employee_data']['first_name'] ?? '') . ' ' . ($verify['employee_data']['last_name'] ?? ''));
            $nationalId = (string) ($verify['employee_data']['national_id'] ?? '');

            $saved = $this->consentModel->saveConsentWithVersion(
                $userId,
                $name,
                $nationalId,
                Consent::CURRENT_VERSION
            );

            if (!$saved) {
                $this->json([
                    'success' => false,
                    'message' => 'Failed to save consent. Please try again.',
                ], 500);
                return;
            }

            $this->json([
                'success' => true,
                'message' => 'Consent recorded successfully',
                'data' => [
                    'consent_version' => Consent::CURRENT_VERSION,
                    'consented' => true,
                ],
            ], 201);
        } catch (\Exception $e) {
            error_log("Consent Store Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to save consent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}


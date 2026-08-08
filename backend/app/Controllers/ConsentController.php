<?php

declare(strict_types=1);

namespace App\Controllers;

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
            if ($userId <= 0) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            $consented = $this->consentModel->hasAcceptedVersion($userId, Consent::CURRENT_VERSION);

            $this->json([
                'success' => true,
                'consented' => $consented,
                'consent_version' => Consent::CURRENT_VERSION,
            ]);
        } catch (\Exception $e) {
            error_log("Consent Status Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to check consent status',
                'error' => $e->getMessage(),
            ], 500);
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
            if ($userId <= 0) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $employeeId = trim((string) ($input['employee_id'] ?? ''));

            if ($employeeId === '') {
                $this->json([
                    'success' => false,
                    'message' => 'Employee ID is required',
                ], 422);
                return;
            }

            // Server-side verification - never trust frontend-only validation
            $result = $this->consentModel->verifyEmployeeId($userId, $employeeId);

            if (!$result['success']) {
                $this->json([
                    'success' => false,
                    'message' => $result['error'],
                ], 403);
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
            error_log("Consent Employee Verify Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to verify employee ID',
                'error' => $e->getMessage(),
            ], 500);
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

            // 1. Verify the employee ID belongs to the authenticated user
            $verify = $this->consentModel->verifyEmployeeId($userId, $employeeId);
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
            $nationalId = $verify['employee_data']['national_id'] ?? '';

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
<?php

declare(strict_types=1);

namespace App\Controllers\Employee;

use App\Controllers\BaseController;
use App\Services\Vault\VaultException;
use App\Services\Vault\VaultService;

/**
 * VaultController - HTTP surface for the Private Vault (PART A).
 *
 * SHAPE OF THE API
 *   The browser does all cryptography. This controller moves ciphertext,
 *   wrapped keys and access decisions. It never receives a passphrase, an
 *   unwrapped data key, or a decrypted value - if it did, the zero-knowledge
 *   property would be gone.
 *
 * OWNERSHIP IS RESOLVED SERVER-SIDE, NEVER FROM THE REQUEST
 *   Every owner-only action calls requireOwnEmployeeId() to derive the target
 *   from the authenticated user. An `employee_id` in the body is used ONLY to
 *   identify WHOSE vault is being read by a grantee - never to decide who may
 *   write. That is what makes "non-owner write is denied" a property of the
 *   code rather than a promise.
 *
 * EVERY RESPONSE GOES THROUGH fail()
 *   VaultException carries the reason and the HTTP status, so a policy decision
 *   made deep in the service is translated the same way every time. A vault
 *   that does not exist, and one the caller may not see, are both 404.
 */
final class VaultController extends BaseController
{
    private VaultService $vault;

    public function __construct()
    {
        $this->vault = VaultService::getInstance();
    }

    /**
     * Translate a VaultException into the standard error envelope, and fail
     * closed if the feature is switched off.
     */
    private function fail(VaultException $e): void
    {
        $this->error($e->getMessage(), $e->getHttpStatus(), $e->getReason());
    }

    private function requireEnabled(): void
    {
        if (!$this->vault->isEnabled()) {
            $this->error(
                'The private vault is not available on this system.',
                503,
                'VAULT_DISABLED'
            );
        }
    }

    /**
     * POST /api/vault/setup
     *
     * Register the caller's vault. One-time, owner-only. The payload contains
     * only public key material and wrapped blobs.
     */
    public function setupAction(): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);
            $body = $this->getJsonBody();

            $this->vault->setup($userId, $employeeId, $body);

            $this->success([
                'has_vault' => true,
                'groups'    => array_keys($this->vault->groups()),
            ], 'Vault created. Store your recovery code somewhere safe.');

        } catch (VaultException $e) {
            $this->fail($e);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, VaultException::VALIDATION);
        }
    }

    /**
     * GET /api/vault/status
     *
     * What the caller needs to render their own vault panel: whether it exists,
     * the public keys needed to build a grant, and the pending requests and
     * live grants they control.
     */
    public function statusAction(): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);
            $hasVault = $this->vault->hasVault($employeeId);
            $keyRow = $hasVault ? $this->vault->keyRowForUser($userId) : null;

            $this->success([
                'has_vault'    => $hasVault,
                'groups'       => array_keys($this->vault->groups()),
                'kdf'          => $keyRow['kdf_params'] ?? null,
                'public_key'   => $keyRow['public_key'] ?? null,
                'salt'         => isset($keyRow['salt']) ? bin2hex((string) $keyRow['salt']) : null,
                'auto_lock_seconds' => (int) \config('vault.auto_lock_seconds', 300),
                'requests'     => $hasVault ? $this->vault->requestsFor($employeeId) : [],
                'grants'       => $hasVault ? $this->vault->grantsFor($employeeId) : [],
            ]);

        } catch (VaultException $e) {
            $this->fail($e);
        }
    }

    /**
     * PUT /api/vault/items/{group}
     *
     * Store one encrypted field group. OWNER ONLY - the employee is derived
     * from the session, not from the request.
     */
    public function writeItemAction(string $group): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);
            $body = $this->getJsonBody();

            foreach (['ciphertext', 'iv', 'aad'] as $field) {
                if (!isset($body[$field]) || trim((string) $body[$field]) === '') {
                    throw new VaultException(
                        "Missing required field: {$field}",
                        VaultException::VALIDATION
                    );
                }
            }

            $version = $this->vault->writeItem(
                $employeeId,
                $group,
                (string) $body['ciphertext'],
                (string) $body['iv'],
                (string) $body['aad'],
                (int) ($body['expected_version'] ?? 0)
            );

            $this->success(['version' => $version], 'Encrypted data stored.');

        } catch (VaultException $e) {
            $this->fail($e);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, VaultException::VALIDATION);
        }
    }

    /**
     * GET /api/vault/items/{employeeId}
     *
     * Read an employee's vault. The service decides: the owner always, a
     * grantee only with a live grant, and anyone else gets 404 without being
     * told whether a vault exists.
     *
     * `employeeId` here identifies WHOSE vault is being read. It is never used
     * to authorise a write.
     */
    public function readItemsAction(int $employeeId): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $result = $this->vault->readItems($employeeId, $userId);

            $this->success($result);

        } catch (VaultException $e) {
            $this->fail($e);
        }
    }

    /**
     * GET /api/vault/requests/{employeeId}/state
     *
     * The lock state for the HR view: not_set_up | locked | pending | granted.
     * This is what lets the UI show "Locked - request access" instead of an
     * empty value.
     */
    public function lockStateAction(int $employeeId): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $this->success($this->vault->lockState($employeeId, $userId));

        } catch (VaultException $e) {
            $this->fail($e);
        }
    }

    /**
     * POST /api/vault/requests
     *
     * Ask an employee for access. Gated on `employees:view` at the route
     * level, and re-checked against EmployeePolicy in the service so a vault
     * grant can never become a way around RBAC.
     */
    public function requestAccessAction(): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $body = $this->getJsonBody();
            $employeeId = (int) ($body['employee_id'] ?? 0);
            $reason = (string) ($body['reason'] ?? '');

            if ($employeeId <= 0) {
                throw new VaultException(
                    'employee_id is required.',
                    VaultException::VALIDATION
                );
            }

            $id = $this->vault->requestAccess($employeeId, $userId, $reason);

            $this->success(['request_id' => $id], 'Access requested. The employee has been notified.');

        } catch (VaultException $e) {
            $this->fail($e);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, VaultException::VALIDATION);
        }
    }

    /**
     * PUT /api/vault/requests/{id}
     *
     * Approve or deny. OWNER ONLY - the request is looked up scoped to the
     * caller's own employee id, so a request id belonging to someone else is
     * reported as not found.
     */
    public function decideRequestAction(int $id): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);
            $body = $this->getJsonBody();
            $decision = (string) ($body['decision'] ?? '');

            $this->vault->decideRequest($id, $employeeId, $decision);

            $this->success(null, 'Request ' . $decision . '.');

        } catch (VaultException $e) {
            $this->fail($e);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, VaultException::VALIDATION);
        }
    }

    /**
     * POST /api/vault/grants
     *
     * Mint a grant. OWNER ONLY. `wrapped_data_key` was produced in the OWNER's
     * browser for the grantee's public key; the server stores and relays it
     * without ever being able to unwrap it.
     */
    public function createGrantAction(): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);
            $body = $this->getJsonBody();

            $granteeUserId = (int) ($body['grantee_user_id'] ?? 0);
            $wrappedKey = (string) ($body['wrapped_data_key'] ?? '');
            $reason = (string) ($body['reason'] ?? '');

            if ($granteeUserId <= 0) {
                throw new VaultException(
                    'grantee_user_id is required.',
                    VaultException::VALIDATION
                );
            }

            $id = $this->vault->createGrant($employeeId, $granteeUserId, $wrappedKey, $reason);

            $this->success(['grant_id' => $id], 'Access granted.');

        } catch (VaultException $e) {
            $this->fail($e);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, VaultException::VALIDATION);
        }
    }

    /**
     * DELETE /api/vault/grants/{id}
     *
     * Revoke. OWNER ONLY, at any time, regardless of expiry.
     */
    public function revokeGrantAction(int $id): void
    {
        $this->requireEnabled();

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            $employeeId = $this->vault->requireOwnEmployeeId($userId);

            $this->vault->revokeGrant($id, $employeeId);

            $this->success(null, 'Access revoked.');

        } catch (VaultException $e) {
            $this->fail($e);
        }
    }
}

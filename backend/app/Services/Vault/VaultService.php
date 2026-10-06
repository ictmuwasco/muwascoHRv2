<?php

declare(strict_types=1);

namespace App\Services\Vault;

use App\Services\AuditService;
use App\Services\NotificationService;

/**
 * VaultService - server side of the Private Vault (PART A).
 *
 * WHAT THIS CLASS IS, AND WHAT IT IS NOT
 *   It stores and gates CIPHERTEXT. It can never read vault content: the data
 *   key is derived in the browser from a passphrase the server never sees, and
 *   only WRAPPED forms are persisted. A database dump of every vault table
 *   yields nothing readable.
 *
 * THE FOUR RULES, IN ONE PLACE
 *   1. Only the OWNER may write vault items, create requests, approve, deny,
 *      or revoke. Enforced by the caller resolving their own employee id and
 *      comparing it to the target.
 *   2. A GRANTEE may read ciphertext only while status='active' AND
 *      expires_at > NOW(). The expiry is checked in the query itself, not only
 *      by the cron job, so a missed cron run can never widen access.
 *   3. Anyone else gets 404 - not 403. A 403 would confirm that a vault
 *      exists, which is itself a disclosure.
 *   4. Nobody may invent KDF parameters. setup() always takes them from
 *      config, never from the request body.
 *
 * WHAT IS NEVER LOGGED
 *   Ciphertext, wrapped keys, passphrases, recovery codes, and plaintext field
 *   values. Audit rows carry ids, group names, counts and reasons - never
 *   decrypted content.
 */
final class VaultService
{
    public const MODULE = 'Private Vault';

    // Audit actions, kept as constants so a typo cannot invent a new string
    // the security dashboard does not know about.
    public const ACTION_SETUP           = 'VAULT_SETUP';
    public const ACTION_ITEM_WRITTEN    = 'VAULT_ITEM_WRITTEN';
    public const ACTION_REQUESTED       = 'VAULT_ACCESS_REQUESTED';
    public const ACTION_APPROVED        = 'VAULT_ACCESS_APPROVED';
    public const ACTION_DENIED          = 'VAULT_ACCESS_DENIED';
    public const ACTION_GRANTED         = 'VAULT_ACCESS_GRANTED';
    public const ACTION_VIEWED          = 'VAULT_DATA_VIEWED';
    public const ACTION_REVOKED         = 'VAULT_ACCESS_REVOKED';
    public const ACTION_EXPIRED         = 'VAULT_ACCESS_EXPIRED';
    public const ACTION_READ_DENIED     = 'VAULT_READ_DENIED';

    private static ?VaultService $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return \config('vault.' . $key, $default);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    // =================================================================
    // Ownership
    // =================================================================

    /**
     * Resolve the employees primary key for a user, or null.
     *
     * users.employee_id is the business employee NUMBER (varchar) and joins to
     * employees.employee_id - NOT to the employees primary key. Joining the
     * wrong column returns null and would make every vault look unowned.
     */
    public function employeeIdForUser(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $row = \db()->fetchOne(
                'SELECT e.id AS employee_pk
                   FROM users u
                   JOIN employees e ON e.employee_id = u.employee_id
                  WHERE u.id = ?
                  LIMIT 1',
                'i',
                [$userId]
            );
            if ($row === null) {
                return null;
            }
            $id = (int) ($row['employee_pk'] ?? 0);
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            error_log('[VaultService] employeeIdForUser failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * The caller's own employee id, or refuse.
     *
     * Every owner-only action routes through this, so "is this mine?" is
     * decided in exactly one place.
     */
    public function requireOwnEmployeeId(int $userId): int
    {
        $employeeId = $this->employeeIdForUser($userId);
        if ($employeeId === null) {
            throw new VaultException(
                'No employee record is linked to your account.',
                VaultException::FORBIDDEN
            );
        }
        return $employeeId;
    }

    // =================================================================
    // Setup
    // =================================================================

    /**
     * Has this employee already set up a vault?
     */
    public function hasVault(int $employeeId): bool
    {
        $row = \db()->fetchOne(
            'SELECT vk.user_id
               FROM vault_keys vk
               JOIN users u ON u.id = vk.user_id
               JOIN employees e ON e.employee_id = u.employee_id
              WHERE e.id = ?
              LIMIT 1',
            'i',
            [$employeeId]
        );
        return $row !== null;
    }

    /**
     * The vault_keys row for a user, or null.
     */
    public function keyRowForUser(int $userId): ?array
    {
        return \db()->fetchOne(
            'SELECT user_id, public_key, wrapped_private_key,
                    wrapped_data_key_passphrase, wrapped_data_key_recovery,
                    kdf_params, salt, created_at, rotated_at
               FROM vault_keys
              WHERE user_id = ?
              LIMIT 1',
            'i',
            [$userId]
        );
    }

    /**
     * Register a new vault. Called once, at setup, by the owner only.
     *
     * Every wrapped blob arrives already encrypted by the browser. The server
     * never sees a passphrase, an unwrapped data key, or any plaintext.
     */
    public function setup(int $userId, int $employeeId, array $payload): void
    {
        if ($this->hasVault($employeeId)) {
            throw new VaultException(
                'A vault is already set up for this profile.',
                VaultException::CONFLICT
            );
        }

        foreach (['public_key', 'wrapped_private_key', 'wrapped_data_key_passphrase',
                  'wrapped_data_key_recovery', 'salt'] as $field) {
            if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw new VaultException(
                    "Missing required field: {$field}",
                    VaultException::VALIDATION
                );
            }
        }

        // KDF parameters come from CONFIG, never from the client. A client
        // asking for 1 iteration must not be recorded and later honoured.
        $kdfParams = [
            'algorithm'  => (string) $this->config('kdf.algorithm', 'PBKDF2'),
            'hash'       => (string) $this->config('kdf.hash', 'SHA-256'),
            'iterations' => (int) $this->config('kdf.iterations', 600000),
            'key_length' => (int) $this->config('kdf.key_length', 32),
        ];

        $salt = (string) $payload['salt'];
        if (preg_match('/^[0-9a-fA-F]{64}$/', $salt) !== 1) {
            throw new VaultException(
                'Salt must be 32 bytes hex-encoded (64 characters).',
                VaultException::VALIDATION
            );
        }

        $conn = \db()->getConnection();
        $stmt = $conn->prepare(
            'INSERT INTO vault_keys
                (user_id, public_key, wrapped_private_key, wrapped_data_key_passphrase,
                 wrapped_data_key_recovery, kdf_params, salt)
             VALUES (?,?,?,?,?,?,?)'
        );
        $publicKey  = (string) $payload['public_key'];
        $wrappedPriv = (string) $payload['wrapped_private_key'];
        $wrappedPass = (string) $payload['wrapped_data_key_passphrase'];
        $wrappedRec  = (string) $payload['wrapped_data_key_recovery'];
        $kdfJson     = (string) json_encode($kdfParams);
        $saltBin     = (string) hex2bin($salt);

        // Type string must have exactly one character per placeholder:
        // i(user_id) s(public_key) s(wrapped_private_key)
        // s(wrapped_data_key_passphrase) s(wrapped_data_key_recovery)
        // s(kdf_params) b(salt) = 7 characters for 7 placeholders.
        $stmt->bind_param('isssssb', $userId, $publicKey, $wrappedPriv, $wrappedPass, $wrappedRec, $kdfJson, $saltBin);
        $ok  = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            throw new VaultException('Could not create the vault: ' . $err, VaultException::SERVER);
        }

        AuditService::getInstance()->log(
            self::MODULE,
            self::ACTION_SETUP,
            'Employee created their private vault',
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                // Metadata only: no key material, no salt, no ciphertext. The
                // KDF parameters are public configuration, so recording them is
                // safe and lets a future re-derivation verify against this row.
                'metadata'    => ['kdf' => $kdfParams],
            ]
        );
    }

    // =================================================================
    // Items
    // =================================================================

    /**
     * Every configured field group, including `salary` when enabled.
     *
     * @return array<string, string[]>
     */
    public function groups(): array
    {
        $groups = (array) $this->config('vault_groups', []);
        if ($this->includesSalary()) {
            $groups['salary'] = ['salary'];
        }
        return $groups;
    }

    public function includesSalary(): bool
    {
        return (bool) $this->config('include_salary', false);
    }

    public function isValidGroup(string $group): bool
    {
        return array_key_exists($group, $this->groups());
    }

    /**
     * Write (or replace) one encrypted group. OWNER ONLY - the caller must
     * have already resolved $employeeId from their own user id.
     *
     * `expectedVersion` is the optimistic-concurrency token the browser read.
     * If the stored version has moved on the write is refused, so a stale tab
     * cannot silently clobber a newer value.
     */
    public function writeItem(int $employeeId, string $group, string $ciphertext, string $ivHex, string $aad, int $expectedVersion): int
    {
        if (!$this->isValidGroup($group)) {
            throw new VaultException('Unknown field group.', VaultException::VALIDATION);
        }
        if (preg_match('/^[0-9a-fA-F]{24}$/', $ivHex) !== 1) {
            throw new VaultException('IV must be 12 bytes hex-encoded.', VaultException::VALIDATION);
        }

        $conn = \db()->getConnection();

        $existing = $conn->prepare(
            'SELECT id, version FROM vault_items WHERE employee_id = ? AND field_group = ? LIMIT 1'
        );
        $existing->bind_param('is', $employeeId, $group);
        $existing->execute();
        $row = $existing->get_result()->fetch_assoc();
        $existing->close();

        $storedVersion = $row !== null ? (int) $row['version'] : 0;
        $nextVersion   = $storedVersion + 1;

        if ($row !== null && $expectedVersion > 0 && $storedVersion !== $expectedVersion) {
            throw new VaultException(
                'This field was changed in another session. Reload and try again.',
                VaultException::CONFLICT
            );
        }

        if ($row === null) {
            $insert = $conn->prepare(
                'INSERT INTO vault_items (employee_id, field_group, ciphertext, iv, aad, version)
                 VALUES (?,?,?,?,?,?)'
            );
            // bind_param() takes arguments BY REFERENCE, so every value needs
            // its own variable - an inline (string) hex2bin(...) is an
            // expression and PHP rejects it.
            $ivBin = (string) hex2bin($ivHex);
            $insert->bind_param('isssbi', $employeeId, $group, $ciphertext, $ivBin, $aad, $nextVersion);
            $ok  = $insert->execute();
            $err = $insert->error;
            $insert->close();
            if (!$ok) {
                throw new VaultException('Could not store the encrypted field: ' . $err, VaultException::SERVER);
            }
        } else {
            $update = $conn->prepare(
                'UPDATE vault_items
                    SET ciphertext = ?, iv = ?, aad = ?, version = ?
                  WHERE id = ? AND version = ?'
            );
            $id    = (int) $row['id'];
            $ivBin = (string) hex2bin($ivHex);
            $update->bind_param('ssbiii', $ciphertext, $ivBin, $aad, $nextVersion, $id, $storedVersion);
            $ok        = $update->execute();
            $err       = $update->error;
            // Read affected_rows BEFORE close(): the property is not valid
            // afterwards, and a zero here means another writer won the race.
            $affected  = $update->affected_rows;
            $update->close();

            if (!$ok || $affected === 0) {
                throw new VaultException(
                    'This field was changed in another session. Reload and try again.',
                    VaultException::CONFLICT
                );
            }
        }

        AuditService::getInstance()->log(
            self::MODULE,
            self::ACTION_ITEM_WRITTEN,
            "Employee updated their encrypted '{$group}' data",
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                // Group name and version only - never the ciphertext, the AAD
                // or any plaintext value.
                'metadata'    => ['field_group' => $group, 'version' => $nextVersion],
            ]
        );

        return $nextVersion;
    }

    // =================================================================
    // Grants and requests
    // =================================================================

    /**
     * The active, unexpired grant for a grantee on an employee - or null.
     *
     * expires_at is checked HERE as well as by the cron job. That redundancy
     * is the point: if the cron never runs, the grant still stops working at
     * its expiry. A cron-only check would turn a broken scheduler into an
     * indefinite access grant.
     */
    public function activeGrantFor(int $employeeId, int $granteeUserId): ?array
    {
        $row = \db()->fetchOne(
            "SELECT id, employee_id, grantee_user_id, wrapped_data_key, reason, expires_at, created_at
               FROM vault_grants
              WHERE employee_id = ?
                AND grantee_user_id = ?
                AND status = 'active'
                AND expires_at > NOW()
              LIMIT 1",
            'ii',
            [$employeeId, $granteeUserId]
        );
        return $row;
    }

    /**
     * Read an employee's vault items.
     *
     * OWNER: always allowed.
     * GRANTEE: allowed only with a live grant, and the wrapped data key is
     *         returned so their browser can unwrap it.
     * ANYONE ELSE: VaultException::NOT_FOUND - deliberately 404, not 403, so
     *         the response does not confirm a vault exists.
     */
    public function readItems(int $employeeId, int $userId): array
    {
        $isOwner = $this->employeeIdForUser($userId) === $employeeId;
        $grant   = $isOwner ? null : $this->activeGrantFor($employeeId, $userId);

        if (!$isOwner && $grant === null) {
            AuditService::getInstance()->log(
                self::MODULE,
                self::ACTION_READ_DENIED,
                'Attempt to read an employee vault without an active grant',
                [
                    'target_type' => 'Employee',
                    'target_id'   => $employeeId,
                    'metadata'    => ['grantee_user_id' => $userId],
                    'status'      => 'FAILED',
                ]
            );

            // 404, not 403: a 403 would confirm a vault exists here.
            throw new VaultException('Vault not found.', VaultException::NOT_FOUND);
        }

        $rows = \db()->fetchAll(
            'SELECT field_group, ciphertext, iv, aad, version, updated_at
               FROM vault_items
              WHERE employee_id = ?
              ORDER BY field_group',
            'i',
            [$employeeId]
        );

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'field_group' => (string) $r['field_group'],
                // The browser needs base64 to feed crypto.subtle.
                'ciphertext'  => base64_encode((string) $r['ciphertext']),
                'iv'          => bin2hex((string) $r['iv']),
                'aad'         => (string) $r['aad'],
                'version'     => (int) $r['version'],
                'updated_at'  => (string) $r['updated_at'],
            ];
        }

        if ($isOwner) {
            AuditService::getInstance()->log(
                self::MODULE,
                self::ACTION_VIEWED,
                'Employee unlocked their own vault',
                [
                    'target_type' => 'Employee',
                    'target_id'   => $employeeId,
                    'metadata'    => ['groups' => count($items), 'as' => 'owner'],
                ]
            );
        } else {
            AuditService::getInstance()->log(
                self::MODULE,
                self::ACTION_VIEWED,
                'Granted user read an employee vault under an active grant',
                [
                    'target_type' => 'Employee',
                    'target_id'   => $employeeId,
                    'metadata'    => [
                        'grantee_user_id' => $userId,
                        'grant_id'        => (int) $grant['id'],
                        'expires_at'      => (string) $grant['expires_at'],
                        'groups'          => count($items),
                    ],
                ]
            );
        }

        return [
            'items'    => $items,
            'is_owner' => $isOwner,
            'grant'    => $isOwner ? null : [
                'id'         => (int) $grant['id'],
                'expires_at' => (string) $grant['expires_at'],
                // Wrapped to THIS grantee's public key. The server relays it
                // and cannot unwrap it.
                'wrapped_data_key' => (string) $grant['wrapped_data_key'],
            ],
        ];
    }

    /**
     * Raise an access request. The requester must be someone who is ALLOWED to
     * view the employee in the first place - a vault grant must never become a
     * way around the normal RBAC gate.
     */
    public function requestAccess(int $employeeId, int $requesterUserId, string $reason): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new VaultException('A reason is required.', VaultException::VALIDATION);
        }
        if (mb_strlen($reason) > 500) {
            throw new VaultException('Reason must be 500 characters or fewer.', VaultException::VALIDATION);
        }
        if ($requesterUserId <= 0) {
            throw new VaultException('Invalid requester.', VaultException::VALIDATION);
        }

        // A vault grant must not become a side door around RBAC. If the
        // requester could not open the employee profile at all, they must not
        // be able to request vault contents either.
        if (!$this->requesterMayViewEmployee($employeeId, $requesterUserId)) {
            throw new VaultException(
                'You do not have permission to request access to this employee.',
                VaultException::FORBIDDEN
            );
        }

        // No duplicate open request from the same person.
        $existing = \db()->fetchOne(
            "SELECT id FROM vault_requests
              WHERE employee_id = ? AND requester_user_id = ? AND status = 'pending'
              LIMIT 1",
            'ii',
            [$employeeId, $requesterUserId]
        );
        if ($existing !== null) {
            throw new VaultException(
                'You already have a pending request for this employee.',
                VaultException::CONFLICT
            );
        }

        $id = \db()->insert('vault_requests', [
            'employee_id'       => $employeeId,
            'requester_user_id' => $requesterUserId,
            'reason'            => $reason,
            'status'            => 'pending',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        // Tell the employee WHO is asking, so the decision is informed.
        $this->notifyOwner($employeeId, $requesterUserId, $reason);

        AuditService::getInstance()->log(
            self::MODULE,
            self::ACTION_REQUESTED,
            'Vault access requested',
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                // The reason is requester-supplied justification shown to the
                // employee; it is not decrypted content.
                'metadata'    => [
                    'requester_user_id' => $requesterUserId,
                    'request_id'        => (int) $id,
                    'reason_length'     => mb_strlen($reason),
                ],
            ]
        );

        return (int) $id;
    }

    /**
     * Can this user open the employee's profile at all?
     *
     * Delegates to the existing EmployeePolicy so the vault cannot become a
     * weaker path than the directory.
     */
    private function requesterMayViewEmployee(int $employeeId, int $userId): bool
    {
        try {
            $employee = \db()->fetchOne(
                'SELECT id, employee_id, department_id, section_id, subsection_id, office_id
                   FROM employees WHERE id = ? LIMIT 1',
                'i',
                [$employeeId]
            );
            if ($employee === null) {
                return false;
            }
            return \App\Services\Security\EmployeePolicy::canView($userId, $employee);
        } catch (\Throwable $e) {
            error_log('[VaultService] requesterMayViewEmployee failed: ' . $e->getMessage());
            // Fail closed: an internal error must not become an authorization.
            return false;
        }
    }

    /**
     * Notify the employee that someone wants in, naming the requester.
     *
     * Best effort: a notification failure must not fail the request itself.
     */
    private function notifyOwner(int $employeeId, int $requesterUserId, string $reason): void
    {
        try {
            $ownerUserId = \db()->fetchValue(
                'SELECT u.id
                   FROM users u
                   JOIN employees e ON e.employee_id = u.employee_id
                  WHERE e.id = ? AND u.is_active = 1
                  LIMIT 1',
                'i',
                [$employeeId]
            );
            if ($ownerUserId === null) {
                return;
            }

            $requesterName = \db()->fetchValue(
                "SELECT CONCAT_WS(' ', first_name, last_name) FROM users WHERE id = ? LIMIT 1",
                'i',
                [$requesterUserId]
            );

            NotificationService::getInstance()->sendInApp(
                (int) $ownerUserId,
                'Vault access requested',
                sprintf(
                    '%s has requested access to your private vault. Reason: %s',
                    (string) ($requesterName ?: 'A colleague'),
                    mb_substr($reason, 0, 200)
                ),
                'warning',
                'profile.php?tab=vault'
            );
        } catch (\Throwable $e) {
            error_log('[VaultService] notifyOwner failed: ' . $e->getMessage());
        }
    }

    /**
     * Approve or deny a request. OWNER ONLY.
     *
     * Approval does NOT grant access by itself - it records the decision and
     * notifies the requester. Access only exists once the EMPLOYEE's browser
     * mints a grant (see createGrant). Keeping the two steps apart means a
     * request can be approved and the employee can still decline to wrap the
     * key, which is the whole point of the control.
     */
    public function decideRequest(int $requestId, int $employeeId, string $decision): void
    {
        if (!in_array($decision, ['approved', 'denied'], true)) {
            throw new VaultException('Decision must be approved or denied.', VaultException::VALIDATION);
        }

        $request = \db()->fetchOne(
            'SELECT id, employee_id, requester_user_id, reason, status
               FROM vault_requests WHERE id = ? AND employee_id = ? LIMIT 1',
            'ii',
            [$requestId, $employeeId]
        );

        if ($request === null) {
            // Not found for THIS owner - do not disclose that the id exists.
            throw new VaultException('Request not found.', VaultException::NOT_FOUND);
        }
        if ((string) $request['status'] !== 'pending') {
            throw new VaultException(
                'This request has already been answered.',
                VaultException::CONFLICT
            );
        }

        \db()->update(
            'vault_requests',
            ['status' => $decision, 'decided_at' => date('Y-m-d H:i:s')],
            'id = ? AND employee_id = ? AND status = ?',
            'iis',
            [$requestId, $employeeId, 'pending']
        );

        AuditService::getInstance()->log(
            self::MODULE,
            $decision === 'approved' ? self::ACTION_APPROVED : self::ACTION_DENIED,
            'Employee ' . $decision . ' a vault access request',
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                'metadata'    => [
                    'request_id'        => (int) $requestId,
                    'requester_user_id' => (int) $request['requester_user_id'],
                    'decision'          => $decision,
                ],
            ]
        );

        try {
            NotificationService::getInstance()->sendInApp(
                (int) $request['requester_user_id'],
                'Vault access ' . $decision,
                $decision === 'approved'
                    ? 'Your request to view this employee\'s vault was approved. Their browser must now authorise your key.'
                    : 'Your request to view this employee\'s vault was denied.',
                $decision === 'approved' ? 'success' : 'warning',
                null
            );
        } catch (\Throwable $e) {
            error_log('[VaultService] decideRequest notify failed: ' . $e->getMessage());
        }
    }

    /**
     * Mint a grant. OWNER ONLY. The wrapped key comes from the OWNER's browser
     * and is addressed to the grantee's public key, so the server relays it
     * without ever being able to open it.
     */
    public function createGrant(int $employeeId, int $granteeUserId, string $wrappedDataKey, string $reason): int
    {
        if ($granteeUserId <= 0) {
            throw new VaultException('Invalid grantee.', VaultException::VALIDATION);
        }
        if (trim($wrappedDataKey) === '') {
            throw new VaultException('Missing wrapped data key.', VaultException::VALIDATION);
        }

        // Never grant the owner access to their own vault - they have it already.
        if ($this->employeeIdForUser($granteeUserId) === $employeeId) {
            throw new VaultException(
                'You already have access to your own vault.',
                VaultException::VALIDATION
            );
        }

        $active = (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM vault_grants
              WHERE employee_id = ? AND grantee_user_id = ?
                AND status = 'active' AND expires_at > NOW()",
            'ii',
            [$employeeId, $granteeUserId]
        );
        if ($active > 0) {
            throw new VaultException(
                'That person already has an active grant.',
                VaultException::CONFLICT
            );
        }

        // Cap standing access so a compromised session cannot accumulate it.
        $max = (int) $this->config('max_active_grants', 5);
        $total = (int) \db()->fetchValue(
            "SELECT COUNT(*) FROM vault_grants
              WHERE employee_id = ? AND status = 'active' AND expires_at > NOW()",
            'i',
            [$employeeId]
        );
        if ($total >= $max) {
            throw new VaultException(
                "You already have {$max} active grants. Revoke one before adding another.",
                VaultException::CONFLICT
            );
        }

        $ttlHours  = max(1, (int) $this->config('grant_ttl_hours', 24));
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $id = \db()->insert('vault_grants', [
            'employee_id'      => $employeeId,
            'grantee_user_id'  => $granteeUserId,
            'wrapped_data_key' => $wrappedDataKey,
            'reason'           => trim($reason) !== '' ? trim($reason) : null,
            'status'           => 'active',
            'expires_at'       => $expiresAt,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        AuditService::getInstance()->log(
            self::MODULE,
            self::ACTION_GRANTED,
            'Employee granted a user access to their vault',
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                // Grant id, grantee, expiry. NEVER the wrapped key itself.
                'metadata'    => [
                    'grant_id'        => (int) $id,
                    'grantee_user_id' => $granteeUserId,
                    'expires_at'      => $expiresAt,
                ],
            ]
        );

        return (int) $id;
    }

    /**
     * Revoke a grant. OWNER ONLY, at any time, regardless of expiry.
     *
     * The row is retained (status changes; the wrapped key is not deleted) so
     * the audit trail stays coherent and a revoke can be investigated rather
     * than silently disappearing.

    // =================================================================
    // Owner-facing listings
    // =================================================================

    /**
     * Pending and decided requests for the employee's own vault.
     */
    public function requestsFor(int $employeeId): array
    {
        $rows = \db()->fetchAll(
            "SELECT r.id, r.requester_user_id, r.reason, r.status, r.created_at, r.decided_at,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS requester_name,
                    u.role AS requester_role
               FROM vault_requests r
               LEFT JOIN users u ON u.id = r.requester_user_id
              WHERE r.employee_id = ?
              ORDER BY r.created_at DESC
              LIMIT 100",
            'i',
            [$employeeId]
        );

        return array_map(static fn(array $r): array => [
            'id'                => (int) $r['id'],
            'requester_user_id' => (int) $r['requester_user_id'],
            // The employee MUST see WHO is asking, not just that someone is.
            'requester_name'    => (string) ($r['requester_name'] ?? 'Unknown'),
            'requester_role'    => (string) ($r['requester_role'] ?? ''),
            'reason'            => (string) $r['reason'],
            'status'            => (string) $r['status'],
            'created_at'        => (string) $r['created_at'],
            'decided_at'        => $r['decided_at'] !== null ? (string) $r['decided_at'] : null,
        ], $rows);
    }

    /**
     * Grants the employee has issued, with live/expired resolved.
     */
    public function grantsFor(int $employeeId): array
    {
        $rows = \db()->fetchAll(
            "SELECT g.id, g.grantee_user_id, g.reason, g.status, g.expires_at, g.created_at, g.revoked_at,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS grantee_name,
                    u.role AS grantee_role,
                    (g.status = 'active' AND g.expires_at > NOW()) AS is_live
               FROM vault_grants g
               LEFT JOIN users u ON u.id = g.grantee_user_id
              WHERE g.employee_id = ?
              ORDER BY g.created_at DESC
              LIMIT 100",
            'i',
            [$employeeId]
        );

        return array_map(static fn(array $r): array => [
            'id'              => (int) $r['id'],
            'grantee_user_id' => (int) $r['grantee_user_id'],
            'grantee_name'    => (string) ($r['grantee_name'] ?? 'Unknown'),
            'grantee_role'    => (string) ($r['grantee_role'] ?? ''),
            'reason'          => $r['reason'] !== null ? (string) $r['reason'] : null,
            'status'          => (string) $r['status'],
            'is_live'         => (int) $r['is_live'] === 1,
            'expires_at'      => (string) $r['expires_at'],
            'created_at'      => (string) $r['created_at'],
            'revoked_at'      => $r['revoked_at'] !== null ? (string) $r['revoked_at'] : null,
            // The wrapped key is deliberately NOT returned in a listing; the
            // grantee receives it only through the guarded read path.
        ], $rows);
    }

    /**
     * The state of the vault for one employee, from another user's point of
     * view. This is what the HR UI renders INSTEAD of a value.
     *
     * A locked field is never reported as an empty string: an empty value
     * reads as "no data on file", whereas a locked value reads as "data
     * exists, ask for it". That distinction is the entire UX of the control.
     *
     * @return array{state:string, can_request:bool, expires_at:?string, pending:?array}
     */
    public function lockState(int $employeeId, int $viewerUserId): array
    {
        if (!$this->hasVault($employeeId)) {
            return [
                'state'       => 'not_set_up',
                'can_request' => false,
                'expires_at'  => null,
                'pending'     => null,
            ];
        }

        $grant = $this->activeGrantFor($employeeId, $viewerUserId);
        if ($grant !== null) {
            return [
                'state'       => 'granted',
                'can_request' => false,
                'expires_at'  => (string) $grant['expires_at'],
                'pending'     => null,
            ];
        }

        $pending = \db()->fetchOne(
            "SELECT id FROM vault_requests
              WHERE employee_id = ? AND requester_user_id = ? AND status = 'pending'
              LIMIT 1",
            'ii',
            [$employeeId, $viewerUserId]
        );

        return [
            'state'       => $pending !== null ? 'pending' : 'locked',
            'can_request' => $pending === null,
            'expires_at'  => null,
            'pending'     => $pending !== null ? ['id' => (int) $pending['id']] : null,
        ];
    }

    // =================================================================
    // Expiry sweep (cron)
    // =================================================================

    /**
     * Flip lapsed grants to 'expired' and audit each one.
     *
     * Housekeeping, NOT the security boundary: activeGrantFor() already
     * refuses an expired grant, so access stops at the expiry whether or not
     * this ever runs. Running it only keeps the listing honest - a broken
     * scheduler degrades the display, never the control.
     *
     * @return int number of grants expired
     */
    public function expireLapsedGrants(): int
    {
        $lapsed = \db()->fetchAll(
            "SELECT id, employee_id, grantee_user_id, expires_at
               FROM vault_grants
              WHERE status = 'active' AND expires_at <= NOW()"
        );

        if (empty($lapsed)) {
            return 0;
        }

        $expired = 0;
        foreach ($lapsed as $g) {
            \db()->update(
                'vault_grants',
                ['status' => 'expired'],
                'id = ? AND status = ?',
                'is',
                [(int) $g['id'], 'active']
            );
            $expired++;

            AuditService::getInstance()->log(
                self::MODULE,
                self::ACTION_EXPIRED,
                'A vault access grant expired automatically',
                [
                    'target_type' => 'Employee',
                    'target_id'   => (int) $g['employee_id'],
                    'metadata'    => [
                        'grant_id'        => (int) $g['id'],
                        'grantee_user_id' => (int) $g['grantee_user_id'],
                        'expired_at'      => (string) $g['expires_at'],
                    ],
                ]
            );
        }

        return $expired;
    }

    /**
     * Revoke a grant. OWNER ONLY, at any time, regardless of expiry.
     *
     * The row is retained (status changes; the wrapped key is not deleted) so
     * the audit trail stays coherent and a revoke can be investigated rather
     * than silently disappearing.
     */
    public function revokeGrant(int $grantId, int $employeeId): void
    {
        $grant = \db()->fetchOne(
            'SELECT id, grantee_user_id, status
               FROM vault_grants
              WHERE id = ? AND employee_id = ?
              LIMIT 1',
            'ii',
            [$grantId, $employeeId]
        );
        if ($grant === null) {
            // Not scoped to this owner - do not confirm the id exists.
            throw new VaultException('Grant not found.', VaultException::NOT_FOUND);
        }
        if ((string) $grant['status'] !== 'active') {
            throw new VaultException('That grant is no longer active.', VaultException::CONFLICT);
        }

        \db()->update(
            'vault_grants',
            ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
            'id = ? AND employee_id = ?',
            'ii',
            [$grantId, $employeeId]
        );

        AuditService::getInstance()->log(
            self::MODULE,
            self::ACTION_REVOKED,
            'Employee revoked a vault access grant',
            [
                'target_type' => 'Employee',
                'target_id'   => $employeeId,
                'metadata'    => [
                    'grant_id'        => (int) $grantId,
                    'grantee_user_id' => (int) $grant['grantee_user_id'],
                ],
            ]
        );
    }
}

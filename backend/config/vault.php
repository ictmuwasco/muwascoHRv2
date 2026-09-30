<?php

declare(strict_types=1);

/**
 * Private Vault configuration (PART A).
 *
 * This file is the SINGLE SOURCE OF TRUTH for what goes into the vault. The
 * database stores ciphertext keyed by `field_group` (a config key, not a
 * column name), so changing the scope here needs no migration.
 *
 * ---------------------------------------------------------------------------
 * THE TWO LISTS, AND WHY BOTH EXIST
 * ---------------------------------------------------------------------------
 * `vault_groups`   fields whose PLAINTEXT is moved into the vault. Once the
 *                  employee encrypts them, the plaintext column is blanked, so
 *                  the server (and anyone with a database dump) cannot read
 *                  them.
 * `plaintext`      fields that STAY readable in `employees`. Other modules
 *                  depend on them, and encrypting them would break those
 *                  modules rather than protect anything.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT HERE, AND WHAT IT COSTS
 * ---------------------------------------------------------------------------
 * national_id  - EmployeeRepository::nationalIdExists() enforces uniqueness
 *               with `WHERE national_id = ?`. Once the value is vaulted and
 *               the column blanked, the SERVER CANNOT perform that check.
 *               This is a real, accepted loss of a server-side integrity
 *               control. The browser checks for duplicates against the vault
 *               before submitting. A blind index (server-side HMAC) would
 *               restore the guarantee but leak equality of national IDs to
 *               anyone holding the key, so it is not implemented by default.
 *
 * phone        - employees.phone is the ONLY phone number in the system and is
 *               the source for SMS/push (NotificationDispatcher,
 *               UserPreferenceService, LeaveNotificationService,
 *               MeetingNotificationService, AppraisalWorkflowService,
 *               AttendanceReminderEligibilityService). Moving it into a
 *               zero-knowledge vault means the server can no longer read it, so
 *               those channels stop resolving a recipient. Accepted and
 *               intended - see PRIVATE_VAULT.md.
 *
 * salary       - gated behind `include_salary`, default FALSE. The payroll
 *               module was removed in migration 102 and no application code
 *               reads employees.salary, so flipping this on has no code paths
 *               to update. HR keeps salary visibility by default.
 *
 * ---------------------------------------------------------------------------
 * SCOPE CAVEAT
 * ---------------------------------------------------------------------------
 * Vaulting removes these fields from reports, search, and the AI assistant.
 * The two AI tools that touch employee data (GetMyEmployeeProfileTool,
 * SearchEmployeeDirectoryTool) already filter every field listed here, so this
 * continues existing behaviour rather than regressing it.
 */

return [
    /**
     * Master switch. When false the vault endpoints refuse and the profile
     * keeps serving legacy plaintext untouched.
     */
    'enabled' => filter_var(
        \env('VAULT_ENABLED', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * When true, salary moves into the vault and is no longer readable by HR.
     * Default false: HR retains salary visibility.
     */
    'include_salary' => filter_var(
        \env('VAULT_INCLUDE_SALARY', 'false'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /**
     * field_group => columns whose plaintext is blanked after vaulting.
     *
     * `documents` holds browser-encrypted file blobs and therefore has no
     * plaintext column to blank; it is listed so the group is created.
     */
    'vault_groups' => [
        'personal'    => ['national_id', 'date_of_birth', 'gender', 'marital_status', 'address', 'phone'],
        'next_of_kin' => ['next_of_kin'],
        'dependants'  => ['dependants'],
        'documents'   => [],
    ],

    /**
     * Columns that stay plaintext because other modules read them.
     */
    'plaintext' => [
        'first_name', 'last_name', 'surname', 'employee_id', 'email',
        'designation', 'position', 'department_id', 'section_id', 'subsection_id',
        'office_id', 'employee_type', 'employment_type', 'employee_status',
        'hire_date', 'contract_start_date', 'contract_end_date', 'scale_id',
        'profile_image_url',
    ],


    /**
     * Password/KDF parameters.
     *
     * PBKDF2-HMAC-SHA256 at 600,000 iterations is the current OWASP
     * recommendation for PBKDF2. Argon2id is preferable but no vetted WASM
     * build is a dependency of this project, and adding one is a supply-chain
     * decision that should be taken deliberately, not as a side effect.
     * These values are recorded per-vault in vault_keys.kdf_params so a future
     * increase can re-derive and verify against existing rows.
     */
    'kdf' => [
        'algorithm'  => 'PBKDF2',
        'hash'       => 'SHA-256',
        'iterations' => (int) \env('VAULT_KDF_ITERATIONS', 600000),
        'key_length' => 32,
    ],

    /**
     * How long an approved grant stays readable. The employee can revoke at
     * any time regardless of this.
     */
    'grant_ttl_hours' => (int) \env('VAULT_GRANT_TTL_HOURS', 24),

    /**
     * Cap on simultaneous active grants per employee, so a compromised HR
     * session cannot accumulate standing access.
     */
    'max_active_grants' => (int) \env('VAULT_MAX_ACTIVE_GRANTS', 5),

    /**
     * Hours an access request stays open before it stops being actionable.
     */
    'request_ttl_hours' => (int) \env('VAULT_REQUEST_TTL_HOURS', 168),

    /**
     * Client-side auto-lock. Keys are held in memory only, so this bounds how
     * long an unlocked vault is reachable after the user walks away.
     */
    'auto_lock_seconds' => (int) \env('VAULT_AUTO_LOCK_SECONDS', 300),

    /**
     * Recovery code shape: 6 groups of 8 Crockford base-32 characters.
     */
    'recovery_groups' => 6,
    'recovery_group_length' => 8,
];

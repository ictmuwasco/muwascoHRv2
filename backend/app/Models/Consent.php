<?php

declare(strict_types=1);

namespace App\Models;


class Consent
{
    /**
     * Current active consent version.
     * Bump this when the Data Protection Notice is updated to force renewed consent.
     */
    public const CURRENT_VERSION = '1.0';

    /**
     * Check if a user has given their data protection consent for any version.
     *
     * @param int $userId
     * @return bool
     */
    public function hasConsent(int $userId): bool
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare(
            "SELECT id FROM user_consents WHERE user_id = ? AND consent_given = 1 LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Check if a user has accepted the given consent version.
     * This is the authoritative check - the database is the source of truth.
     *
     * @param int    $userId
     * @param string $version
     * @return bool
     */
    public function hasAcceptedVersion(int $userId, string $version): bool
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare(
            "SELECT id FROM user_consents
             WHERE user_id = ? AND consent_given = 1 AND consent_version = ?
             LIMIT 1"
        );
        $stmt->bind_param("is", $userId, $version);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Get consent record for a user and version.
     *
     * @param int    $userId
     * @param string $version
     * @return array|null
     */
    public function findAcceptedVersion(int $userId, string $version): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare(
            "SELECT id, user_id, full_name, national_id, consent_given,
                    consent_version, consent_date, ip_address, user_agent
             FROM user_consents WHERE user_id = ? AND consent_version = ?
             LIMIT 1"
        );
        $stmt->bind_param("is", $userId, $version);
        $stmt->execute();
        $result = $stmt->get_result();
        $data   = $result->fetch_assoc();
        $stmt->close();

        return $data ?: null;
    }

    /**
     * Get consent record for a user (any version, most recent).
     *
     * @param int $userId
     * @return array|null
     */
    public function findByUserId(int $userId): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare(
            "SELECT id, user_id, full_name, national_id, consent_given,
                    consent_version, consent_date, ip_address, user_agent
             FROM user_consents WHERE user_id = ? ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data   = $result->fetch_assoc();
        $stmt->close();

        return $data ?: null;
    }

    /**
     * Save or update consent for a user (legacy method, uses current version).
     *
     * @param int    $userId
     * @param string $fullName
     * @param string $nationalId
     * @return bool
     */
    public function saveConsent(int $userId, string $fullName, string $nationalId): bool
    {
        return $this->saveConsentWithVersion($userId, $fullName, $nationalId, self::CURRENT_VERSION);
    }

    /**
     * Save or update consent for a user with a specific version.
     * Prevents duplicate records for the same user + version via the
     * unique key (user_id, consent_version).
     *
     * @param int    $userId
     * @param string $fullName
     * @param string $nationalId
     * @param string $version
     * @return bool
     */
    public function saveConsentWithVersion(int $userId, string $fullName, string $nationalId, string $version): bool
    {
        $conn      = \App\Helpers\Database::getInstance()->getConnection();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

        try {
            $conn->begin_transaction();

            // Check if consent already exists for this user + version
            $check = $conn->prepare(
                "SELECT id FROM user_consents WHERE user_id = ? AND consent_version = ?"
            );
            $check->bind_param("is", $userId, $version);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                // Already consented to this version - no duplicate created
                $conn->commit();
                return true;
            }

            $stmt = $conn->prepare(
                "INSERT INTO user_consents
                     (user_id, full_name, national_id, consent_version, consent_given, consent_date, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)"
            );
            $stmt->bind_param("isssss", $userId, $fullName, $nationalId, $version, $ipAddress, $userAgent);

            $success = $stmt->execute();
            $stmt->close();
            $conn->commit();

            return $success;

        } catch (\Exception $e) {
            $conn->rollback();
            error_log("Consent save failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify a user's National ID against the employees table.
     *
     * @param int    $userId
     * @param string $nationalId
     * @return array  ['success' => bool, 'error' => string, 'employee_id' => ?string]
     */
    public function verifyNationalId(int $userId, string $nationalId): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        try {
            $stmt = $conn->prepare("
                SELECT u.employee_id, e.national_id, e.first_name, e.last_name
                FROM users u
                LEFT JOIN employees e ON u.employee_id = e.employee_id
                WHERE u.id = ? AND e.employee_status = 'active'
                LIMIT 1
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                return ['success' => false, 'error' => 'User/employee record not found or inactive.'];
            }

            $employeeData         = $result->fetch_assoc();
            $storedNationalId     = $employeeData['national_id'];
            $cleanedEnteredId     = (int)preg_replace('/[^0-9]/', '', $nationalId);
            $cleanedStoredId      = (int)$storedNationalId;

            if (empty($storedNationalId)) {
                return ['success' => false, 'error' => 'National ID not found in employee record. Contact HR.'];
            }

            if ($cleanedStoredId !== $cleanedEnteredId) {
                return ['success' => false, 'error' => 'National ID does not match our records.'];
            }

            return [
                'success'      => true,
                'employee_id'  => $employeeData['employee_id'],
                'verified_national_id' => $storedNationalId,
            ];

        } catch (\Exception $e) {
            error_log("National ID verification error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Verification system error.'];
        }
    }

    /**
     * Verify a user's Employee ID against the employees table.
     * The Employee ID supplied by the frontend is verified server-side
     * against the authenticated user's linked employee record.
     *
     * @param int    $userId
     * @param string $employeeId
     * @return array  ['success' => bool, 'error' => string, 'employee_data' => ?array]
     */
    public function verifyEmployeeId(int $userId, string $employeeId): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        try {
            $stmt = $conn->prepare("
                SELECT u.employee_id, e.id AS employee_pk, e.national_id, e.first_name, e.last_name, e.employee_status
                FROM users u
                LEFT JOIN employees e ON u.employee_id = e.employee_id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                return ['success' => false, 'error' => 'User record not found.'];
            }

            $employeeData = $result->fetch_assoc();

            // User has no linked employee record
            if (empty($employeeData['employee_id']) || empty($employeeData['employee_pk'])) {
                return ['success' => false, 'error' => 'No employee record is linked to this account. Contact HR.'];
            }

            // Employee must be active to provide consent
            if ($employeeData['employee_status'] !== 'active') {
                return ['success' => false, 'error' => 'Employee record is not active. Contact HR.'];
            }

            // Compare normalized Employee IDs (case-insensitive)
            $enteredId   = strtolower(trim($employeeId));
            $storedId    = strtolower(trim((string) $employeeData['employee_id']));

            if ($enteredId === '') {
                return ['success' => false, 'error' => 'Employee ID is required.'];
            }

            if ($storedId !== $enteredId) {
                return ['success' => false, 'error' => 'Employee ID does not match our records.'];
            }

            return [
                'success'      => true,
                'employee_id'  => $employeeData['employee_id'],
                'employee_data' => [
                    'id'         => (int) $employeeData['employee_pk'],
                    'first_name' => $employeeData['first_name'],
                    'last_name'  => $employeeData['last_name'],
                ],
            ];

        } catch (\Exception $e) {
            error_log("Employee ID verification error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Verification system error.'];
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Models;


class Consent
{
    /**
     * Check if a user has given their data protection consent.
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
     * Get consent record for a user.
     *
     * @param int $userId
     * @return array|null
     */
    public function findByUserId(int $userId): ?array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $stmt = $conn->prepare(
            "SELECT id, user_id, full_name, national_id, consent_given,
                    consent_date, ip_address, user_agent
             FROM user_consents WHERE user_id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data   = $result->fetch_assoc();
        $stmt->close();

        return $data ?: null;
    }

    /**
     * Save or update consent for a user.
     *
     * @param int    $userId
     * @param string $fullName
     * @param string $nationalId
     * @return bool
     */
    public function saveConsent(int $userId, string $fullName, string $nationalId): bool
    {
        $conn      = \App\Helpers\Database::getInstance()->getConnection();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

        try {
            $conn->begin_transaction();

            // Check if consent already exists
            $check = $conn->prepare("SELECT id FROM user_consents WHERE user_id = ?");
            $check->bind_param("i", $userId);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                $stmt = $conn->prepare(
                    "UPDATE user_consents
                     SET full_name = ?, national_id = ?, consent_given = 1,
                         consent_date = NOW(), ip_address = ?, user_agent = ?
                     WHERE user_id = ?"
                );
                $stmt->bind_param("ssssi", $fullName, $nationalId, $ipAddress, $userAgent, $userId);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO user_consents
                         (user_id, full_name, national_id, consent_given, consent_date, ip_address, user_agent)
                     VALUES (?, ?, ?, 1, NOW(), ?, ?)"
                );
                $stmt->bind_param("issss", $userId, $fullName, $nationalId, $ipAddress, $userAgent);
            }

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
}

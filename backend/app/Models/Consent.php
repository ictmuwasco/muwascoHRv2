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
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            // First check if table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE 'user_consents'");
            if ($tableCheck->num_rows === 0) {
                error_log("user_consents table does not exist");
                return false;
            }
            $tableCheck->close();

            // Check if consent_version column exists
            $columnCheck = $conn->query("SHOW COLUMNS FROM user_consents LIKE 'consent_version'");
            if ($columnCheck->num_rows === 0) {
                error_log("consent_version column does not exist, using legacy check");
                // Fallback to legacy check without version
                $stmt = $conn->prepare(
                    "SELECT id, consent_given FROM user_consents
                     WHERE user_id = ? AND consent_given = 1
                     LIMIT 1"
                );
                if (!$stmt) {
                    error_log("Prepare failed: " . $conn->error);
                    return false;
                }
                
                $stmt->bind_param("i", $userId);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result->fetch_assoc();
                $stmt->close();
                
                return $row && $row['consent_given'] == 1;
            }
            $columnCheck->close();

            $stmt = $conn->prepare(
                "SELECT id, consent_given, consent_version FROM user_consents
                 WHERE user_id = ? AND consent_version = ?
                 LIMIT 1"
            );
            if (!$stmt) {
                error_log("Prepare failed: " . $conn->error);
                return false;
            }
            
            $stmt->bind_param("is", $userId, $version);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            error_log("Consent check for user $userId, version $version: " . ($row ? json_encode($row) : 'no record found'));
            
            return $row && $row['consent_given'] == 1;
        } catch (\Exception $e) {
            error_log("Consent query error: " . $e->getMessage());
            return false;
        }
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
            // Check if table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE 'user_consents'");
            if ($tableCheck->num_rows === 0) {
                error_log("user_consents table does not exist");
                return false;
            }
            $tableCheck->close();

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

            // Check if consent_version column exists
            $columnCheck = $conn->query("SHOW COLUMNS FROM user_consents LIKE 'consent_version'");
            $hasVersionColumn = $columnCheck->num_rows > 0;
            $columnCheck->close();

            if ($hasVersionColumn) {
                $stmt = $conn->prepare(
                    "INSERT INTO user_consents
                         (user_id, full_name, national_id, consent_version, consent_given, consent_date, ip_address, user_agent)
                     VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)"
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO user_consents
                         (user_id, full_name, national_id, consent_given, consent_date, ip_address, user_agent)
                     VALUES (?, ?, ?, 1, NOW(), ?, ?)"
                );
            }
            if ($hasVersionColumn) {
                $stmt->bind_param("isssss", $userId, $fullName, $nationalId, $version, $ipAddress, $userAgent);
            } else {
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
                'employee_data' => [
                    'first_name' => $employeeData['first_name'],
                    'last_name'  => $employeeData['last_name'],
                    'national_id' => $storedNationalId,
                ],
            ];

        } catch (\Exception $e) {
            error_log("National ID verification error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Verification system error.'];
        }
    }

    /**
     * Get HR dashboard statistics for consent management.
     *
     * @return array  ['total_employees' => int, 'consented' => int, 'pending' => int, 'declined' => int, 'consent_rate' => float]
     */
    public function getDashboardStats(): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        try {
            // Get total active employees
            $totalStmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM employees
                WHERE employee_status = 'active'
            ");
            $totalStmt->execute();
            $totalResult = $totalStmt->get_result()->fetch_assoc();
            $totalStmt->close();
            $totalEmployees = (int)($totalResult['count'] ?? 0);

            // Get consented employees (those who have accepted the current version)
            $consentedStmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM user_consents uc
                INNER JOIN users u ON uc.user_id = u.id
                INNER JOIN employees e ON u.employee_id = e.employee_id
                WHERE uc.consent_version = ?
                AND uc.consent_given = 1
                AND e.employee_status = 'active'
            ");
            $currentVersion = self::CURRENT_VERSION;
            $consentedStmt->bind_param("s", $currentVersion);
            $consentedStmt->execute();
            $consentedResult = $consentedStmt->get_result()->fetch_assoc();
            $consentedStmt->close();
            $consented = (int)($consentedResult['count'] ?? 0);

            // Calculate pending and declined
            $pending = $totalEmployees - $consented;
            $declined = 0; // Database doesn't track declined separately

            // Calculate consent rate
            $consentRate = $totalEmployees > 0 ? round(($consented / $totalEmployees) * 100, 2) : 0;

            return [
                'total_employees' => $totalEmployees,
                'consented' => $consented,
                'pending' => $pending,
                'declined' => $declined,
                'consent_rate' => $consentRate,
            ];
        } catch (\Exception $e) {
            error_log("Dashboard stats error: " . $e->getMessage());
            return [
                'total_employees' => 0,
                'consented' => 0,
                'pending' => 0,
                'declined' => 0,
                'consent_rate' => 0,
            ];
        }
    }

    /**
     * Get employee consent listing with filtering, search, and pagination.
     *
     * @param array $filters  ['status' => string, 'department_id' => int, 'section_id' => int, 'search' => string, 'date_from' => string, 'date_to' => string, 'page' => int, 'per_page' => int]
     * @return array  ['employees' => array, 'pagination' => array, 'departments' => array, 'sections' => array, 'versions' => array]
     */
    public function getEmployeeConsentList(array $filters = []): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        // Default filters
        $status = $filters['status'] ?? 'all';
        $departmentId = $filters['department_id'] ?? null;
        $sectionId = $filters['section_id'] ?? null;
        $search = $filters['search'] ?? '';
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int)($filters['per_page'] ?? 25)));
        $currentVersion = self::CURRENT_VERSION;

        $offset = ($page - 1) * $perPage;

        try {
            // Build dynamic query
            $whereConditions = ["e.employee_status = 'active'"];
            $params = [];
            $types = '';

            // Status filter
            if ($status !== 'all') {
                if ($status === 'consented') {
                    $whereConditions[] = 'uc.consent_given = 1 AND uc.consent_version = ?';
                    $params[] = self::CURRENT_VERSION;
                    $types .= 's';
                } elseif ($status === 'pending') {
                    $whereConditions[] = 'uc.id IS NULL OR uc.consent_version != ?';
                    $params[] = self::CURRENT_VERSION;
                    $types .= 's';
                }
            } else {
                // For 'all', still join with current version consent
                $whereConditions[] = 'uc.consent_version = ?';
                $params[] = self::CURRENT_VERSION;
                $types .= 's';
            }

            // Department filter
            if ($departmentId) {
                $whereConditions[] = 'e.department_id = ?';
                $params[] = $departmentId;
                $types .= 'i';
            }

            // Section filter
            if ($sectionId) {
                $whereConditions[] = 'e.section_id = ?';
                $params[] = $sectionId;
                $types .= 'i';
            }

            // Search filter
            if ($search !== '') {
                $whereConditions[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)';
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $types .= 'ssss';
            }

            // Date range filter
            if ($dateFrom) {
                $whereConditions[] = 'uc.consent_date >= ?';
                $params[] = $dateFrom . ' 00:00:00';
                $types .= 's';
            }
            if ($dateTo) {
                $whereConditions[] = 'uc.consent_date <= ?';
                $params[] = $dateTo . ' 23:59:59';
                $types .= 's';
            }

            $whereClause = implode(' AND ', $whereConditions);

            // Get departments for filter dropdown
            $deptStmt = $conn->prepare("
                SELECT DISTINCT d.id, d.name
                FROM departments d
                INNER JOIN employees e ON e.department_id = d.id
                WHERE e.employee_status = 'active'
                ORDER BY d.name ASC
            ");
            $deptStmt->execute();
            $departments = $deptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $deptStmt->close();

            // Get sections for filter dropdown
            $sectionStmt = $conn->prepare("
                SELECT DISTINCT s.id, s.name, s.department_id
                FROM sections s
                INNER JOIN employees e ON e.section_id = s.id
                WHERE e.employee_status = 'active'
                ORDER BY s.name ASC
            ");
            $sectionStmt->execute();
            $sections = $sectionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sectionStmt->close();

            // Get available consent versions
            $versionStmt = $conn->prepare("
                SELECT DISTINCT consent_version
                FROM user_consents
                ORDER BY consent_version DESC
            ");
            $versionStmt->execute();
            $versions = array_column($versionStmt->get_result()->fetch_all(MYSQLI_ASSOC), 'consent_version');
            $versionStmt->close();

            // Count total matching records
            $countQuery = "
                SELECT COUNT(*) as total
                FROM employees e
                LEFT JOIN users u ON e.employee_id = u.employee_id
                LEFT JOIN user_consents uc ON u.id = uc.user_id AND {$whereClause}
                WHERE {$whereClause}
            ";

            // Remove the duplicate where clause for count
            $countQuery = "
                SELECT COUNT(*) as total
                FROM employees e
                LEFT JOIN users u ON e.employee_id = u.employee_id
                LEFT JOIN user_consents uc ON u.id = uc.user_id AND uc.consent_version = ?
                WHERE e.employee_status = 'active'
            ";

            // Rebuild for count with proper params
            $countParams = [$currentVersion];
            $countTypes = 's';

            if ($status !== 'all') {
                if ($status === 'consented') {
                    $countQuery .= " AND uc.consent_given = 1";
                } elseif ($status === 'pending') {
                    $countQuery .= " AND (uc.id IS NULL OR uc.consent_given = 0)";
                }
            }

            if ($departmentId) {
                $countQuery .= " AND e.department_id = ?";
                $countParams[] = $departmentId;
                $countTypes .= 'i';
            }
            if ($sectionId) {
                $countQuery .= " AND e.section_id = ?";
                $countParams[] = $sectionId;
                $countTypes .= 'i';
            }
            if ($search !== '') {
                $countQuery .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
                $searchTerm = "%{$search}%";
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countTypes .= 'ssss';
            }
            if ($dateFrom) {
                $countQuery .= " AND uc.consent_date >= ?";
                $countParams[] = $dateFrom . ' 00:00:00';
                $countTypes .= 's';
            }
            if ($dateTo) {
                $countQuery .= " AND uc.consent_date <= ?";
                $countParams[] = $dateTo . ' 23:59:59';
                $countTypes .= 's';
            }

            $countStmt = $conn->prepare($countQuery);
            $countStmt->bind_param($countTypes, ...$countParams);
            $countStmt->execute();
            $totalResult = $countStmt->get_result()->fetch_assoc();
            $totalRecords = (int)($totalResult['total'] ?? 0);
            $totalPages = (int)ceil($totalRecords / $perPage);
            $countStmt->close();

            // Build data query
            $dataQuery = "
                SELECT
                    e.employee_id,
                    e.first_name,
                    e.last_name,
                    e.email,
                    e.gender,
                    d.name as department_name,
                    s.name as section_name,
                    uc.consent_given,
                    uc.consent_version,
                    uc.consent_date,
                    uc.id as consent_id
                FROM employees e
                LEFT JOIN users u ON e.employee_id = u.employee_id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN user_consents uc ON u.id = uc.user_id AND uc.consent_version = ?
                WHERE e.employee_status = 'active'
            ";

            // Add filters to data query
            if ($status !== 'all') {
                if ($status === 'consented') {
                    $dataQuery .= " AND uc.consent_given = 1";
                } elseif ($status === 'pending') {
                    $dataQuery .= " AND (uc.id IS NULL OR uc.consent_given = 0)";
                }
            }

            if ($departmentId) {
                $dataQuery .= " AND e.department_id = ?";
            }
            if ($sectionId) {
                $dataQuery .= " AND e.section_id = ?";
            }
            if ($search !== '') {
                $dataQuery .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
            }
            if ($dateFrom) {
                $dataQuery .= " AND uc.consent_date >= ?";
            }
            if ($dateTo) {
                $dataQuery .= " AND uc.consent_date <= ?";
            }

            $dataQuery .= " ORDER BY e.first_name ASC, e.last_name ASC LIMIT ? OFFSET ?";

            $dataStmt = $conn->prepare($dataQuery);

            // Build bind params for data query
            $dataParams = [$currentVersion];
            $dataTypes = 's';

            if ($departmentId) {
                $dataParams[] = $departmentId;
                $dataTypes .= 'i';
            }
            if ($sectionId) {
                $dataParams[] = $sectionId;
                $dataTypes .= 'i';
            }
            if ($search !== '') {
                $searchTerm = "%{$search}%";
                $dataParams[] = $searchTerm;
                $dataParams[] = $searchTerm;
                $dataParams[] = $searchTerm;
                $dataParams[] = $searchTerm;
                $dataTypes .= 'ssss';
            }
            if ($dateFrom) {
                $dataParams[] = $dateFrom . ' 00:00:00';
                $dataTypes .= 's';
            }
            if ($dateTo) {
                $dataParams[] = $dateTo . ' 23:59:59';
                $dataTypes .= 's';
            }
            $dataParams[] = $perPage;
            $dataParams[] = $offset;
            $dataTypes .= 'ii';

            $dataStmt->bind_param($dataTypes, ...$dataParams);
            $dataStmt->execute();
            $result = $dataStmt->get_result();
            $employees = $result->fetch_all(MYSQLI_ASSOC);
            $dataStmt->close();

            // Format employee data
            $formattedEmployees = [];
            foreach ($employees as $emp) {
                $hasConsent = !empty($emp['consent_given']) && $emp['consent_given'] == 1;
                $formattedEmployees[] = [
                    'employee_id' => $emp['employee_id'],
                    'first_name' => $emp['first_name'],
                    'last_name' => $emp['last_name'],
                    'email' => $emp['email'],
                    'gender' => $emp['gender'],
                    'department' => $emp['department_name'] ?? 'N/A',
                    'section' => $emp['section_name'] ?? 'N/A',
                    'consent_status' => $hasConsent ? 'consented' : 'pending',
                    'consent_version' => $emp['consent_version'] ?? 'N/A',
                    'consent_date' => $emp['consent_date'] ? date('Y-m-d H:i:s', strtotime($emp['consent_date'])) : null,
                ];
            }

            return [
                'employees' => $formattedEmployees,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $totalRecords,
                    'total_pages' => $totalPages,
                ],
                'departments' => $departments,
                'sections' => $sections,
                'versions' => $versions,
            ];
        } catch (\Exception $e) {
            error_log("Employee consent list error: " . $e->getMessage());
            return [
                'employees' => [],
                'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'total_pages' => 0],
                'departments' => [],
                'sections' => [],
                'versions' => [],
            ];
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
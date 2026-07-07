<?php

declare(strict_types=1);

namespace App\Services;

/**
 * DelegateService
 *
 * Handles task delegation logic for leave management.
 * Determines eligible delegates based on organizational hierarchy.
 */
class DelegateService
{
    private \mysqli $conn;
    private AuditService $audit;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
        $this->audit = AuditService::getInstance();
    }

    /**
     * Get eligible delegates for a given employee based on role hierarchy.
     */
    public function getEligibleDelegates(int $employeeId): array
    {
        $employee = $this->getEmployee($employeeId);
        if (!$employee) return [];

        $role = $employee['employee_type'] ?? 'officer';
        $deptId = (int)($employee['department_id'] ?? 0);
        $sectionId = (int)($employee['section_id'] ?? 0);
        $subsectionId = (int)($employee['subsection_id'] ?? 0);

        $sql = "SELECT e.id, e.employee_id, e.first_name, e.last_name, e.designation,
                       d.name AS dept_name, s.name AS sec_name, ss.name AS sub_name
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN subsections ss ON e.subsection_id = ss.id
                WHERE e.id != ? AND e.employee_status = 'active'";

        $types = 'i';
        $params = [$employeeId];

        switch ($role) {
            case 'dept_head':
                $sql .= " AND e.department_id = ? AND e.id != ?";
                $types .= 'ii';
                $params[] = $deptId;
                $params[] = $employeeId;
                break;

            case 'section_head':
                $sql .= " AND e.section_id = ? AND e.id != ?";
                $types .= 'ii';
                $params[] = $sectionId;
                $params[] = $employeeId;
                break;

            case 'sub_section_head':
                $sql .= " AND e.subsection_id = ? AND e.id != ?";
                $types .= 'ii';
                $params[] = $subsectionId;
                $params[] = $employeeId;
                break;

            default: // officer / regular
                if ($subsectionId > 0) {
                    $sql .= " AND e.subsection_id = ?";
                    $params[] = $subsectionId;
                    $types .= 'i';
                } elseif ($sectionId > 0) {
                    $sql .= " AND e.section_id = ?";
                    $params[] = $sectionId;
                    $types .= 'i';
                } else {
                    $sql .= " AND e.department_id = ?";
                    $params[] = $deptId;
                    $types .= 'i';
                }
                $sql .= " AND e.id != ?";
                $params[] = $employeeId;
                $types .= 'i';
        }

        $sql .= " ORDER BY e.first_name, e.last_name";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $delegates = [];
        while ($row = $result->fetch_assoc()) {
            $delegates[] = [
                'id' => (int)$row['id'],
                'employee_id' => $row['employee_id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'designation' => $row['designation'] ?? '',
                'department' => $row['dept_name'] ?? '',
                'section' => $row['sec_name'] ?? '',
                'subsection' => $row['sub_name'] ?? '',
            ];
        }

        return $delegates;
    }

    /**
     * Assign a delegate to a leave application.
     */
    public function assignDelegate(int $leaveApplicationId, int $delegateEmployeeId, int $applicantUserId): bool
    {
        // Verify delegate is active
        $stmt = $this->conn->prepare("SELECT id FROM employees WHERE id = ? AND employee_status = 'active'");
        $stmt->bind_param('i', $delegateEmployeeId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE leave_applications SET delegated_to = ? WHERE id = ?");
        $stmt->bind_param('ii', $delegateEmployeeId, $leaveApplicationId);
        $success = $stmt->execute();

        if ($success) {
            // Get delegate info for audit
            $delegate = $this->getEmployee($delegateEmployeeId);
            $this->audit->logUpdate('leave_applications', $leaveApplicationId, 
                ['delegated_to' => null],
                ['delegated_to' => $delegateEmployeeId, 'delegate_name' => $delegate['first_name'] . ' ' . $delegate['last_name']],
                "Delegate assigned to leave #{$leaveApplicationId}"
            );
        }

        return $success;
    }

    /**
     * Validate that a delegate is eligible for a given employee.
     */
    public function validateDelegate(int $employeeId, int $delegateEmployeeId): array
    {
        if ($employeeId === $delegateEmployeeId) {
            return ['valid' => false, 'message' => 'Cannot delegate tasks to yourself.'];
        }

        $eligible = $this->getEligibleDelegates($employeeId);
        $ids = array_column($eligible, 'id');

        if (!in_array($delegateEmployeeId, $ids)) {
            return ['valid' => false, 'message' => 'Selected delegate is not eligible based on organizational hierarchy.'];
        }

        // Check delegate is active
        $emp = $this->getEmployee($delegateEmployeeId);
        if (!$emp || $emp['employee_status'] !== 'active') {
            return ['valid' => false, 'message' => 'Selected delegate is not active.'];
        }

        return ['valid' => true];
    }

    /**
     * Get delegate information for a leave application.
     */
    public function getDelegateInfo(int $leaveApplicationId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.id, e.employee_id, e.first_name, e.last_name, e.designation,
                   d.name AS dept_name, s.name AS sec_name
            FROM leave_applications la
            JOIN employees e ON la.delegated_to = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $leaveApplicationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        
        if (!$row) return null;

        return [
            'id' => (int)$row['id'],
            'employee_id' => $row['employee_id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'designation' => $row['designation'] ?? '',
            'department' => $row['dept_name'] ?? '',
            'section' => $row['sec_name'] ?? '',
        ];
    }

    private function getEmployee(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }
}
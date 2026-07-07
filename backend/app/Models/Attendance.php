<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Attendance Model
 *
 * Handles all attendance-related database operations:
 * clock-in, clock-out, status checks, history.
 *
 * Place: backend/app/Models/Attendance.php
 */
class Attendance
{
    /**
     * Get database connection helper.
     */
    private function db(): \mysqli
    {
        return \App\Helpers\Database::getInstance()->getConnection();
    }

    /**
     * Auto clock-out missed sessions at midnight.
     */
    public function autoClockOutMissedSessions(): void
    {
        $this->db()->query("
            UPDATE attendance
            SET clock_out  = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
                status     = 'auto_clocked_out',
                updated_at = NOW()
            WHERE clock_out IS NULL AND DATE(clock_in) < CURDATE()
        ");
    }

    /**
     * Get current active session for an employee.
     */
    public function getCurrentSession(int $employeeDbId): ?array
    {
        $stmt = $this->db()->prepare("
            SELECT a.*, o.name AS office_name
            FROM attendance a
            LEFT JOIN offices o ON a.office_id = o.id
            WHERE a.employee_id = ? AND a.clock_out IS NULL
            ORDER BY a.clock_in DESC LIMIT 1
        ");
        $stmt->bind_param("i", $employeeDbId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get today's attendance record for an employee.
     */
    public function getTodayRecord(int $employeeDbId, string $todayDate): ?array
    {
        $stmt = $this->db()->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND DATE(clock_in) = ?
            ORDER BY clock_in DESC LIMIT 1
        ");
        $stmt->bind_param("is", $employeeDbId, $todayDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Check if device fingerprint was used today.
     */
    public function isDeviceUsedToday(string $deviceFp, string $todayDate): bool
    {
        $stmt = $this->db()->prepare("
            SELECT COUNT(*) as used
            FROM attendance
            WHERE device_fingerprint = ? AND DATE(clock_in) = ?
        ");
        $stmt->bind_param("ss", $deviceFp, $todayDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ($result['used'] ?? 0) > 0;
    }

    /**
     * Clock in an employee.
     */
    public function clockIn(
        int $employeeDbId,
        int $officeId,
        float $latitude,
        float $longitude,
        float $accuracy,
        bool $isLate,
        string $deviceFp,
        string $status = 'clocked_in'
    ): bool {
        $stmt = $this->db()->prepare("
            INSERT INTO attendance SET
                employee_id = ?,
                office_id = ?,
                clock_in = NOW(),
                lat = ?,
                lng = ?,
                accuracy = ?,
                status = ?,
                created_at = NOW(),
                updated_at = NOW(),
                clock_in_office_id = ?,
                is_late = ?,
                device_fingerprint = ?
        ");
        $stmt->bind_param("iidddsiis",
            $employeeDbId,
            $officeId,
            $latitude,
            $longitude,
            $accuracy,
            $status,
            $officeId,
            $isLate,
            $deviceFp
        );
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Clock out an employee.
     */
    public function clockOut(int $sessionId, int $employeeDbId, int $officeId): bool
    {
        $stmt = $this->db()->prepare("
            UPDATE attendance
            SET clock_out = NOW(), clock_out_office_id = ?,
                status = 'clocked_out', updated_at = NOW()
            WHERE id = ? AND employee_id = ?
        ");
        $stmt->bind_param("iii", $officeId, $sessionId, $employeeDbId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Get attendance summary for dashboard.
     */
    public function getAttendanceSummary(int $employeeDbId): array
    {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $weekStart = date('Y-m-d', strtotime('monday this week'));

        // Today's record
        $todayRecord = $this->getTodayRecord($employeeDbId, $today);

        // This week stats
        $weekStmt = $this->db()->prepare("
            SELECT COUNT(*) as total_days,
                   SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                   SUM(CASE WHEN status = 'auto_clocked_out' THEN 1 ELSE 0 END) as missed_days
            FROM attendance
            WHERE employee_id = ? AND DATE(clock_in) >= ? AND DATE(clock_in) <= ?
        ");
        $weekStmt->bind_param("iss", $employeeDbId, $weekStart, $today);
        $weekStmt->execute();
        $weekStats = $weekStmt->get_result()->fetch_assoc();
        $weekStmt->close();

        // This month stats
        $monthStmt = $this->db()->prepare("
            SELECT COUNT(*) as total_days,
                   SUM(ROUND(TIMESTAMPDIFF(MINUTE, clock_in, COALESCE(clock_out, NOW()))) / 60) as total_hours
            FROM attendance
            WHERE employee_id = ? AND DATE(clock_in) >= ? AND DATE(clock_in) <= ?
        ");
        $monthStmt->bind_param("iss", $employeeDbId, $monthStart, $today);
        $monthStmt->execute();
        $monthStats = $monthStmt->get_result()->fetch_assoc();
        $monthStmt->close();

        return [
            'is_clocked_in'    => !empty($todayRecord) && empty($todayRecord['clock_out']),
            'clocked_in_today' => !empty($todayRecord),
            'today_record'     => $todayRecord,
            'week_days'        => (int)($weekStats['total_days'] ?? 0),
            'late_days'        => (int)($weekStats['late_days'] ?? 0),
            'missed_days'      => (int)($weekStats['missed_days'] ?? 0),
            'month_days'       => (int)($monthStats['total_days'] ?? 0),
            'month_hours'      => round((float)($monthStats['total_hours'] ?? 0), 1),
        ];
    }

    /**
     * Get attendance history for an employee.
     */
    public function getHistory(int $employeeDbId, int $limit = 30, int $offset = 0): array
    {
        $stmt = $this->db()->prepare("
            SELECT a.*, o.name AS office_name
            FROM attendance a
            LEFT JOIN offices o ON a.office_id = o.id
            WHERE a.employee_id = ?
            ORDER BY a.clock_in DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $employeeDbId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }
}
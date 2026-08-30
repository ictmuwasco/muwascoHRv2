<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * LeaveRosterController
 *
 * Handles annual leave roster planning API endpoints.
 * Stores planned annual leave per employee per financial year.
 */
class LeaveRosterController extends BaseController
{
    private const FY_MONTHS = [
        'July', 'August', 'September', 'October', 'November', 'December',
        'January', 'February', 'March', 'April', 'May', 'June',
    ];

    public function financialYearsAction(): void
    {
        try {
            $db = Database::getInstance()->getConnection();
            $result = $db->query("SELECT id, year_name FROM financial_years ORDER BY start_date DESC");
            $years = [];
            while ($row = $result->fetch_assoc()) {
                $years[] = [
                    'id' => (int) $row['id'],
                    'year_name' => $row['year_name'],
                    'name' => $row['year_name'],
                ];
            }
            $this->json(['success' => true, 'data' => $years]);
        } catch (\Throwable $e) {
            $this->logError('financialYearsAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load financial years', 'data' => []], 500);
        }
    }

    public function departmentsAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => []], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            $fyId = (int) ($_GET['financial_year_id'] ?? 0);

            $sql = "
                SELECT d.id AS department_id,
                       d.name AS department_name,
                       COUNT(DISTINCT e.id) AS total_employees,
                       COUNT(DISTINCT CASE WHEN lr.id IS NOT NULL THEN e.id END) AS scheduled_count,
                       COUNT(DISTINCT CASE WHEN lr.id IS NULL THEN e.id END) AS unscheduled_count
                FROM departments d
                LEFT JOIN employees e ON e.department_id = d.id AND e.employee_status = 'active'
                LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND (lr.financial_year_id = ? OR ? = 0)
                GROUP BY d.id, d.name
                ORDER BY d.name
            ";
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ii', $fyId, $fyId);
            $stmt->execute();
            $result = $stmt->get_result();

            $departments = [];
            while ($row = $result->fetch_assoc()) {
                $total = (int) $row['total_employees'];
                $scheduled = (int) $row['scheduled_count'];
                $departments[] = [
                    'department_id' => (int) $row['department_id'],
                    'department_name' => $row['department_name'],
                    'total_employees' => $total,
                    'scheduled_count' => $scheduled,
                    'unscheduled_count' => (int) $row['unscheduled_count'],
                    'coverage_percent' => $total > 0 ? (int) round(($scheduled / $total) * 100) : 0,
                ];
            }

            $this->json(['success' => true, 'data' => $departments]);
        } catch (\Throwable $e) {
            $this->logError('departmentsAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load departments', 'data' => []], 500);
        }
    }

    public function statsAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => null], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            $fyId = (int) ($_GET['financial_year_id'] ?? $this->defaultFyId());
            $deptId = (int) ($_GET['department_id'] ?? 0);
            $sectionId = (int) ($_GET['section_id'] ?? 0);

            $where = "e.employee_status = 'active'";
            $params = [];
            $types = '';
            // FY filter goes in the JOIN so unscheduled employees are NOT dropped
            $lrJoin = "lr.employee_id = e.id";
            if ($fyId) { $lrJoin .= ' AND lr.financial_year_id = ?'; $types .= 'i'; $params[] = $fyId; }
            if ($deptId) { $where .= ' AND e.department_id = ?'; $types .= 'i'; $params[] = $deptId; }
            if ($sectionId) { $where .= ' AND e.section_id = ?'; $types .= 'i'; $params[] = $sectionId; }

            $query = "
                SELECT COUNT(DISTINCT e.id) AS total_active,
                       COUNT(DISTINCT CASE WHEN lr.id IS NOT NULL THEN e.id END) AS total_scheduled
                FROM employees e
                LEFT JOIN leave_roster lr ON {$lrJoin}
                WHERE {$where}
            ";
            $stmt = $db->prepare($query);
            if ($types) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();

            $totalActive = (int) ($row['total_active'] ?? 0);
            $totalScheduled = (int) ($row['total_scheduled'] ?? 0);
            $this->json([
                'success' => true,
                'data' => [
                    'total_active' => $totalActive,
                    'total_scheduled' => $totalScheduled,
                    'not_scheduled' => max(0, $totalActive - $totalScheduled),
                    'coverage_percent' => $totalActive > 0 ? (int) round(($totalScheduled / $totalActive) * 100) : 0,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logError('statsAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load stats', 'data' => null], 500);
        }
    }

    public function distributionAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => null], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            [$where, $types, $params] = $this->buildFilters('lr');

            $query = "
                SELECT lr.scheduled_month AS month, COUNT(DISTINCT lr.employee_id) AS cnt
                FROM leave_roster lr
                JOIN employees e ON e.id = lr.employee_id AND e.employee_status = 'active'
                WHERE {$where}
                GROUP BY lr.scheduled_month
            ";
            $stmt = $db->prepare($query);
            if ($types) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $result = $stmt->get_result();

            $counts = [];
            while ($row = $result->fetch_assoc()) {
                $counts[$row['month']] = (int) $row['cnt'];
            }
            $distribution = [];
            foreach (self::FY_MONTHS as $month) {
                $distribution[] = ['month' => $month, 'count' => $counts[$month] ?? 0];
            }

            $scheduled = array_filter($distribution, fn($d) => $d['count'] > 0);
            $highest = null;
            $lowest = null;
            if (!empty($scheduled)) {
                usort($scheduled, fn($a, $b) => $b['count'] <=> $a['count']);
                $highest = $scheduled[0];
                $lowest = $scheduled[count($scheduled) - 1];
            }
            $this->json(['success' => true, 'data' => ['distribution' => $distribution, 'highest' => $highest, 'lowest' => $lowest]]);
        } catch (\Throwable $e) {
            $this->logError('distributionAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load distribution', 'data' => null], 500);
        }
    }

    public function upcomingAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => null], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            $currentMonth = date('F');
            $nextMonthNum = ((int) date('n')) % 12 + 1;
            $nextMonth = date('F', mktime(0, 0, 0, $nextMonthNum, 1));
            [$where, $types, $params] = $this->buildFilters('lr');
            $params[] = $currentMonth;            $params[] = $nextMonth;
            $types .= 'ss';

            $query = "
                SELECT lr.scheduled_month, e.id AS eid, e.first_name, e.last_name, e.employee_id, d.name AS department_name
                FROM leave_roster lr
                JOIN employees e ON e.id = lr.employee_id AND e.employee_status = 'active'
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE {$where} AND lr.scheduled_month IN (?, ?)
                ORDER BY lr.scheduled_month, e.first_name
            ";
            $stmt = $db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();

            $currentEmployees = [];
            $nextEmployees = [];
            while ($row = $result->fetch_assoc()) {
                $emp = [
                    'employee_id' => (int) $row['eid'],
                    'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                    'emp_code' => $row['employee_id'],
                    'department_name' => $row['department_name'] ?? '',
                ];
                if ($row['scheduled_month'] === $currentMonth) { $currentEmployees[] = $emp; }
                elseif ($row['scheduled_month'] === $nextMonth) { $nextEmployees[] = $emp; }
            }

            $this->json(['success' => true, 'data' => [
                'current_month' => $currentMonth,
                'next_month' => $nextMonth,
                'current_month_employees' => $currentEmployees,
                'next_month_employees' => $nextEmployees,
                'next_month_count' => count($nextEmployees),
            ]]);
        } catch (\Throwable $e) {
            $this->logError('upcomingAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load upcoming', 'data' => null], 500);
        }
    }

    public function matrixAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => null], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            $fyId = (int) ($_GET['financial_year_id'] ?? $this->defaultFyId());
            $deptId = (int) ($_GET['department_id'] ?? 0);
            $sectionId = (int) ($_GET['section_id'] ?? 0);

            $where = "e.employee_status = 'active'";
            $params = [$fyId];
            $types = 'i';
            if ($deptId) { $where .= ' AND e.department_id = ?'; $types .= 'i'; $params[] = $deptId; }
            if ($sectionId) { $where .= ' AND e.section_id = ?'; $types .= 'i'; $params[] = $sectionId; }

            $query = "
                SELECT e.id AS employee_id,
                       CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS employee_name,
                       e.employee_id AS emp_code,
                       d.name AS department_name,
                       lr.scheduled_month,
                       lr.id AS roster_id
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
                WHERE {$where}
                ORDER BY e.first_name, e.last_name
            ";
            $stmt = $db->prepare($query);
            if ($types) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $result = $stmt->get_result();

            $employees = [];
            while ($row = $result->fetch_assoc()) {
                $employees[] = [
                    'employee_id' => (int) $row['employee_id'],
                    'employee_name' => $row['employee_name'],
                    'emp_code' => $row['emp_code'],
                    'department_name' => $row['department_name'] ?? '',
                    'scheduled_month' => $row['scheduled_month'],
                    'roster_id' => $row['roster_id'] ? (int) $row['roster_id'] : null,
                ];
            }

            $this->json(['success' => true, 'data' => ['employees' => $employees]]);
        } catch (\Throwable $e) {
            $this->logError('matrixAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load matrix', 'data' => null], 500);
        }
    }

    public function employeesAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => []], 401);
                return;
            }

            $db = Database::getInstance()->getConnection();
            $search = trim($_GET['search'] ?? '');
            $fyId = (int) ($_GET['financial_year_id'] ?? 0);

            $query = "
                SELECT e.id,
                       CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS employee_name,
                       e.employee_id AS emp_code,
                       d.name AS department_name,
                       s.name AS section_name,
                       (SELECT lr.scheduled_month FROM leave_roster lr WHERE lr.employee_id = e.id AND lr.financial_year_id = ? LIMIT 1) AS scheduled_month
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN sections s ON s.id = e.section_id
                WHERE e.employee_status = 'active'
            ";
            $params = [$fyId];
            $types = 'i';
            if ($search !== '') {
                $query .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like; $params[] = $like; $params[] = $like;
                $types .= 'sss';
            }
            $query .= ' ORDER BY e.first_name, e.last_name LIMIT 20';

            $stmt = $db->prepare($query);
            if ($types) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $result = $stmt->get_result();

            $employees = [];
            while ($row = $result->fetch_assoc()) {
                $employees[] = [
                    'id' => (int) $row['id'],
                    'employee_name' => $row['employee_name'] ?? '',
                    'emp_code' => $row['emp_code'] ?? '',
                    'department_name' => $row['department_name'] ?? '',
                    'section_name' => $row['section_name'] ?? '',
                    'scheduled_month' => $row['scheduled_month'],
                ];
            }

            $this->json(['success' => true, 'data' => $employees]);
        } catch (\Throwable $e) {
            $this->logError('employeesAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to search employees', 'data' => []], 500);
        }
    }

    public function indexAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated', 'data' => []], 401);
                return;
            }
            $this->buildIndexResponse();
        } catch (\Throwable $e) {
            $this->logError('indexAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to load roster', 'data' => []], 500);
        }
    }

    private function buildIndexResponse(): void
    {
        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? $this->defaultFyId());
        $deptId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);
        $month = trim($_GET['month'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));

        // FY filter goes in the JOIN so unscheduled employees are still listed
        $where = "1=1";
        $params = [];
        $types = '';
        $lrJoin = "lr.employee_id = e.id";
        if ($fyId) { $lrJoin .= ' AND lr.financial_year_id = ?'; $types .= 'i'; $params[] = $fyId; }
        if ($deptId) { $where .= ' AND e.department_id = ?'; $types .= 'i'; $params[] = $deptId; }
        if ($sectionId) { $where .= ' AND e.section_id = ?'; $types .= 'i'; $params[] = $sectionId; }
        if ($month) { $where .= ' AND lr.scheduled_month = ?'; $types .= 's'; $params[] = $month; }
        if ($status === 'scheduled') { $where .= ' AND lr.id IS NOT NULL'; }
        elseif ($status === 'not_scheduled') { $where .= ' AND lr.id IS NULL'; }
        if ($search) { $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss'; }

        $base = "
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN leave_roster lr ON {$lrJoin}
            WHERE e.employee_status = 'active' AND {$where}
        ";

        $stmt = $db->prepare("SELECT COUNT(DISTINCT e.id) AS total {$base}");
        if ($types) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];

        $offset = ($page - 1) * $perPage;
        $dataQuery = "
            SELECT lr.id AS roster_id,
                   e.id AS employee_id,
                   CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS employee_name,
                   e.employee_id AS emp_code,
                   d.name AS department_name,
                   lr.scheduled_month,
                   lr.notes,
                   lr.updated_at,
                   CASE WHEN lr.id IS NOT NULL THEN 'scheduled' ELSE 'not_scheduled' END AS status
            {$base}
            ORDER BY e.first_name, e.last_name
            LIMIT ? OFFSET ?
        ";
        $stmt = $db->prepare($dataQuery);
        $params[] = $perPage;        $params[] = $offset;
        $types .= 'ii';
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $entries[] = [
                'roster_id' => $row['roster_id'] ? (int) $row['roster_id'] : null,
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'emp_code' => $row['emp_code'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'scheduled_month' => $row['scheduled_month'],
                'notes' => $row['notes'],
                'status' => $row['status'],
                'updated_at' => $row['updated_at'],
            ];
        }

        $this->json(['success' => true, 'data' => $entries, 'total' => $total, 'per_page' => $perPage, 'current_page' => $page, 'last_page' => (int) ceil($total / $perPage)]);
    }

    public function storeAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }
            $db = Database::getInstance()->getConnection();
            $body = $this->getJsonBody();

            $employeeId = (int) ($body['employee_id'] ?? 0);
            $fyId = (int) ($body['financial_year_id'] ?? 0);
            $month = trim($body['scheduled_month'] ?? '');
            $notes = trim($body['notes'] ?? '');

            if (!$employeeId || !$fyId || !$month) {
                $this->json(['success' => false, 'message' => 'Missing required fields: employee_id, financial_year_id, scheduled_month'], 400);
                return;
            }

            $stmt = $db->prepare("SELECT start_date FROM financial_years WHERE id = ?");
            $stmt->bind_param('i', $fyId);
            $stmt->execute();
            $fy = $stmt->get_result()->fetch_assoc();
            if (!$fy) {
                $this->json(['success' => false, 'message' => 'Invalid financial year'], 400);
                return;
            }
            $year = (int) date('Y', strtotime($fy['start_date']));
            if (in_array($month, ['January', 'February', 'March', 'April', 'May', 'June'])) { $year++; }

            // Check existing
            $stmt = $db->prepare("SELECT id FROM leave_roster WHERE employee_id = ? AND financial_year_id = ?");
            $stmt->bind_param('ii', $employeeId, $fyId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();

            if ($existing) {
                $stmt = $db->prepare("UPDATE leave_roster SET scheduled_month = ?, scheduled_year = ?, notes = ? WHERE id = ?");
                $stmt->bind_param('sisi', $month, $year, $notes, $existing['id']);
                $stmt->execute();
                $rosterId = (int) $existing['id'];
            } else {
                $stmt = $db->prepare("INSERT INTO leave_roster (employee_id, financial_year_id, scheduled_month, scheduled_year, notes) VALUES (?, ?, ?, ?, ?)");
                // Five placeholders -> five type chars (i i s i s). The previous
                // 'iisi' miscount made every insert throw ArgumentCountError,
                // which surfaced to users as a bare 500 "Failed to schedule leave".
                $stmt->bind_param('iisis', $employeeId, $fyId, $month, $year, $notes);
                $stmt->execute();
                $rosterId = (int) $db->insert_id;
            }

            $this->json(['success' => true, 'message' => 'Leave scheduled successfully', 'data' => ['id' => $rosterId]], 201);
        } catch (\Throwable $e) {
            $this->logError('storeAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to schedule leave'], 500);
        }
    }

    public function updateAction(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }
            $db = Database::getInstance()->getConnection();
            $body = $this->getJsonBody();
            $month = trim($body['scheduled_month'] ?? '');
            $notes = trim($body['notes'] ?? '');
            if (!$month) {
                $this->json(['success' => false, 'message' => 'Missing scheduled_month'], 400);
                return;
            }
            // Existence pre-check: mysqli reports *changed* rows by default, so
            // saving identical values must not be misread as "entry not found".
            $chk = $db->prepare("SELECT fy.start_date FROM leave_roster lr JOIN financial_years fy ON fy.id = lr.financial_year_id WHERE lr.id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $existingRow = $chk->get_result()->fetch_assoc();
            if (!$existingRow) {
                $this->json(['success' => false, 'message' => 'Roster entry not found'], 404);
                return;
            }

            // Keep scheduled_year consistent with storeAction's month->year mapping.
            $year = (int) date('Y', strtotime($existingRow['start_date']));
            if (in_array($month, ['January', 'February', 'March', 'April', 'May', 'June'])) { $year++; }

            $stmt = $db->prepare("UPDATE leave_roster SET scheduled_month = ?, scheduled_year = ?, notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param('sisi', $month, $year, $notes, $id);
            $stmt->execute();
            $this->json(['success' => true, 'message' => 'Schedule updated successfully']);
        } catch (\Throwable $e) {
            $this->logError('updateAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to update roster'], 500);
        }
    }

    public function destroyAction(int $id): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM leave_roster WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $this->json(['success' => true, 'message' => 'Schedule entry removed']);
        } catch (\Throwable $e) {
            $this->logError('destroyAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to remove schedule'], 500);
        }
    }

    public function exportAction(): void
    {
        try {
            if (!$this->getUserId()) {
                $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
                return;
            }
            $db = Database::getInstance()->getConnection();
            $fyId = (int) ($_GET['financial_year_id'] ?? 0);
            $deptId = (int) ($_GET['department_id'] ?? 0);
            $sectionId = (int) ($_GET['section_id'] ?? 0);
            $month = trim($_GET['month'] ?? '');
            $status = trim($_GET['status'] ?? '');
            $search = trim($_GET['search'] ?? '');

            $where = "e.employee_status = 'active'";
            $params = [];
            $types = '';
            // FY filter goes in the JOIN so unscheduled employees are still included
            $lrJoin = "lr.employee_id = e.id";
            if ($fyId) { $lrJoin .= ' AND lr.financial_year_id = ?'; $types .= 'i'; $params[] = $fyId; }
            if ($deptId) { $where .= ' AND e.department_id = ?'; $types .= 'i'; $params[] = $deptId; }
            if ($sectionId) { $where .= ' AND e.section_id = ?'; $types .= 'i'; $params[] = $sectionId; }
            if ($month) { $where .= ' AND lr.scheduled_month = ?'; $types .= 's'; $params[] = $month; }
            if ($status === 'scheduled') { $where .= ' AND lr.id IS NOT NULL'; }
            elseif ($status === 'not_scheduled') { $where .= ' AND lr.id IS NULL'; }
            if ($search) { $where .= ' AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; $params[] = $like; $types .= 'sss'; }

            $query = "
                SELECT CONCAT(COALESCE(e.first_name, ''), ' ', COALESCE(e.last_name, '')) AS employee_name,
                       e.employee_id AS emp_code,
                       d.name AS department_name,
                       lr.scheduled_month,
                       CASE WHEN lr.id IS NOT NULL THEN 'scheduled' ELSE 'not_scheduled' END AS status
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN leave_roster lr ON {$lrJoin}
                WHERE {$where}
                ORDER BY e.first_name, e.last_name
            ";
            $stmt = $db->prepare($query);
            if ($types) { $stmt->bind_param($types, ...$params); }
            $stmt->execute();
            $result = $stmt->get_result();

            $csv = "Employee,Employee Code,Department,Planned Month,Status\n";
            while ($row = $result->fetch_assoc()) {
                $csv .= '"' . str_replace('"', '""', $row['employee_name'] ?? '') . '","'
                     . str_replace('"', '""', $row['emp_code'] ?? '') . '","'
                     . str_replace('"', '""', $row['department_name'] ?? '') . '","'
                     . str_replace('"', '""', $row['scheduled_month'] ?? '') . '","'
                     . str_replace('"', '""', $row['status'] ?? '') . "\"\n";
            }

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="leave-roster-export.csv"');
            echo $csv;
            exit;
        } catch (\Throwable $e) {
            $this->logError('exportAction', $e);
            $this->json(['success' => false, 'message' => 'Failed to export roster'], 500);
        }
    }

    private function buildFilters(string $alias): array
    {
        $where = "1=1";
        $types = '';
        $params = [];
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($fyId) { $where .= " AND {$alias}.financial_year_id = ?"; $types .= 'i'; $params[] = $fyId; }
        return [$where, $types, $params];
    }

    private function defaultFyId(): int
    {
        static $fyId = null;
        if ($fyId !== null) { return $fyId; }
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $fyId = $row ? (int) $row['id'] : 0;
        if ($fyId === 0) {
            $stmt = $db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $fyId = $row ? (int) $row['id'] : 0;
        }
        return $fyId;
    }

    private function logError(string $action, \Throwable $e): void
    {
        error_log('[LeaveRosterController] ' . $action . ': ' . $e->getMessage());
    }
}
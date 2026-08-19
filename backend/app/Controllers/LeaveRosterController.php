<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Services\AuditService;

/**
 * LeaveRosterController
 *
 * Handles annual leave planning roster API endpoints.
 * Roster = planning which employees take annual leave in which month.
 * This is separate from actual leave applications.
 */
class LeaveRosterController
{
    private const FY_MONTHS = [
        'July', 'August', 'September', 'October', 'November', 'December',
        'January', 'February', 'March', 'April', 'May', 'June'
    ];

    private const MANAGER_ROLES = ['hr_manager', 'managing_director', 'super_admin'];

    /**
     * GET /api/leave/roster
     * List roster entries with filters.
     */
    public function indexAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        $departmentId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);
        $month = trim($_GET['month'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $search = trim($_GET['search'] ?? '');

        // If no FY specified, use current
        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $where = ['lr.financial_year_id = ?'];
        $params = [$fyId];
        $types = 'i';

        if ($departmentId) {
            $where[] = 'e.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
        if ($sectionId) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
            $types .= 'i';
        }
        if ($month) {
            $where[] = 'lr.scheduled_month = ?';
            $params[] = $month;
            $types .= 's';
        }
        if ($status === 'scheduled') {
            $where[] = 'lr.id IS NOT NULL';
        } elseif ($status === 'not_scheduled') {
            $where[] = 'lr.id IS NULL';
        }
        if ($search) {
            $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT e.id AS employee_id,
                   e.first_name,
                   e.last_name,
                   e.employee_id AS emp_code,
                   d.name AS department_name,
                   s.name AS section_name,
                   lr.id AS roster_id,
                   lr.scheduled_month,
                   lr.scheduled_year,
                   lr.notes,
                   lr.created_by,
                   lr.created_at,
                   lr.updated_at,
                   u.first_name AS created_by_name,
                   u.last_name AS created_by_last_name
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN sections s ON s.id = e.section_id
            LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
            LEFT JOIN users u ON u.id = lr.created_by
            WHERE e.employee_status = 'active'
              AND {$whereSql}
            ORDER BY d.name, e.first_name, e.last_name
        ";

        // Prepend fyId for the LEFT JOIN condition
        array_unshift($params, $fyId);
        $types = 'i' . $types;

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'emp_code' => $row['emp_code'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'section_name' => $row['section_name'] ?? '',
                'roster_id' => $row['roster_id'] ? (int) $row['roster_id'] : null,
                'scheduled_month' => $row['scheduled_month'] ?? null,
                'scheduled_year' => $row['scheduled_year'] ? (int) $row['scheduled_year'] : null,
                'notes' => $row['notes'] ?? '',
                'status' => $row['roster_id'] ? 'scheduled' : 'not_scheduled',
                'created_by_name' => trim(($row['created_by_name'] ?? '') . ' ' . ($row['created_by_last_name'] ?? '')),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $rows,
            'financial_year_id' => $fyId,
        ]);
    }

    /**
     * POST /api/leave/roster
     * Create a new roster entry.
     */
    public function storeAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        if (!$this->canManageRoster($userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Only HR managers and above can manage the roster.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $employeeId = (int) ($input['employee_id'] ?? 0);
        $fyId = (int) ($input['financial_year_id'] ?? 0);
        $month = trim($input['scheduled_month'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if (!$employeeId || !$fyId || !$month) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields: employee_id, financial_year_id, scheduled_month.']);
            return;
        }

        // Validate month is in FY order
        if (!in_array($month, self::FY_MONTHS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid month. Must be one of: ' . implode(', ', self::FY_MONTHS)]);
            return;
        }

        $db = Database::getInstance()->getConnection();

        // Check employee exists and is active
        $stmt = $db->prepare("SELECT id FROM employees WHERE id = ? AND employee_status = 'active'");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Employee not found or inactive.']);
            return;
        }

        // Check financial year exists
        $stmt = $db->prepare("SELECT id, year_name, start_date FROM financial_years WHERE id = ?");
        $stmt->bind_param('i', $fyId);
        $stmt->execute();
        $fy = $stmt->get_result()->fetch_assoc();
        if (!$fy) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Financial year not found.']);
            return;
        }

        // Duplicate prevention: one employee per financial year
        $stmt = $db->prepare("SELECT id FROM leave_roster WHERE employee_id = ? AND financial_year_id = ?");
        $stmt->bind_param('ii', $employeeId, $fyId);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This employee already has a roster entry for the selected financial year.']);
            return;
        }

        // Get scheduled_year from financial year
        $scheduledYear = (int) date('Y', strtotime($fy['start_date']));

        $stmt = $db->prepare("
            INSERT INTO leave_roster (employee_id, scheduled_month, scheduled_year, notes, created_by, financial_year_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('isissi', $employeeId, $month, $scheduledYear, $notes, $userId, $fyId);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to create roster entry: ' . $db->error]);
            return;
        }

        $rosterId = (int) $db->insert_id;

        AuditService::getInstance()->log(
            AuditService::MODULE_LEAVE,
            AuditService::ACTION_CREATE,
            'Created leave roster entry',
            ['target_type' => 'LeaveRoster', 'target_id' => $rosterId, 'metadata' => ['employee_id' => $employeeId, 'month' => $month, 'financial_year_id' => $fyId]]
        );

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Leave roster entry created successfully.',
            'data' => ['id' => $rosterId],
        ]);
    }

    /**
     * PUT /api/leave/roster/{id}
     * Update a roster entry.
     */
    public function updateAction(int $id): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        if (!$this->canManageRoster($userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Only HR managers and above can manage the roster.']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $month = trim($input['scheduled_month'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if (!$month) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required field: scheduled_month.']);
            return;
        }

        if (!in_array($month, self::FY_MONTHS, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid month. Must be one of: ' . implode(', ', self::FY_MONTHS)]);
            return;
        }

        $db = Database::getInstance()->getConnection();

        // Check entry exists
        $stmt = $db->prepare("SELECT * FROM leave_roster WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Roster entry not found.']);
            return;
        }

        $stmt = $db->prepare("UPDATE leave_roster SET scheduled_month = ?, notes = ? WHERE id = ?");
        $stmt->bind_param('ssi', $month, $notes, $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to update roster entry: ' . $db->error]);
            return;
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_LEAVE,
            AuditService::ACTION_UPDATE,
            'Updated leave roster entry',
            ['target_type' => 'LeaveRoster', 'target_id' => $id, 'metadata' => ['month' => $month]]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Leave roster entry updated successfully.',
        ]);
    }

    /**
     * DELETE /api/leave/roster/{id}
     * Delete a roster entry.
     */
    public function destroyAction(int $id): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        if (!$this->canManageRoster($userId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Only HR managers and above can manage the roster.']);
            return;
        }

        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM leave_roster WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Roster entry not found.']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM leave_roster WHERE id = ?");
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete roster entry: ' . $db->error]);
            return;
        }

        AuditService::getInstance()->log(
            AuditService::MODULE_LEAVE,
            AuditService::ACTION_DELETE,
            'Deleted leave roster entry',
            ['target_type' => 'LeaveRoster', 'target_id' => $id, 'metadata' => ['employee_id' => $existing['employee_id'], 'month' => $existing['scheduled_month']]]
        );

        echo json_encode([
            'success' => true,
            'message' => 'Leave roster entry removed successfully.',
        ]);
    }

    /**
     * GET /api/leave/roster/stats
     * KPI metrics: active employees, scheduled, not scheduled, coverage %.
     */
    public function statsAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        $departmentId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $where = ['e.employee_status = ?'];
        $params = ['active'];
        $types = 's';

        if ($departmentId) {
            $where[] = 'e.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
        if ($sectionId) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT
                COUNT(DISTINCT e.id) AS total_active,
                COUNT(DISTINCT lr.id) AS total_scheduled
            FROM employees e
            LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
            WHERE {$whereSql}
        ";

        array_unshift($params, $fyId);
        $types = 'i' . $types;

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $totalActive = (int) ($row['total_active'] ?? 0);
        $totalScheduled = (int) ($row['total_scheduled'] ?? 0);
        $notScheduled = max(0, $totalActive - $totalScheduled);
        $coverage = $totalActive > 0 ? round(($totalScheduled / $totalActive) * 100, 1) : 0;

        echo json_encode([
            'success' => true,
            'data' => [
                'total_active' => $totalActive,
                'total_scheduled' => $totalScheduled,
                'not_scheduled' => $notScheduled,
                'coverage_percent' => $coverage,
                'financial_year_id' => $fyId,
            ],
        ]);
    }

    /**
     * GET /api/leave/roster/distribution
     * Monthly distribution of scheduled leave (July → June).
     */
    public function distributionAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        $departmentId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $where = ['lr.financial_year_id = ?'];
        $params = [$fyId];
        $types = 'i';

        if ($departmentId) {
            $where[] = 'e.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
        if ($sectionId) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT lr.scheduled_month, COUNT(*) AS count
            FROM leave_roster lr
            JOIN employees e ON e.id = lr.employee_id
            WHERE {$whereSql}
            GROUP BY lr.scheduled_month
        ";

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[$row['scheduled_month']] = (int) $row['count'];
        }

        // Build July → June ordered distribution
        $distribution = [];
        foreach (self::FY_MONTHS as $month) {
            $distribution[] = [
                'month' => $month,
                'count' => $counts[$month] ?? 0,
            ];
        }

        // Find highest and lowest
        $highest = null;
        $lowest = null;
        foreach ($distribution as $item) {
            if ($item['count'] > 0) {
                if ($highest === null || $item['count'] > $highest['count']) {
                    $highest = $item;
                }
                if ($lowest === null || $item['count'] < $lowest['count']) {
                    $lowest = $item;
                }
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'distribution' => $distribution,
                'highest' => $highest,
                'lowest' => $lowest,
                'financial_year_id' => $fyId,
            ],
        ]);
    }

    /**
     * GET /api/leave/roster/upcoming
     * Current month + next month planned leave.
     */
    public function upcomingAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        $departmentId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        // Determine current month in FY order
        $currentMonthName = date('F');
        $currentMonthIndex = array_search($currentMonthName, self::FY_MONTHS, true);
        if ($currentMonthIndex === false) {
            $currentMonthIndex = 0;
        }
        $nextMonthIndex = ($currentMonthIndex + 1) % 12;
        $nextMonthName = self::FY_MONTHS[$nextMonthIndex];

        $where = ['lr.financial_year_id = ?', 'lr.scheduled_month IN (?, ?)'];
        $params = [$fyId, $currentMonthName, $nextMonthName];
        $types = 'iss';

        if ($departmentId) {
            $where[] = 'e.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
        if ($sectionId) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT lr.id, lr.scheduled_month, lr.notes,
                   e.id AS employee_id, e.first_name, e.last_name, e.employee_id AS emp_code,
                   d.name AS department_name
            FROM leave_roster lr
            JOIN employees e ON e.id = lr.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE {$whereSql}
            ORDER BY lr.scheduled_month, e.first_name, e.last_name
        ";

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $currentMonth = [];
        $nextMonth = [];
        while ($row = $result->fetch_assoc()) {
            $item = [
                'roster_id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'emp_code' => $row['emp_code'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'notes' => $row['notes'] ?? '',
            ];
            if ($row['scheduled_month'] === $currentMonthName) {
                $currentMonth[] = $item;
            } else {
                $nextMonth[] = $item;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'current_month' => $currentMonthName,
                'next_month' => $nextMonthName,
                'current_month_employees' => $currentMonth,
                'next_month_employees' => $nextMonth,
                'next_month_count' => count($nextMonth),
                'financial_year_id' => $fyId,
            ],
        ]);
    }

    /**
     * GET /api/leave/roster/departments
     * Department planning status table.
     */
    public function departmentsAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $query = "
            SELECT d.id AS department_id,
                   d.name AS department_name,
                   COUNT(DISTINCT e.id) AS total_employees,
                   COUNT(DISTINCT lr.id) AS scheduled_count
            FROM departments d
            LEFT JOIN employees e ON e.department_id = d.id AND e.employee_status = 'active'
            LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
            GROUP BY d.id, d.name
            HAVING total_employees > 0
            ORDER BY d.name
        ";

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param('i', $fyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $total = (int) $row['total_employees'];
            $scheduled = (int) $row['scheduled_count'];
            $unscheduled = max(0, $total - $scheduled);
            $coverage = $total > 0 ? round(($scheduled / $total) * 100, 1) : 0;
            $departments[] = [
                'department_id' => (int) $row['department_id'],
                'department_name' => $row['department_name'],
                'total_employees' => $total,
                'scheduled_count' => $scheduled,
                'unscheduled_count' => $unscheduled,
                'coverage_percent' => $coverage,
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $departments,
            'financial_year_id' => $fyId,
        ]);
    }

    /**
     * GET /api/leave/roster/matrix
     * Employee × Month planning matrix data.
     */
    public function matrixAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);
        $departmentId = (int) ($_GET['department_id'] ?? 0);
        $sectionId = (int) ($_GET['section_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $where = ['e.employee_status = ?'];
        $params = ['active'];
        $types = 's';

        if ($departmentId) {
            $where[] = 'e.department_id = ?';
            $params[] = $departmentId;
            $types .= 'i';
        }
        if ($sectionId) {
            $where[] = 'e.section_id = ?';
            $params[] = $sectionId;
            $types .= 'i';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT e.id AS employee_id,
                   e.first_name, e.last_name, e.employee_id AS emp_code,
                   d.name AS department_name,
                   lr.scheduled_month,
                   lr.notes,
                   lr.id AS roster_id
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
            WHERE {$whereSql}
            ORDER BY d.name, e.first_name, e.last_name
        ";

        array_unshift($params, $fyId);
        $types = 'i' . $types;

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'emp_code' => $row['emp_code'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'scheduled_month' => $row['scheduled_month'] ?? null,
                'notes' => $row['notes'] ?? '',
                'roster_id' => $row['roster_id'] ? (int) $row['roster_id'] : null,
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'months' => self::FY_MONTHS,
                'employees' => $employees,
            ],
            'financial_year_id' => $fyId,
        ]);
    }

    /**
     * GET /api/leave/roster/export
     * CSV export of current filtered view.
     */
    public function exportAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        // Reuse indexAction logic but output CSV
        ob_start();
        $this->indexAction();
        $json = ob_get_clean();
        $data = json_decode($json, true);

        if (!($data['success'] ?? false)) {
            http_response_code(400);
            echo $json;
            return;
        }

        $rows = $data['data'] ?? [];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leave_roster_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Employee', 'Employee Code', 'Department', 'Section', 'Planned Month', 'Status', 'Notes', 'Scheduled By', 'Updated At']);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['employee_name'] ?? '',
                $row['emp_code'] ?? '',
                $row['department_name'] ?? '',
                $row['section_name'] ?? '',
                $row['scheduled_month'] ?? '',
                $row['status'] ?? '',
                $row['notes'] ?? '',
                $row['created_by_name'] ?? '',
                $row['updated_at'] ?? '',
            ]);
        }
        fclose($output);
    }

    /**
     * GET /api/leave/roster/employees
     * Searchable employee list for the schedule form.
     */
    public function employeesAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $search = trim($_GET['search'] ?? '');
        $fyId = (int) ($_GET['financial_year_id'] ?? 0);

        if (!$fyId) {
            $fyId = $this->getCurrentFinancialYearId();
        }

        $where = ["e.employee_status = 'active'"];
        $params = [];
        $types = '';

        if ($search) {
            $where[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ?)';
            $like = "%{$search}%";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $whereSql = implode(' AND ', $where);

        $query = "
            SELECT e.id, e.first_name, e.last_name, e.employee_id AS emp_code,
                   d.name AS department_name, s.name AS section_name,
                   lr.scheduled_month, lr.id AS roster_id
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN sections s ON s.id = e.section_id
            LEFT JOIN leave_roster lr ON lr.employee_id = e.id AND lr.financial_year_id = ?
            WHERE {$whereSql}
            ORDER BY e.first_name, e.last_name
            LIMIT 50
        ";

        array_unshift($params, $fyId);
        $types = 'i' . $types;

        $stmt = $db->prepare($query);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $db->error]);
            return;
        }
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = [
                'id' => (int) $row['id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'emp_code' => $row['emp_code'] ?? '',
                'department_name' => $row['department_name'] ?? '',
                'section_name' => $row['section_name'] ?? '',
                'scheduled_month' => $row['scheduled_month'] ?? null,
                'roster_id' => $row['roster_id'] ? (int) $row['roster_id'] : null,
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $employees,
        ]);
    }

    /**
     * GET /api/leave/roster/financial-years
     * List financial years for the selector.
     */
    public function financialYearsAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = Database::getInstance()->getConnection();
        $result = $db->query("SELECT id, year_name, start_date, end_date, is_active FROM financial_years ORDER BY start_date DESC");

        $years = [];
        while ($row = $result->fetch_assoc()) {
            $years[] = [
                'id' => (int) $row['id'],
                'year_name' => $row['year_name'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'is_active' => (int) $row['is_active'],
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $years,
        ]);
    }

    /**
     * Check if user can manage the roster.
     */
    private function canManageRoster(int $userId): bool
    {
        $user = Auth::getInstance()->user();
        $role = strtolower($user['role'] ?? '');
        return in_array($role, self::MANAGER_ROLES, true);
    }

    /**
     * Get current financial year ID.
     */
    private function getCurrentFinancialYearId(): int
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            return (int) $result['id'];
        }
        $stmt = $db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }
}
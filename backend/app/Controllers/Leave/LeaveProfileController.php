<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Core\Controller;
use App\Services\AuditService;

class LeaveProfileController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $userRole = $_SESSION['user_role'] ?? 'guest';
        $canViewAll = in_array($userRole, ['hr_manager', 'super_admin']);

        // Get own employee record
        $stmt = $conn->prepare("SELECT e.* FROM employees e JOIN users u ON u.employee_id = e.employee_id WHERE u.id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ownEmployee = $stmt->get_result()->fetch_assoc();

        // Determine which employee to view
        $selectedEmployeeId = $ownEmployee['id'] ?? 0;
        if ($canViewAll && isset($_GET['employee_id']) && (int)$_GET['employee_id'] > 0) {
            $selectedEmployeeId = (int)$_GET['employee_id'];
        }

        // Get employee info
        $empStmt = $conn->prepare("
            SELECT e.*, d.name AS department_name, s.name AS section_name, ss.name AS subsection_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            WHERE e.id = ? LIMIT 1
        ");
        $empStmt->bind_param('i', $selectedEmployeeId);
        $empStmt->execute();
        $employee = $empStmt->get_result()->fetch_assoc();

        // Get all employees (for search, HR only)
        $allEmployees = [];
        if ($canViewAll) {
            $allEmployees = $conn->query("
                SELECT e.id, e.employee_id, e.first_name, e.last_name, d.name AS dept
                FROM employees e LEFT JOIN departments d ON e.department_id = d.id
                ORDER BY e.first_name, e.last_name
            ")->fetch_all(MYSQLI_ASSOC) ?: [];
        }

        // Get financial years
        $allFYs = $conn->query("SELECT * FROM financial_years ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
        $today = date('Y-m-d');

        // Determine current FY
        $currentFY = null;
        foreach ($allFYs as $fy) {
            if ($today >= $fy['start_date'] && $today <= $fy['end_date'] && $fy['is_active']) {
                $currentFY = $fy;
                break;
            }
        }
        if (!$currentFY && !empty($allFYs)) {
            $currentFY = $allFYs[0];
        }

        $selectedFYId = isset($_GET['view_fy']) ? (int)$_GET['view_fy'] : ($currentFY['id'] ?? 0);
        $selectedFY = null;
        foreach ($allFYs as $fy) {
            if ((int)$fy['id'] === $selectedFYId) { $selectedFY = $fy; break; }
        }
        if (!$selectedFY) $selectedFY = $currentFY;

        $isFutureFY = $selectedFY && $selectedFY['start_date'] > $today;
        $isCurrentFY = $selectedFY && (int)$selectedFY['id'] === (int)($currentFY['id'] ?? 0);

        // Fetch leave balances
        $balances = [];
        if ($selectedEmployeeId && $selectedFY) {
            $balStmt = $conn->prepare("
                SELECT elb.*, lt.name AS leave_type_name, lt.counts_weekends,
                       lt.deducted_from_annual, lt.is_active
                FROM employee_leave_balances elb
                JOIN leave_types lt ON elb.leave_type_id = lt.id
                WHERE elb.employee_id = ? AND elb.financial_year_id = ?
                ORDER BY lt.name
            ");
            $balStmt->bind_param('ii', $selectedEmployeeId, $selectedFY['id']);
            $balStmt->execute();
            $balances = $balStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        }

        // Fetch leave applications for this FY
        $leaveApplications = [];
        if ($selectedEmployeeId && $selectedFY) {
            $appStmt = $conn->prepare("
                SELECT la.*, lt.name AS leave_type_name, lt.deducted_from_annual,
                       u.first_name AS applied_by_fname, u.last_name AS applied_by_lname
                FROM leave_applications la
                JOIN leave_types lt ON la.leave_type_id = lt.id
                LEFT JOIN users u ON la.applied_by_user_id = u.id
                WHERE la.employee_id = ? AND la.financial_year_id = ?
                ORDER BY la.applied_at DESC
            ");
            $appStmt->bind_param('ii', $selectedEmployeeId, $selectedFY['id']);
            $appStmt->execute();
            $leaveApplications = $appStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            
            // Fallback: try date range if no financial_year_id matches
            if (empty($leaveApplications)) {
                $appStmt = $conn->prepare("
                    SELECT la.*, lt.name AS leave_type_name, lt.deducted_from_annual,
                           u.first_name AS applied_by_fname, u.last_name AS applied_by_lname
                    FROM leave_applications la
                    JOIN leave_types lt ON la.leave_type_id = lt.id
                    LEFT JOIN users u ON la.applied_by_user_id = u.id
                    WHERE la.employee_id = ? AND la.start_date >= ? AND la.start_date <= ?
                    ORDER BY la.applied_at DESC
                ");
                $appStmt->bind_param('iss', $selectedEmployeeId, $selectedFY['start_date'], $selectedFY['end_date']);
                $appStmt->execute();
                $leaveApplications = $appStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            }
        }

        // Leave types for filters
        $allLeaveTypes = $conn->query("SELECT id, name FROM leave_types WHERE is_active = 1 ORDER BY name")->fetch_all(MYSQLI_ASSOC) ?: [];

        $this->view('leave/profile', [
            'employee' => $employee,
            'ownEmployee' => $ownEmployee,
            'allEmployees' => $allEmployees,
            'canViewAll' => $canViewAll,
            'balances' => $balances,
            'leaveApplications' => $leaveApplications,
            'allFYs' => $allFYs,
            'selectedFY' => $selectedFY,
            'currentFY' => $currentFY,
            'isFutureFY' => $isFutureFY,
            'isCurrentFY' => $isCurrentFY,
            'selectedEmployeeId' => $selectedEmployeeId,
            'allLeaveTypes' => $allLeaveTypes,
            'csrf_token' => $this->generateCsrfToken(),
            'filterStatus' => trim($_GET['filter_status'] ?? ''),
            'filterLeaveType' => (int)($_GET['filter_leave_type'] ?? 0),
            'filterDateFrom' => trim($_GET['filter_date_from'] ?? ''),
            'filterDateTo' => trim($_GET['filter_date_to'] ?? ''),
        ]);
    }
}
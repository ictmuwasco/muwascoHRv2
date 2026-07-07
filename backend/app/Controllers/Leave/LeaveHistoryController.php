<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Core\Controller;
use App\Services\AuditService;

class LeaveHistoryController extends Controller
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

        $role = $_SESSION['user_role'] ?? 'guest';
        if (!in_array($role, ['hr_manager', 'super_admin', 'manager', 'managing_director'])) {
            $this->redirect('leave/apply');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        // Employees currently on leave
        $currentLeaves = $conn->query("
            SELECT la.*, e.employee_id, e.first_name, e.last_name,
                   lt.name as leave_type_name,
                   elb.remaining_days
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN employee_leave_balances elb ON e.id = elb.employee_id 
                AND lt.id = elb.leave_type_id
                AND elb.financial_year_id = (
                    SELECT id FROM financial_years WHERE is_active = 1 LIMIT 1
                )
            WHERE la.status = 'approved'
            AND la.start_date <= CURDATE()
            AND la.end_date >= CURDATE()
            ORDER BY la.end_date ASC
        ")->fetch_all(MYSQLI_ASSOC) ?: [];

        // All leave applications (recent 50)
        $allLeaves = $conn->query("
            SELECT la.*, e.employee_id, e.first_name, e.last_name,
                   lt.name as leave_type_name,
                   elb.remaining_days
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN employee_leave_balances elb ON e.id = elb.employee_id 
                AND lt.id = elb.leave_type_id
                AND elb.financial_year_id = (
                    SELECT id FROM financial_years WHERE is_active = 1 LIMIT 1
                )
            ORDER BY la.applied_at DESC
            LIMIT 50
        ")->fetch_all(MYSQLI_ASSOC) ?: [];

        $this->view('leave/history', [
            'currentLeaves' => $currentLeaves,
            'allLeaves' => $allLeaves,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}
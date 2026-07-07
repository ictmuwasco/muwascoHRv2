<?php

declare(strict_types=1);

namespace App\Controllers\Attendance;

use App\Core\Controller;
use App\Services\AuditService;

class AttendanceProfileController extends Controller
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

        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 3) . '/auth.php';
        }

        $role = $_SESSION['user_role'] ?? 'guest';
        $hasDashboardAccess = in_array($role, ['hr_manager', 'super_admin', 'dept_head']);

        if (!$hasDashboardAccess) {
            $this->redirect('attendance');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        // Get all employees for search
        $allEmployees = $conn->query("
            SELECT e.id, e.employee_id, e.first_name, e.last_name, 
                   e.profile_image_url, e.designation, o.name as office_name, d.name as department_name
            FROM employees e
            LEFT JOIN offices o ON e.office_id = o.id
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE e.employee_status = 'active'
            ORDER BY e.first_name, e.last_name
        ")->fetch_all(MYSQLI_ASSOC) ?: [];

        $this->view('attendance/profile', [
            'allEmployees' => $allEmployees,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}
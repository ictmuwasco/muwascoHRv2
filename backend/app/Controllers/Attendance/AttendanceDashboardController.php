<?php

declare(strict_types=1);

namespace App\Controllers\Attendance;

use App\Core\Controller;
use App\Services\AuditService;

class AttendanceDashboardController extends Controller
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

        if (!hasPermission('hr_manager')) {
            $this->redirect('attendance');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $filterDate   = $_GET['date'] ?? date('Y-m-d');
        $filterOffice = $_GET['office'] ?? 'all';
        $filterStatus = $_GET['status'] ?? 'all';
        $page         = max(1, (int)($_GET['page'] ?? 1));
        $rpp          = 20;
        $currentSection = $_GET['section'] ?? 'attendance';

        // Stats
        $totalActive = $conn->query("SELECT COUNT(*) as c FROM employees WHERE employee_status='active'")->fetch_assoc()['c'];

        // Send basic stats + get view path
        $this->view('attendance/dashboard', [
            'totalActive' => $totalActive,
            'filterDate' => $filterDate,
            'filterOffice' => $filterOffice,
            'filterStatus' => $filterStatus,
            'currentSection' => $currentSection,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}
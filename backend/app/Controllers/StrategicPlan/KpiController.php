<?php

declare(strict_types=1);

namespace App\Controllers\StrategicPlan;

use App\Core\Controller;
use App\Services\AuditService;

class KpiController extends Controller
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
        if (!in_array($role, ['hr_manager', 'super_admin', 'manager', 'dept_head'])) {
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $this->view('strategic_plan/kpi', [
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}
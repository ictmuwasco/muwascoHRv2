<?php

declare(strict_types=1);

namespace App\Controllers\Appraisal;

use App\Core\Controller;
use App\Services\AuditService;

class PerformanceAppraisalController extends Controller
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

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        // Get current user's employee record
        $userId = $this->getUserId();
        $stmt = $conn->prepare("
            SELECT e.*, d.id as department_id, d.name as department_name, 
                   s.id as section_id, s.name as section_name, 
                   ss.id as subsection_id, ss.name as subsection_name
            FROM employees e 
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN users u ON u.employee_id = e.employee_id 
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentEmployee = $stmt->get_result()->fetch_assoc();

        if (!$currentEmployee) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('appraisal/performance_appraisal', [
            'currentEmployee' => $currentEmployee,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function escalatedAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 3) . '/auth.php';
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $userId = $this->getUserId();
        $stmt = $conn->prepare("
            SELECT e.*, d.id as department_id, d.name as department_name, 
                   s.id as section_id, s.name as section_name, 
                   ss.id as subsection_id, ss.name as subsection_name
            FROM employees e 
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN users u ON u.employee_id = e.employee_id 
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentEmployee = $stmt->get_result()->fetch_assoc();

        if (!$currentEmployee) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('appraisal/performance_appraisal_escalated', [
            'currentEmployee' => $currentEmployee,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function pendingAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 3) . '/auth.php';
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $userId = $this->getUserId();
        $stmt = $conn->prepare("
            SELECT e.*, d.id as department_id, d.name as department_name, 
                   s.id as section_id, s.name as section_name, 
                   ss.id as subsection_id, ss.name as subsection_name
            FROM employees e 
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN users u ON u.employee_id = e.employee_id 
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentEmployee = $stmt->get_result()->fetch_assoc();

        if (!$currentEmployee) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('appraisal/performance_appraisal_pending', [
            'currentEmployee' => $currentEmployee,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Core\Controller;
use App\Services\AuditService;

class EmployeeReportsController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    public function indexAction(): void
    {
        $this->ensureAccess();
        $conn = $this->getDbConnection();
        $filters = $this->buildFilters();
        $employees = $this->getData($conn, $filters);
        $stats = $this->calcStats($employees);
        $opts = $this->getFilterOptions($conn);
        $this->audit->logPageView();
        $this->view('reports/employees', [
            'employees' => $employees, 'stats' => $stats,
            'filters' => $filters, 'filter_options' => $opts,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function exportAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('reports/employees'); return;
        }
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];
        $this->audit->logExport('employees', $filters);
        $_SESSION['flash_message'] = 'Export initiated successfully.';
        $this->redirect('reports/employees');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated() || !hasPermission('hr_manager')) {
            $this->audit->logSuspiciousActivity('Unauthorized access to employee reports', [
                'user_id' => $_SESSION['user_id'] ?? 0,
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
            $_SESSION['flash_error'] = 'Access denied.';
            $this->redirect('dashboard');
        }
    }

    private function buildFilters(): array
    {
        return [
            'department' => $this->sanitize($_GET['department'] ?? ''),
            'section' => $this->sanitize($_GET['section'] ?? ''),
            'subsection' => $this->sanitize($_GET['subsection'] ?? ''),
            'type' => $this->sanitize($_GET['type'] ?? ''),
            'status' => $this->sanitize($_GET['status'] ?? ''),
            'employment_type' => $this->sanitize($_GET['employment_type'] ?? ''),
            'job_group' => $this->sanitize($_GET['job_group'] ?? ''),
            'date_from' => $this->validateDate($_GET['date_from'] ?? ''),
            'date_to' => $this->validateDate($_GET['date_to'] ?? ''),
            'search' => $this->sanitize($_GET['search'] ?? '', 100),
        ];
    }

    private function getData(\mysqli $conn, array $f): array
    {
        $sql = "SELECT e.*, COALESCE(e.first_name,'') fn, COALESCE(e.last_name,'') ln,
                d.name dept, s.name sec, ss.name sub
                FROM employees e
                LEFT JOIN departments d ON e.department_id=d.id
                LEFT JOIN sections s ON e.section_id=s.id
                LEFT JOIN subsections ss ON e.subsection_id=ss.id WHERE 1=1";
        $t = ''; $p = [];
        foreach (['department','section','subsection'] as $k) {
            if (!empty($f[$k])) { $sql.=" AND e.{$k}_id=?"; $p[]=(int)$f[$k]; $t.='i'; }
        }
        foreach (['type'=>'employee_type','status'=>'employee_status','employment_type'=>'employment_type','job_group'=>'scale_id'] as $k=>$c) {
            if (!empty($f[$k])) { $sql.=" AND e.{$c}=?"; $p[]=$f[$k]; $t.='s'; }
        }
        if (!empty($f['date_from'])) { $sql.=" AND e.hire_date>=?"; $p[]=$f['date_from']; $t.='s'; }
        if (!empty($f['date_to'])) { $sql.=" AND e.hire_date<=?"; $p[]=$f['date_to']; $t.='s'; }
        if (!empty($f['search'])) { $s="%{$f['search']}%"; $sql.=" AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)"; $p=array_merge($p,[$s,$s,$s,$s]); $t.='ssss'; }
        $sql.=" ORDER BY e.employee_id ASC";
        $stmt = $conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t, ...$p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcStats(array $e): array
    {
        $t = count($e);
        $a = count(array_filter($e, fn($x) => $x['employee_status']==='active'));
        return ['total'=>$t,'active'=>$a,'inactive'=>$t-$a,
            'permanent'=>count(array_filter($e,fn($x)=>$x['employment_type']==='permanent')),
            'contract'=>count(array_filter($e,fn($x)=>$x['employment_type']==='contract')),
            'temporary'=>count(array_filter($e,fn($x)=>$x['employment_type']==='temporary'))];
    }

    private function getFilterOptions(\mysqli $conn): array
    {
        return [
            'departments' => $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'sections' => $conn->query("SELECT s.*,d.name department_name FROM sections s LEFT JOIN departments d ON s.department_id=d.id ORDER BY d.name,s.name")->fetch_all(MYSQLI_ASSOC),
            'subsections' => $conn->query("SELECT ss.*,s.name section_name,d.name department_name FROM subsections ss LEFT JOIN sections s ON ss.section_id=s.id LEFT JOIN departments d ON ss.department_id=d.id ORDER BY d.name,s.name,ss.name")->fetch_all(MYSQLI_ASSOC),
        ];
    }

    private function sanitize(string $v, int $max=255): string
    {
        $v = trim(strip_tags($v));
        return strlen($v) > $max ? substr($v,0,$max) : $v;
    }

    private function validateDate(string $d): string
    {
        $ts = strtotime($d);
        return $ts === false ? '' : date('Y-m-d', $ts);
    }
}
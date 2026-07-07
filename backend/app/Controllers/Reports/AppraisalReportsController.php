<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Core\Controller;
use App\Services\AuditService;

class AppraisalReportsController extends Controller
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
        $data = $this->getData($conn, $filters);
        $stats = $this->calcStats($conn, $filters);
        $opts = $this->getFilterOptions($conn);
        $this->audit->logPageView();
        $this->view('reports/appraisal', [
            'appraisals' => $data, 'stats' => $stats,
            'filters' => $filters, 'filter_options' => $opts,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function exportAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('reports/appraisal'); return;
        }
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];
        $this->audit->logExport('performance_appraisals', $filters);
        $_SESSION['flash_message'] = 'Export initiated successfully.';
        $this->redirect('reports/appraisal');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated() || !hasPermission('hr_manager')) {
            $this->audit->logSuspiciousActivity('Unauthorized access to appraisal reports', [
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
            'cycle' => $this->sanitize($_GET['cycle'] ?? ''),
            'department' => $this->sanitize($_GET['department'] ?? ''),
            'employee' => $this->sanitize($_GET['employee'] ?? '', 100),
            'reviewer' => $this->sanitize($_GET['reviewer'] ?? ''),
            'status' => $this->sanitize($_GET['status'] ?? ''),
            'date_from' => $this->validateDate($_GET['date_from'] ?? ''),
            'date_to' => $this->validateDate($_GET['date_to'] ?? ''),
        ];
    }

    private function getData(\mysqli $conn, array $f): array
    {
        $sql = "SELECT pa.*,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,CONCAT(r.first_name,' ',r.last_name) reviewer_name
                FROM performance_appraisals pa INNER JOIN employees e ON pa.employee_id=e.id
                LEFT JOIN departments d ON e.department_id=d.id
                LEFT JOIN users u ON e.employee_id=u.employee_id
                LEFT JOIN employees r ON u.supervisor_id=r.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['cycle'])) { $sql.=" AND pa.appraisal_cycle=?"; $p[]=$f['cycle']; $t.='s'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        if (!empty($f['status'])) { $sql.=" AND pa.status=?"; $p[]=$f['status']; $t.='s'; }
        if (!empty($f['date_from'])) { $sql.=" AND pa.created_at>=?"; $p[]=$f['date_from']; $t.='s'; }
        if (!empty($f['date_to'])) { $sql.=" AND pa.created_at<=?"; $p[]=$f['date_to']; $t.='s'; }
        $sql.=" ORDER BY pa.created_at DESC";
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcStats(\mysqli $conn, array $f): array
    {
        $sql = "SELECT COUNT(*) total,SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) completed,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) in_progress,AVG(overall_score) avg_score
                FROM performance_appraisals pa INNER JOIN employees e ON pa.employee_id=e.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['cycle'])) { $sql.=" AND pa.appraisal_cycle=?"; $p[]=$f['cycle']; $t.='s'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        $s=$stmt->get_result()->fetch_assoc();
        return ['total'=>(int)($s['total']??0),'completed'=>(int)($s['completed']??0),'pending'=>(int)($s['pending']??0),'in_progress'=>(int)($s['in_progress']??0),'avg_score'=>round((float)($s['avg_score']??0),2)];
    }

    private function getFilterOptions(\mysqli $conn): array
    {
        return [
            'departments' => $conn->query("SELECT id,name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'cycles' => $conn->query("SELECT DISTINCT appraisal_cycle FROM performance_appraisals ORDER BY appraisal_cycle DESC")->fetch_all(MYSQLI_ASSOC),
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
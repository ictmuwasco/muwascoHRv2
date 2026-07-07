<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Core\Controller;
use App\Services\AuditService;

class LeaveReportsController extends Controller
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
        $leaves = $this->getData($conn, $filters);
        $stats = $this->calcStats($conn, $filters);
        $opts = $this->getFilterOptions($conn);
        $this->audit->logPageView();
        $this->view('reports/leave', [
            'leaves' => $leaves, 'stats' => $stats,
            'filters' => $filters, 'filter_options' => $opts,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function exportAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('reports/leave'); return;
        }
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];
        $this->audit->logExport('leave_requests', $filters);
        $_SESSION['flash_message'] = 'Export initiated successfully.';
        $this->redirect('reports/leave');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated() || !hasPermission('hr_manager')) {
            $this->audit->logSuspiciousActivity('Unauthorized access to leave reports', [
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
            'date_from' => $this->validateDate($_GET['date_from'] ?? ''),
            'date_to' => $this->validateDate($_GET['date_to'] ?? ''),
            'employee' => $this->sanitize($_GET['employee'] ?? '', 100),
            'department' => $this->sanitize($_GET['department'] ?? ''),
            'leave_type' => $this->sanitize($_GET['leave_type'] ?? ''),
            'status' => $this->sanitize($_GET['status'] ?? ''),
            'leave_year' => $this->validateYear($_GET['leave_year'] ?? ''),
        ];
    }

    private function getData(\mysqli $conn, array $f): array
    {
        $sql = "SELECT lr.*,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,lt.name leave_type_name
                FROM leave_requests lr INNER JOIN employees e ON lr.employee_id=e.id
                LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN leave_types lt ON lr.leave_type_id=lt.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['date_from'])) { $sql.=" AND lr.start_date>=?"; $p[]=$f['date_from']; $t.='s'; }
        if (!empty($f['date_to'])) { $sql.=" AND lr.end_date<=?"; $p[]=$f['date_to']; $t.='s'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['leave_type'])) { $sql.=" AND lr.leave_type_id=?"; $p[]=(int)$f['leave_type']; $t.='i'; }
        if (!empty($f['status'])) { $sql.=" AND lr.status=?"; $p[]=$f['status']; $t.='s'; }
        if (!empty($f['leave_year'])) { $sql.=" AND YEAR(lr.start_date)=?"; $p[]=(int)$f['leave_year']; $t.='i'; }
        $sql.=" ORDER BY lr.created_at DESC";
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcStats(\mysqli $conn, array $f): array
    {
        $sql = "SELECT COUNT(*) total_requests,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) approved,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) rejected,
                SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled,
                SUM(CASE WHEN status='approved' THEN days_requested ELSE 0 END) total_days_approved
                FROM leave_requests lr INNER JOIN employees e ON lr.employee_id=e.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['date_from'])) { $sql.=" AND lr.start_date>=?"; $p[]=$f['date_from']; $t.='s'; }
        if (!empty($f['date_to'])) { $sql.=" AND lr.end_date<=?"; $p[]=$f['date_to']; $t.='s'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        $s=$stmt->get_result()->fetch_assoc();
        return ['total_requests'=>(int)($s['total_requests']??0),'approved'=>(int)($s['approved']??0),'pending'=>(int)($s['pending']??0),'rejected'=>(int)($s['rejected']??0),'cancelled'=>(int)($s['cancelled']??0),'total_days_approved'=>(int)($s['total_days_approved']??0)];
    }

    private function getFilterOptions(\mysqli $conn): array
    {
        $y=range((int)date('Y')-2,(int)date('Y')+1);
        return [
            'departments' => $conn->query("SELECT id,name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'leave_types' => $conn->query("SELECT id,name FROM leave_types ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'years' => array_map(fn($y)=>['year'=>$y],$y),
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

    private function validateYear(string $y): string
    {
        if (empty($y)) return '';
        $y=(int)$y; $cy=(int)date('Y');
        return ($y<2000||$y>$cy+1) ? '' : (string)$y;
    }
}
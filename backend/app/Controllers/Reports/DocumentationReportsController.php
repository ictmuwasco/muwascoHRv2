<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Core\Controller;
use App\Services\AuditService;

class DocumentationReportsController extends Controller
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
        $this->view('reports/documentation', [
            'documents' => $data, 'stats' => $stats,
            'filters' => $filters, 'filter_options' => $opts,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function exportAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('reports/documentation'); return;
        }
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];
        $this->audit->logExport('employee_documents', $filters);
        $_SESSION['flash_message'] = 'Export initiated successfully.';
        $this->redirect('reports/documentation');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated() || !hasPermission('hr_manager')) {
            $this->audit->logSuspiciousActivity('Unauthorized access to documentation reports', [
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
            'document_type' => $this->sanitize($_GET['document_type'] ?? ''),
            'employee' => $this->sanitize($_GET['employee'] ?? '', 100),
            'department' => $this->sanitize($_GET['department'] ?? ''),
            'expiry_status' => $this->sanitize($_GET['expiry_status'] ?? ''),
            'date_from' => $this->validateDate($_GET['date_from'] ?? ''),
            'date_to' => $this->validateDate($_GET['date_to'] ?? ''),
        ];
    }

    private function getData(\mysqli $conn, array $f): array
    {
        $sql = "SELECT ed.*,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name FROM employee_documents ed
                INNER JOIN employees e ON ed.employee_id=e.id
                LEFT JOIN departments d ON e.department_id=d.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['document_type'])) { $sql.=" AND ed.document_type=?"; $p[]=$f['document_type']; $t.='s'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['expiry_status'])) {
            if ($f['expiry_status']==='expired') $sql.=" AND ed.expiry_date<CURDATE()";
            elseif ($f['expiry_status']==='expiring_soon') $sql.=" AND ed.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)";
            elseif ($f['expiry_status']==='valid') $sql.=" AND (ed.expiry_date IS NULL OR ed.expiry_date>=CURDATE())";
        }
        if (!empty($f['date_from'])) { $sql.=" AND ed.uploaded_at>=?"; $p[]=$f['date_from']; $t.='s'; }
        if (!empty($f['date_to'])) { $sql.=" AND ed.uploaded_at<=?"; $p[]=$f['date_to']; $t.='s'; }
        $sql.=" ORDER BY ed.uploaded_at DESC";
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcStats(\mysqli $conn, array $f): array
    {
        $sql = "SELECT COUNT(*) total,
                SUM(CASE WHEN ed.expiry_date<CURDATE() THEN 1 ELSE 0 END) expired,
                SUM(CASE WHEN ed.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 ELSE 0 END) expiring_soon,
                SUM(CASE WHEN ed.expiry_date IS NULL OR ed.expiry_date>=CURDATE() THEN 1 ELSE 0 END) valid
                FROM employee_documents ed INNER JOIN employees e ON ed.employee_id=e.id WHERE 1=1";
        $t=''; $p=[];
        if (!empty($f['document_type'])) { $sql.=" AND ed.document_type=?"; $p[]=$f['document_type']; $t.='s'; }
        if (!empty($f['department'])) { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $stmt=$conn->prepare($sql);
        if (!empty($p)) $stmt->bind_param($t,...$p);
        $stmt->execute();
        $s=$stmt->get_result()->fetch_assoc();
        return ['total'=>(int)($s['total']??0),'expired'=>(int)($s['expired']??0),'expiring_soon'=>(int)($s['expiring_soon']??0),'valid'=>(int)($s['valid']??0)];
    }

    private function getFilterOptions(\mysqli $conn): array
    {
        return [
            'departments' => $conn->query("SELECT id,name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'document_types' => $conn->query("SELECT DISTINCT document_type FROM employee_documents ORDER BY document_type")->fetch_all(MYSQLI_ASSOC),
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
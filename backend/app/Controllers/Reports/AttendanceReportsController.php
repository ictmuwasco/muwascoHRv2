<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Core\Controller;
use App\Services\AuditService;

class AttendanceReportsController extends Controller
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
        $type = $_GET['report_type'] ?? 'summary';
        $data = match($type) {
            'detailed' => $this->getDetailed($conn, $filters),
            'late' => $this->getLate($conn, $filters),
            'absent' => $this->getAbsent($conn, $filters),
            'location' => $this->getLocation($conn, $filters),
            default => $this->getSummary($conn, $filters),
        };
        $stats = $this->calcStats($conn, $filters);
        $opts = $this->getFilterOptions($conn);
        $this->audit->logPageView();
        $this->view('reports/attendance', [
            'data' => $data, 'stats' => $stats, 'filters' => $filters,
            'report_type' => $type, 'filter_options' => $opts,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    public function exportAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('reports/attendance'); return;
        }
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];
        $this->audit->logExport('attendance', $filters);
        $_SESSION['flash_message'] = 'Export initiated successfully.';
        $this->redirect('reports/attendance');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated() || !hasPermission('hr_manager')) {
            $this->audit->logSuspiciousActivity('Unauthorized access to attendance reports', [
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
            'date_from' => $this->validateDate($_GET['date_from'] ?? date('Y-m-01')),
            'date_to' => $this->validateDate($_GET['date_to'] ?? date('Y-m-t')),
            'office' => $this->sanitize($_GET['office'] ?? 'all'),
            'department' => $this->sanitize($_GET['department'] ?? 'all'),
            'employee' => $this->sanitize($_GET['employee'] ?? '', 100),
            'status' => $this->sanitize($_GET['status'] ?? 'all'),
        ];
    }

    private function getSummary(\mysqli $conn, array $f): array
    {
        $sql = "SELECT e.id,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,o.name office_name,COUNT(a.id) total_days,
                COALESCE(AVG(TIMESTAMPDIFF(HOUR,a.clock_in,a.clock_out)),0) avg_hours,
                MIN(TIME(a.clock_in)) earliest_clock_in,MAX(TIME(a.clock_in)) latest_clock_in,
                COUNT(CASE WHEN TIME(a.clock_in)>'09:00:00' THEN 1 END) late_count,
                COUNT(CASE WHEN TIMESTAMPDIFF(HOUR,a.clock_in,a.clock_out)<8 THEN 1 END) undertime_count
                FROM employees e LEFT JOIN attendance a ON e.id=a.employee_id AND DATE(a.clock_in) BETWEEN ? AND ?
                LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN offices o ON e.office_id=o.id
                WHERE e.employee_status='active'";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND e.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        $sql.=" GROUP BY e.id,e.employee_id,e.first_name,e.last_name,d.name,o.name ORDER BY total_days DESC";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getDetailed(\mysqli $conn, array $f): array
    {
        $sql = "SELECT a.*,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,o.name office_name,TIMESTAMPDIFF(HOUR,a.clock_in,a.clock_out) total_hours
                FROM attendance a INNER JOIN employees e ON a.employee_id=e.id
                LEFT JOIN departments d ON e.department_id=d.id INNER JOIN offices o ON a.office_id=o.id
                WHERE DATE(a.clock_in) BETWEEN ? AND ?";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND a.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        if ($f['status']!=='all') { $sql.=" AND a.status=?"; $p[]=$f['status']; $t.='s'; }
        $sql.=" ORDER BY a.clock_in DESC";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getLate(\mysqli $conn, array $f): array
    {
        $sql = "SELECT e.id,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,o.name office_name,DATE(a.clock_in) date,TIME(a.clock_in) clock_in_time,
                TIMESTAMPDIFF(MINUTE,'09:00:00',TIME(a.clock_in)) minutes_late
                FROM attendance a INNER JOIN employees e ON a.employee_id=e.id
                LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN offices o ON e.office_id=o.id
                WHERE DATE(a.clock_in) BETWEEN ? AND ? AND TIME(a.clock_in)>'09:00:00'";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND e.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $sql.=" ORDER BY minutes_late DESC";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getAbsent(\mysqli $conn, array $f): array
    {
        $sql = "SELECT e.id,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                d.name department_name,o.name office_name,e.email,e.phone,
                COUNT(DISTINCT dates.work_date) absent_days,
                GROUP_CONCAT(DISTINCT dates.work_date ORDER BY dates.work_date ASC) absent_dates
                FROM employees e CROSS JOIN (SELECT DISTINCT DATE(clock_in) work_date FROM attendance WHERE DATE(clock_in) BETWEEN ? AND ?) dates
                LEFT JOIN attendance a ON e.id=a.employee_id AND DATE(a.clock_in)=dates.work_date
                LEFT JOIN departments d ON e.department_id=d.id INNER JOIN offices o ON e.office_id=o.id
                WHERE e.employee_status='active' AND a.id IS NULL";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND e.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        if (!empty($f['employee'])) { $s="%{$f['employee']}%"; $sql.=" AND (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ?)"; $p=array_merge($p,[$s,$s,$s]); $t.='sss'; }
        $sql.=" GROUP BY e.id,e.employee_id,e.first_name,e.last_name,d.name,o.name,e.email,e.phone HAVING absent_days>0 ORDER BY absent_days DESC";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function getLocation(\mysqli $conn, array $f): array
    {
        $sql = "SELECT e.id,e.employee_id emp_number,CONCAT(e.first_name,' ',e.last_name) employee_name,
                o.name office_name,DATE(a.clock_in) date,TIME(a.clock_in) clock_in_time,
                COALESCE(a.accuracy,0) accuracy,
                ROUND(COALESCE((6371*ACOS(COS(RADIANS(COALESCE(a.lat,0)))*COS(RADIANS(COALESCE(o.latitude,0)))*COS(RADIANS(COALESCE(o.longitude,0))-RADIANS(COALESCE(a.lng,0)))+SIN(RADIANS(COALESCE(a.lat,0)))*SIN(RADIANS(COALESCE(o.latitude,0))))*1000),0),2) distance_from_office
                FROM attendance a INNER JOIN employees e ON a.employee_id=e.id INNER JOIN offices o ON a.office_id=o.id
                WHERE DATE(a.clock_in) BETWEEN ? AND ? AND (COALESCE(a.accuracy,0)>100 OR COALESCE((6371*ACOS(COS(RADIANS(COALESCE(a.lat,0)))*COS(RADIANS(COALESCE(o.latitude,0)))*COS(RADIANS(COALESCE(o.longitude,0))-RADIANS(COALESCE(a.lng,0)))+SIN(RADIANS(COALESCE(a.lat,0)))*SIN(RADIANS(COALESCE(o.latitude,0))))*1000),0)>200)";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND e.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $sql.=" ORDER BY distance_from_office DESC";
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function calcStats(\mysqli $conn, array $f): array
    {
        $sql = "SELECT COUNT(DISTINCT a.employee_id) total_present,COUNT(a.id) total_records,
                COALESCE(AVG(TIMESTAMPDIFF(HOUR,a.clock_in,a.clock_out)),0) avg_hours,
                COUNT(CASE WHEN TIME(a.clock_in)>'09:00:00' THEN 1 END) late_count
                FROM attendance a INNER JOIN employees e ON a.employee_id=e.id
                WHERE DATE(a.clock_in) BETWEEN ? AND ?";
        $t='ss'; $p=[$f['date_from'],$f['date_to']];
        if ($f['office']!=='all') { $sql.=" AND e.office_id=?"; $p[]=(int)$f['office']; $t.='i'; }
        if ($f['department']!=='all') { $sql.=" AND e.department_id=?"; $p[]=(int)$f['department']; $t.='i'; }
        $stmt=$conn->prepare($sql); $stmt->bind_param($t,...$p); $stmt->execute();
        $s=$stmt->get_result()->fetch_assoc();
        $q="SELECT COUNT(*) total FROM employees WHERE employee_status='active'";
        if ($f['office']!=='all') $q.=" AND office_id=".(int)$f['office'];
        if ($f['department']!=='all') $q.=" AND department_id=".(int)$f['department'];
        $ta=$conn->query($q)->fetch_assoc()['total']??0;
        return ['total_active'=>$ta,'total_present'=>(int)($s['total_present']??0),'total_records'=>(int)($s['total_records']??0),'avg_hours'=>(float)($s['avg_hours']??0),'late_count'=>(int)($s['late_count']??0),'absent_count'=>$ta-(int)($s['total_present']??0)];
    }

    private function getFilterOptions(\mysqli $conn): array
    {
        return [
            'offices' => $conn->query("SELECT id,name FROM offices ORDER BY name")->fetch_all(MYSQLI_ASSOC),
            'departments' => $conn->query("SELECT id,name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC),
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
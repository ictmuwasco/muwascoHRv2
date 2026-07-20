<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuditService;

/**
 * AuditController
 *
 * Handles audit trail viewing and filtering.
 *
 * Place: backend/app/Controllers/AuditController.php
 */
class AuditController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    /**
     * Display audit dashboard
     * GET /audit-dashboard
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Only super admins, HR managers, and auditors can view audit logs
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['super_admin', 'hr_manager', 'auditor'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access audit logs.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();

        $filters = [];

        if (!empty($_GET['user_id'])) {
            $filters['user_id'] = (int)$_GET['user_id'];
        }

        if (!empty($_GET['action_type'])) {
            $filters['action_type'] = $_GET['action_type'];
        }

        if (!empty($_GET['table_name'])) {
            $filters['table_name'] = $_GET['table_name'];
        }

        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'] . ' 00:00:00';
        }

        if (!empty($_GET['date_end'])) {
            $filters['date_to'] = $_GET['date_end'] . ' 23:59:59';
        }

        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $users = $this->getFilterOptions($conn, 'user_id', 'user_id');
        $actions = $this->getFilterOptions($conn, 'action_type', 'action_type');
        $tables = $this->getFilterOptions($conn, 'table_name', 'table_name', "WHERE table_name IS NOT NULL");

        $logs = $this->getAuditLogs($conn, $filters, $limit, $offset);
        $totalLogs = $this->getAuditLogsCount($conn, $filters);
        $totalPages = (int)ceil($totalLogs / $limit);
        $stats = $this->getAuditStatistics($conn, $filters);

        $this->view('audit/index', [
            'logs' => $logs,
            'users' => $users,
            'actions' => $actions,
            'tables' => $tables,
            'stats' => $stats,
            'total_logs' => $totalLogs,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'filters' => $filters,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Export audit logs
     * POST /audit-dashboard/export
     */
    public function exportAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['super_admin', 'hr_manager', 'auditor'])) {
            $_SESSION['flash_error'] = 'You do not have permission to export audit logs.';
            $this->redirect('dashboard');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('audit-dashboard');
            return;
        }

        $conn = $this->getDbConnection();
        $filters = json_decode($_POST['filters'] ?? '[]', true) ?: [];

        $exportType = $_POST['export_type'] ?? 'excel';

        if ($exportType === 'pdf') {
            $this->exportPDF($conn, $filters);
        } else {
            $this->exportExcel($conn, $filters);
        }
    }

    private function getFilterOptions(\mysqli $conn, string $field, string $alias, string $extraWhere = ''): array
    {
        $query = "SELECT DISTINCT {$field} as {$alias}, {$field} as id, username FROM audit_logs {$extraWhere} ORDER BY {$alias}";
        $result = $conn->query($query);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    private function getAuditLogs(\mysqli $conn, array $filters, int $limit, int $offset): array
    {
        $sql = "SELECT 
                    al.*,
                    COALESCE(
                        CASE 
                            WHEN u.first_name IS NOT NULL AND u.last_name IS NOT NULL AND u.surname IS NOT NULL 
                            THEN CONCAT(u.first_name, ' ', u.last_name, ' ', u.surname)
                            WHEN u.first_name IS NOT NULL AND u.last_name IS NOT NULL 
                            THEN CONCAT(u.first_name, ' ', u.last_name)
                            WHEN u.first_name IS NOT NULL 
                            THEN u.first_name
                            ELSE al.username
                        END, 
                        al.username
                    ) as display_name,
                    COALESCE(u.role, al.user_role, 'Unknown') as display_role
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE 1=1";

        $types = '';
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $types .= 'i';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action_type'])) {
            $sql .= " AND al.action_type = ?";
            $types .= 's';
            $params[] = $filters['action_type'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = ?";
            $types .= 's';
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.timestamp >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.timestamp <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $sql .= " AND (al.description LIKE ? OR al.username LIKE ? OR al.table_name LIKE ?)";
            $types .= 'sss';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY al.timestamp DESC LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    private function getAuditLogsCount(\mysqli $conn, array $filters): int
    {
        $sql = "SELECT COUNT(*) as total FROM audit_logs al WHERE 1=1";
        $types = '';
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $types .= 'i';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['action_type'])) {
            $sql .= " AND al.action_type = ?";
            $types .= 's';
            $params[] = $filters['action_type'];
        }

        if (!empty($filters['table_name'])) {
            $sql .= " AND al.table_name = ?";
            $types .= 's';
            $params[] = $filters['table_name'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.timestamp >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.timestamp <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $sql .= " AND (al.description LIKE ? OR al.username LIKE ? OR al.table_name LIKE ?)";
            $types .= 'sss';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if ($types) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return (int)($result['total'] ?? 0);
    }

    private function getAuditStatistics(\mysqli $conn, array $filters): array
    {
        $sql = "SELECT action_type, COUNT(*) as count FROM audit_logs al WHERE 1=1";
        $types = '';
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= " AND al.user_id = ?";
            $types .= 'i';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND al.timestamp >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND al.timestamp <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }

        $sql .= " GROUP BY action_type";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if ($types) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[$row['action_type']] = (int)$row['count'];
        }

        return $stats;
    }

    private function exportPDF(\mysqli $conn, array $filters): void
    {
        $_SESSION['flash_error'] = 'PDF export is not yet implemented.';
        $this->redirect('audit-dashboard');
    }

    private function exportExcel(\mysqli $conn, array $filters): void
    {
        $_SESSION['flash_error'] = 'Excel export is not yet implemented.';
        $this->redirect('audit-dashboard');
    }
}
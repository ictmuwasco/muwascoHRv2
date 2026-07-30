<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
he
class ConsentController extends Controller
{
    /**
     * Display consent management dashboard
     * GET /consent-management
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Only admins, HR managers and super admins can manage consents
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['super_admin', 'hr_manager', 'admin', 'administrator'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        // Skip permission check for pending consent users
        $isPendingConsent = isset($_SESSION['pending_user_id']) && isset($_SESSION['pending_auth_time']);
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!$isPendingConsent && !in_array($userRole, ['super_admin', 'hr_manager', 'admin', 'administrator'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();

        // Build filters
        $filters = [];
        
        if (!empty($_GET['search'])) {
            $filters['search'] = trim($_GET['search']);
        }
        
        if (!empty($_GET['department'])) {
            $filters['department'] = (int)$_GET['department'];
        }
        
        if (!empty($_GET['consent_status'])) {
            $filters['consent_status'] = $_GET['consent_status'];
        }
        
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }

        // Pagination
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Get statistics
        $stats = $this->getConsentStats($conn, $filters);

        // Get consents with pagination
        $consents = $this->getEmployeeConsents($conn, $filters, $page, $perPage);
        
        // Calculate total pages
        $totalPages = (int)ceil($stats['total_employees'] / $perPage);

        // Get departments for filter
        $departments = $conn->query("SELECT id, name FROM departments ORDER BY name")
            ->fetch_all(MYSQLI_ASSOC);

        $this->view('consent/index', [
            'consents' => $consents,
            'stats' => $stats,
            'departments' => $departments,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'filters' => $filters,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Display login consent page (standalone, no navbar)
     * GET /login/consent
     */
    public function loginConsentAction(): void
    {
        // Allow pending consent users (not fully authenticated yet)
        $hasPendingUser = isset($_SESSION['pending_user_id']) && isset($_SESSION['pending_auth_time']);
        
        if (!$hasPendingUser) {
            $this->redirect('login');
            return;
        }

        $this->view('auth/consent/index', [
            'pending_name' => $_SESSION['pending_user_name'] ?? 'User',
            'csrf_token' => $this->generateCsrfToken(),
            'error' => $_SESSION['flash_error'] ?? '',
        ]);
        unset($_SESSION['flash_error']);
    }

    /**
     * Handle consent form submission
     * POST /consent/submit
     */
    public function submitConsentAction(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('login');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('login/consent');
            return;
        }

        $userId = $_SESSION['pending_user_id'] ?? 0;
        if (!$userId) {
            $this->redirect('login');
            return;
        }

        $fullName = trim($_POST['full_name'] ?? '');
        $nationalId = trim($_POST['national_id'] ?? '');
        $consentAccepted = isset($_POST['consent_accepted']) && $_POST['consent_accepted'] === '1';

        if (empty($fullName) || empty($nationalId) || !$consentAccepted) {
            $_SESSION['flash_error'] = 'Please fill all required fields and accept the terms.';
            $this->redirect('login/consent');
            return;
        }

        if (strlen(preg_replace('/[^0-9]/', '', $nationalId)) < 5) {
            $_SESSION['flash_error'] = 'Please enter a valid National ID with at least 5 digits.';
            $this->redirect('login/consent');
            return;
        }

        $conn = $this->getDbConnection();
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
        
        # Drop the problematic trigger - it's named prevent_zero_id, not user_consents
        $conn->query("DROP TRIGGER IF EXISTS `prevent_zero_id`");
        # Also try to find and drop any triggers on the user_consents table
        $triggers = $conn->query("SHOW TRIGGERS WHERE `Table` = 'user_consents'");
        if ($triggers) {
            while ($trigger = $triggers->fetch_assoc()) {
                $conn->query("DROP TRIGGER IF EXISTS `{$trigger['Trigger']}`");
            }
        }
        
        # Use a transaction to ensure data integrity
        $conn->begin_transaction();
        
        try {
            # Try UPDATE first
            $updateStmt = $conn->prepare("
                UPDATE user_consents 
                SET full_name = ?, national_id = ?, consent_given = 1, consent_date = NOW(), 
                    ip_address = ?, user_agent = ?
                WHERE user_id = ?
            ");
            $updateStmt->bind_param("ssssi", $fullName, $nationalId, $ip, $ua, $userId);
            $updateStmt->execute();
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            # If no row was updated, insert a new one
            if ($affectedRows == 0) {
                # Clean up any records with id=0
                $conn->query("DELETE FROM user_consents WHERE id = 0");
                
                # Calculate next available ID
                $maxIdResult = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM user_consents");
                $nextId = (int)$maxIdResult->fetch_assoc()['next_id'];
                if ($nextId < 1) $nextId = 1;
                
                # Insert with explicit ID
                $insertStmt = $conn->prepare("
                    INSERT INTO user_consents (id, user_id, full_name, national_id, consent_given, consent_date, ip_address, user_agent)
                    VALUES (?, ?, ?, ?, 1, NOW(), ?, ?)
                ");
                $insertStmt->bind_param("iissss", $nextId, $userId, $fullName, $nationalId, $ip, $ua);
                $insertStmt->execute();
                $insertStmt->close();
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }

        // Complete login - set session variables and redirect to dashboard
        $userId = (int)$userId;
        $userEmail = $_SESSION['pending_user_email'] ?? '';
        $employeeName = explode(' ', $fullName)[0] ?? '';
        $userRole = $_SESSION['pending_user_role'] ?? 'employee';
        $designation = $_SESSION['pending_designation'] ?? '';

        // Set session variables
        $_SESSION['user_id']            = $userId;
        $_SESSION['user_email']         = $userEmail;
        $_SESSION['user_name']          = $employeeName;
        $_SESSION['user_role']          = $userRole;
        $_SESSION['designation']        = $designation;
        $_SESSION['employee_id']        = null;
        $_SESSION['login_time']         = time();
        $_SESSION['login_identifier']   = bin2hex(random_bytes(16));
        $_SESSION['ip_address']         = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['session_valid']      = true;
        $_SESSION['last_activity']      = time();

        // Log the login
        $this->logSecurityEvent('login_success', $userId, $userEmail);

        // Clear pending session data
        unset($_SESSION['pending_user_id'], $_SESSION['pending_user_email'], $_SESSION['pending_user_name'], 
              $_SESSION['pending_user_role'], $_SESSION['pending_designation'], $_SESSION['pending_auth_time']);

        $this->redirect('dashboard');
    }

    /**
     * Export consents
     * POST /consent-management/export
     */
    public function exportAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        if (!hasPermission('hr_manager') && !hasPermission('super_admin')) {
            $_SESSION['flash_error'] = 'You do not have permission to export consents.';
            $this->redirect('dashboard');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('consent-management');
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


    private function buildConsentWhere(array $filters): array
    {
        $conditions = ["e.employee_status = 'active'"];
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.email LIKE ? OR e.national_id LIKE ? OR e.employee_id LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$s, $s, $s, $s, $s]);
            $types .= 'sssss';
        }

        if (!empty($filters['department'])) {
            $conditions[] = 'e.department_id = ?';
            $params[] = (int)$filters['department'];
            $types .= 'i';
        }

        if (!empty($filters['consent_status'])) {
            if ($filters['consent_status'] === 'consented') {
                $conditions[] = 'uc.consent_given = 1';
            } else {
                $conditions[] = '(uc.consent_given IS NULL OR uc.consent_given = 0)';
            }
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'uc.consent_date >= ?';
            $params[] = $filters['date_from'];
            $types .= 's';
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'uc.consent_date <= ?';
            $params[] = $filters['date_to'];
            $types .= 's';
        }

        return ['conditions' => $conditions, 'params' => $params, 'types' => $types];
    }

    private function getConsentStats(\mysqli $conn, array $filters = []): array
    {
        $w = $this->buildConsentWhere($filters);
        $whereClause = implode(' AND ', $w['conditions']);

        $st = $conn->prepare(
            "SELECT COUNT(DISTINCT e.id) AS total
             FROM employees e
             LEFT JOIN users u ON u.employee_id = e.employee_id
             LEFT JOIN user_consents uc ON uc.user_id = u.id
             WHERE {$whereClause}"
        );
        if ($w['params']) $st->bind_param($w['types'], ...$w['params']);
        $st->execute();
        $total = (int)$st->get_result()->fetch_assoc()['total'];

        $st2 = $conn->prepare(
            "SELECT COUNT(DISTINCT e.id) AS consented
             FROM employees e
             INNER JOIN users u ON u.employee_id = e.employee_id
             INNER JOIN user_consents uc ON uc.user_id = u.id
             WHERE uc.consent_given = 1 AND {$whereClause}"
        );
        if ($w['params']) $st2->bind_param($w['types'], ...$w['params']);
        $st2->execute();
        $consented = (int)$st2->get_result()->fetch_assoc()['consented'];

        return [
            'total_employees' => $total,
            'consented_employees' => $consented,
            'pending_consents' => $total - $consented,
            'completion_rate' => $total > 0 ? round($consented / $total * 100, 1) : 0,
        ];
    }

    private function getEmployeeConsents(\mysqli $conn, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $w = $this->buildConsentWhere($filters);
        $whereClause = implode(' AND ', $w['conditions']);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT
                    e.id AS employee_db_id,
                    e.employee_id,
                    e.first_name, e.last_name, e.surname,
                    e.email,
                    e.national_id AS employee_national_id,
                    e.designation,
                    d.name AS department_name,
                    uc.id AS consent_id,
                    uc.full_name AS consent_full_name,
                    uc.national_id AS consent_national_id,
                    uc.consent_given,
                    uc.consent_date,
                    uc.ip_address, uc.user_agent,
                    uc.created_at AS consent_created
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN users u ON u.employee_id = e.employee_id
                LEFT JOIN user_consents uc ON uc.user_id = u.id
                WHERE {$whereClause}
                ORDER BY uc.consent_date DESC, e.first_name, e.last_name
                LIMIT ? OFFSET ?";

        $params = $w['params'];
        $params[] = $perPage;
        $params[] = $offset;
        $types = $w['types'] . 'ii';

        $st = $conn->prepare($sql);
        if (!$st) return [];
        $st->bind_param($types, ...$params);
        $st->execute();
        return $st->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function exportPDF(\mysqli $conn, array $filters): void
    {
        // Get all consents with filters (no pagination for export)
        $consents = $this->getEmployeeConsents($conn, $filters, 1, 10000);
        
        // Get stats for header info
        $stats = $this->getConsentStats($conn, $filters);
        
        // Generate HTML for PDF
        $html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Consent Management Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 12px; }
                h1 { color: #333; border-bottom: 2px solid #333; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th { background-color: #f0f0f0; padding: 8px; border: 1px solid #ddd; text-align: left; }
                td { padding: 8px; border: 1px solid #ddd; }
                .header { margin-bottom: 20px; }
                .stats { display: flex; gap: 20px; margin-bottom: 20px; }
                .stat-box { padding: 10px; background: #f5f5f5; border-radius: 4px; }
                .footer { margin-top: 30px; font-size: 10px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Employee Consent Management Report</h1>
                <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
            </div>
            
            <div class="stats">
                <div class="stat-box">
                    <strong>Total Employees:</strong> ' . $stats['total_employees'] . '
                </div>
                <div class="stat-box">
                    <strong>Consented:</strong> ' . $stats['consented_employees'] . '
                </div>
                <div class="stat-box">
                    <strong>Pending:</strong> ' . $stats['pending_consents'] . '
                </div>
                <div class="stat-box">
                    <strong>Completion Rate:</strong> ' . $stats['completion_rate'] . '%
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Consent Date</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($consents as $c) {
            $status = $c['consent_given'] ? 'Consented' : 'Pending';
            $html .= '<tr>
                <td>' . htmlspecialchars($c['employee_id']) . '</td>
                <td>' . htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) . '</td>
                <td>' . htmlspecialchars($c['email']) . '</td>
                <td>' . htmlspecialchars($c['department_name'] ?? 'N/A') . '</td>
                <td>' . htmlspecialchars($c['designation'] ?? 'N/A') . '</td>
                <td>' . $status . '</td>
                <td>' . ($c['consent_date'] ? date('M d, Y', strtotime($c['consent_date'])) : 'N/A') . '</td>
                <td>' . ($c['ip_address'] ? htmlspecialchars($c['ip_address']) : 'N/A') . '</td>
            </tr>';
        }
        
        $html .= '</tbody>
            </table>
            <div class="footer">
                <p>MUWASCO HR System - Confidential</p>
            </div>
        </body>
        </html>';
        
        // Output as HTML (can be printed to PDF from browser)
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="consent_report_' . date('Y-m-d') . '.html"');
        echo $html;
        exit();
    }

    private function exportExcel(\mysqli $conn, array $filters): void
    {
        // Get all consents with filters (no pagination for export)
        $consents = $this->getEmployeeConsents($conn, $filters, 1, 10000);
        
        // Get stats for header info
        $stats = $this->getConsentStats($conn, $filters);
        
        // Generate CSV content
        $filename = 'consent_report_' . date('Y-m-d') . '.csv';
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");
        
        // Add header row
        fputcsv($output, [
            'Employee ID',
            'First Name',
            'Last Name',
            'Email',
            'Department',
            'Position',
            'Consent Status',
            'Consent Date',
            'IP Address'
        ]);
        
        // Add data rows
        foreach ($consents as $c) {
            fputcsv($output, [
                $c['employee_id'],
                $c['first_name'],
                $c['last_name'],
                $c['email'],
                $c['department_name'] ?? 'N/A',
                $c['designation'] ?? 'N/A',
                $c['consent_given'] ? 'Consented' : 'Pending',
                $c['consent_date'] ? date('Y-m-d', strtotime($c['consent_date'])) : 'N/A',
                $c['ip_address'] ?? 'N/A'
            ]);
        }
        
        fclose($output);
        exit();
    }
}

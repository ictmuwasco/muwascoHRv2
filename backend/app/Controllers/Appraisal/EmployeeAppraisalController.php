<?php

declare(strict_types=1);

namespace App\Controllers\Appraisal;

use App\Core\Controller;
use App\Services\AuditService;

class EmployeeAppraisalController extends Controller
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
            SELECT e.* FROM employees e 
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

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {
            $this->handleCommentSubmission($conn, $currentEmployee);
            return;
        }

        // Get employee's appraisals
        $appraisals = $this->getEmployeeAppraisals($conn, $currentEmployee['id']);

        // Get scores for all appraisals
        $scores_by_appraisal = $this->getAppraisalScores($conn, $appraisals);

        $this->view('appraisal/employee_appraisal', [
            'currentEmployee' => $currentEmployee,
            'appraisals' => $appraisals,
            'scores_by_appraisal' => $scores_by_appraisal,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    private function handleCommentSubmission($conn, $currentEmployee): void
    {
        $appraisal_id = (int)($_POST['appraisal_id'] ?? 0);
        $employee_comment = trim($_POST['employee_comment'] ?? '');
        $employee_satisfied = isset($_POST['employee_satisfied']) ? (int)$_POST['employee_satisfied'] : 1;

        if (empty($employee_comment)) {
            $_SESSION['flash_message'] = 'Please enter a comment.';
            $_SESSION['flash_type'] = 'warning';
            $this->redirect('appraisal/employee');
            return;
        }

        $new_status = $employee_satisfied ? 'pending_dept_approval' : 'under_review';

        $updateStmt = $conn->prepare("
            UPDATE employee_appraisals 
            SET employee_comment = ?, 
                employee_satisfied = ?, 
                status = ?,
                employee_comment_date = CURRENT_TIMESTAMP, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ? AND employee_id = ?
        ");
        $updateStmt->bind_param("sisii", $employee_comment, $employee_satisfied, $new_status, $appraisal_id, $currentEmployee['id']);

        if ($updateStmt->execute()) {
            if ($employee_satisfied) {
                $_SESSION['flash_message'] = 'Your comment has been added successfully. The appraisal has been sent to your Department Head for approval.';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Your feedback has been submitted. The appraisal has been escalated for review.';
                $_SESSION['flash_type'] = 'warning';
            }
        } else {
            $_SESSION['flash_message'] = 'Error saving your comment. Please try again.';
            $_SESSION['flash_type'] = 'danger';
        }

        $this->redirect('appraisal/employee');
    }

    private function getEmployeeAppraisals($conn, int $employeeId): array
    {
        $stmt = $conn->prepare("
            SELECT 
                ea.*, 
                ac.name as cycle_name, 
                ac.start_date, 
                ac.end_date,
                e_appraiser.first_name as appraiser_first_name, 
                e_appraiser.last_name as appraiser_last_name,
                ea.escalation_level,
                ea.escalated_to_dept_head,
                ea.escalated_date,
                ea.dept_head_decision,
                ea.dept_head_decision_date,
                ea.employee_satisfied,
                ea.employee_comment_date,
                ea.submitted_at
            FROM employee_appraisals ea
            JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id
            JOIN employees e_appraiser ON ea.appraiser_id = e_appraiser.id
            WHERE ea.employee_id = ? AND ea.status IN ('draft', 'awaiting_employee', 'under_review', 'pending_dept_approval')
            ORDER BY ac.start_date DESC, ea.created_at DESC
        ");
        $stmt->bind_param("i", $employeeId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    }

    private function getAppraisalScores($conn, array $appraisals): array
    {
        if (empty($appraisals)) {
            return [];
        }

        $appraisal_ids = array_column($appraisals, 'id');
        $placeholders = str_repeat('?,', count($appraisal_ids) - 1) . '?';

        $scoresQuery = "
            SELECT as_.*
            FROM appraisal_scores as_
            WHERE as_.employee_appraisal_id IN ($placeholders)
        ";

        $scoresStmt = $conn->prepare($scoresQuery);
        $types = str_repeat('i', count($appraisal_ids));
        $scoresStmt->bind_param($types, ...$appraisal_ids);
        $scoresStmt->execute();
        $scores = $scoresStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $scores_by_appraisal = [];
        foreach ($scores as $score) {
            $scores_by_appraisal[$score['employee_appraisal_id']][$score['performance_indicator_id']] = $score;
        }

        return $scores_by_appraisal;
    }
}
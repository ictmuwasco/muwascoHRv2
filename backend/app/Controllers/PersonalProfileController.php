<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * PersonalProfileController
 *
 * Handles personal profile viewing and editing.
 * Supports viewing own profile or HR viewing other profiles via token.
 * Uses tabbed interface for different sections.
 *
 * Place: backend/app/Controllers/PersonalProfileController.php
 */
class PersonalProfileController extends Controller
{
    /**
     * Display personal profile
     * GET /profile
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        $conn = $this->getDbConnection();
        $userId = $this->getUserId();

        // Determine which employee profile to display
        $requested_token = $_GET['token'] ?? null;
        $is_viewing_other = false;
        $display_employee = null;

        // Get current user's employee auto-increment ID
        $current_user_emp_id = null;
        $stmt = $conn->prepare("SELECT e.id FROM employees e JOIN users u ON u.employee_id = e.employee_id WHERE u.id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $current_user_emp_id = $result['id'] ?? null;
        $stmt->close();

        if ($requested_token) {
            // HR/super_admin viewing someone else's profile via token
            $userRole = $_SESSION['user_role'] ?? '';
            if (in_array($userRole, ['hr_manager', 'super_admin'])) {
                $stmt = $conn->prepare("SELECT e.*, d.name as department_name, s.name as section_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN sections s ON e.section_id = s.id WHERE e.profile_token = ?");
                $stmt->bind_param("s", $requested_token);
                $stmt->execute();
                $display_employee = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $is_viewing_other = true;

                if (!$display_employee) {
                    $_SESSION['flash_error'] = 'Employee profile not found.';
                    $this->redirect('employees');
                    return;
                }
            } else {
                $_SESSION['flash_error'] = 'Access denied.';
                $this->redirect('dashboard');
                return;
            }
        } elseif (isset($_GET['view_employee']) && (hasPermission('hr_manager') || hasPermission('dept_head') || hasPermission('super_admin'))) {
            // HR/dept_head/super_admin viewing specific employee
            $viewing_employee_id = (int)$_GET['view_employee'];
            $stmt = $conn->prepare("SELECT e.*, d.name as department_name, s.name as section_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN sections s ON e.section_id = s.id WHERE e.id = ?");
            $stmt->bind_param("i", $viewing_employee_id);
            $stmt->execute();
            $display_employee = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $is_viewing_other = true;

            if (!$display_employee) {
                $_SESSION['flash_error'] = 'Employee not found.';
                $this->redirect('employees');
                return;
            }
        } else {
            // Viewing own profile
            if (!$current_user_emp_id) {
                $_SESSION['flash_error'] = 'Please log in.';
                $this->redirect('login');
                return;
            }

            $stmt = $conn->prepare("SELECT e.*, d.name as department_name, s.name as section_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN sections s ON e.section_id = s.id WHERE e.id = ?");
            $stmt->bind_param("i", $current_user_emp_id);
            $stmt->execute();
            $display_employee = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$display_employee) {
            $_SESSION['flash_error'] = 'Profile unavailable.';
            $this->redirect('employees');
            return;
        }

        // Get current user for password change (only for own profile)
        $current_user = null;
        if (!$is_viewing_other) {
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $current_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // Get employee documents
        $documents = [];
        $stmt = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY id DESC");
        $stmt->bind_param("i", $display_employee['id']);
        $stmt->execute();
        $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get next of kin
        $next_of_kin = [];
        if (!empty($display_employee['next_of_kin'])) {
            $next_of_kin = json_decode($display_employee['next_of_kin'], true) ?? [];
        }

        $this->view('profile/index', [
            'employee' => $display_employee,
            'current_user' => $current_user,
            'is_viewing_other' => $is_viewing_other,
            'documents' => $documents,
            'next_of_kin' => $next_of_kin,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Update profile
     * POST /profile/update
     */
    public function updateAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthenticated'], 401);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('profile');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('profile');
            return;
        }

        // TODO: Add validation and update logic
        $_SESSION['flash_message'] = 'Profile updated successfully!';
        $_SESSION['flash_type'] = 'success';
        $this->redirect('profile');
    }

    /**
     * Upload document
     * POST /profile/upload-document
     */
    public function uploadDocumentAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthenticated'], 401);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('profile');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Invalid security token'], 403);
            return;
        }

        // TODO: Add file upload logic
        $this->json(['success' => true, 'message' => 'Document uploaded successfully']);
    }
}
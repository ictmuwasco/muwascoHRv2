<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * UsersController
 *
 * Handles user management including CRUD operations and password resets.
 *
 * Place: backend/app/Controllers/UsersController.php
 */
class UsersController extends Controller
{
    /**
     * Display users list
     * GET /users
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Only HR managers and super admins can view users
        $auth = \App\Helpers\Auth::getInstance();
        if (!$auth->hasPermission('hr_manager', 'view') && !$auth->hasPermission('super_admin', 'view')) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();

        // Fetch all users
        $allUsers = $conn->query("SELECT * FROM users ORDER BY first_name, last_name")
            ->fetch_all(MYSQLI_ASSOC);

        // Get valid roles from database ENUM
        $valid_roles = [];
        $enumRow = $conn->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch_assoc();
        if ($enumRow && preg_match("/^enum\('(.*)'\)$/", $enumRow['Type'], $m)) {
            $valid_roles = explode("','", $m[1]);
        }
        if (empty($valid_roles)) {
            $valid_roles = [
                'super_admin', 'hr_manager', 'dept_head', 'section_head',
                'manager', 'managing_director', 'officer', 'bod_chairman', 'sub_section_head',
            ];
        }

        $this->view('users/index', [
            'allUsers' => $allUsers,
            'valid_roles' => $valid_roles,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Add user
     * POST /users/add
     */
    public function addUserAction(): void
    {
        $this->handleUserRequest('add');
    }

    /**
     * Edit user
     * POST /users/edit
     */
    public function editUserAction(): void
    {
        $this->handleUserRequest('edit');
    }

    /**
     * Delete user
     * POST /users/delete
     */
    public function deleteUserAction(): void
    {
        $this->handleUserRequest('delete');
    }

    /**
     * Reset password
     * POST /users/reset-password
     */
    public function resetPasswordAction(): void
    {
        $this->handleUserRequest('reset_password');
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleUserRequest(string $action): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $auth = \App\Helpers\Auth::getInstance();
        if (!$auth->hasPermission('hr_manager', 'view') && !$auth->hasPermission('super_admin', 'view')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('users');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('users');
            return;
        }

        $conn = $this->getDbConnection();

        // Get valid roles
        $valid_roles = [];
        $enumRow = $conn->query("SHOW COLUMNS FROM users WHERE Field = 'role'")->fetch_assoc();
        if ($enumRow && preg_match("/^enum\('(.*)'\)$/", $enumRow['Type'], $m)) {
            $valid_roles = explode("','", $m[1]);
        }
        if (empty($valid_roles)) {
            $valid_roles = [
                'super_admin', 'hr_manager', 'dept_head', 'section_head',
                'manager', 'managing_director', 'officer', 'bod_chairman', 'sub_section_head',
            ];
        }

        try {
            if ($action === 'add_user') {
                $this->addUser($conn, $valid_roles);
            } elseif ($action === 'edit_user') {
                $this->editUser($conn, $valid_roles);
            } elseif ($action === 'delete_user') {
                $this->deleteUser($conn);
            } elseif ($action === 'reset_password') {
                $this->resetPassword($conn);
            }
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('users');
    }

    private function addUser(\mysqli $conn, array $valid_roles): void
    {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $employee_id = trim($_POST['employee_id'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $designation = trim($_POST['designation'] ?? '');

        if (!in_array($role, $valid_roles, true)) {
            throw new Exception('Invalid role selected.');
        }

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }

        // Check if email exists
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $chk->bind_param('s', $email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            throw new Exception('Email already exists in the system.');
        }

        $uid = 'USR-' . time() . rand(1000, 9999);
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $conn->prepare(
            "INSERT INTO users
             (id, first_name, last_name, surname, email, password,
              role, designation, phone, address, gender, employee_id,
              created_at, updated_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)"
        );
        $st->bind_param('ssssssssssss',
            $uid, $first_name, $last_name, $surname, $email, $hash,
            $role, $designation, $phone, $address, $gender, $employee_id
        );

        if ($st->execute()) {
            $_SESSION['flash_message'] = 'User created successfully!';
            $_SESSION['flash_type'] = 'success';
        } else {
            throw new Exception('Error creating user: ' . $conn->error);
        }
    }

    private function editUser(\mysqli $conn, array $valid_roles): void
    {
        $id = trim($_POST['id'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $surname = trim($_POST['surname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $password = $_POST['password'] ?? '';
        $employee_id = trim($_POST['employee_id'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($role, $valid_roles, true)) {
            throw new Exception('Invalid role selected.');
        }

        if (!empty($password) && strlen($password) < 6) {
            throw new Exception('New password must be at least 6 characters.');
        }

        // Check if email exists for another user
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->bind_param('ss', $email, $id);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            throw new Exception('Email already in use by another account.');
        }

        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $st = $conn->prepare(
                "UPDATE users SET first_name=?, last_name=?, surname=?, email=?, password=?,
                 role=?, designation=?, phone=?, address=?, gender=?, employee_id=?, is_active=?, updated_at=NOW()
                 WHERE id=?"
            );
            $st->bind_param('sssssssssssss',
                $first_name, $last_name, $surname, $email, $hash,
                $role, $designation, $phone, $address, $gender, $employee_id, $is_active, $id
            );
        } else {
            $st = $conn->prepare(
                "UPDATE users SET first_name=?, last_name=?, surname=?, email=?,
                 role=?, designation=?, phone=?, address=?, gender=?, employee_id=?, is_active=?, updated_at=NOW()
                 WHERE id=?"
            );
            $st->bind_param('sssssssssss',
                $first_name, $last_name, $surname, $email,
                $role, $designation, $phone, $address, $gender, $employee_id, $is_active, $id
            );
        }

        if ($st->execute()) {
            $_SESSION['flash_message'] = 'User updated successfully!';
            $_SESSION['flash_type'] = 'success';
        } else {
            throw new Exception('Error updating user: ' . $conn->error);
        }
    }

    private function deleteUser(\mysqli $conn): void
    {
        $id = trim($_POST['id'] ?? '');
        $current_user_id = $_SESSION['user_id'];

        if ($id === $current_user_id) {
            throw new Exception('You cannot delete your own account.');
        }

        $st = $conn->prepare("DELETE FROM users WHERE id = ?");
        $st->bind_param('s', $id);

        if ($st->execute() && $st->affected_rows > 0) {
            $_SESSION['flash_message'] = 'User deleted successfully!';
            $_SESSION['flash_type'] = 'success';
        } else {
            throw new Exception('Error deleting user: ' . $conn->error);
        }
    }

    private function resetPassword(\mysqli $conn): void
    {
        $id = trim($_POST['id'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $conn->prepare("UPDATE users SET password=?, updated_at=NOW() WHERE id=?");
        $st->bind_param('ss', $hash, $id);

        if ($st->execute()) {
            $_SESSION['flash_message'] = 'Password reset successfully!';
            $_SESSION['flash_type'] = 'success';
        } else {
            throw new Exception('Error resetting password: ' . $conn->error);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\UserServiceInterface;
use App\Services\UserService;
use App\Repositories\UserRepository;
use App\Services\AuditService;

/**
 * UsersController
 *
 * Handles user management including CRUD operations and password resets.
 * Thin controller that delegates business logic to UserService.
 *
 * Place: backend/app/Controllers/UsersController.php
 */
class UsersController extends Controller
{
    private UserServiceInterface $userService;
    private AuditService $audit;

    public function __construct()
    {
        // Dependency injection
        $this->userService = new UserService();
        $this->userService->setUserRepository(new UserRepository());
        $this->userService->setEmployeeRepository(new \App\Repositories\EmployeeRepository());
        
        $this->audit = AuditService::getInstance();
    }

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

        // Only admins, HR managers and super admins can view users
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['super_admin', 'hr_manager', 'admin', 'administrator'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        try {
            // Fetch all users using service
            $result = $this->userService->getAllUsers();
            $allUsers = $result['data'] ?? $result;

            // Get valid roles (hardcoded for now, could be moved to config)
            $valid_roles = [
                'super_admin', 'hr_manager', 'dept_head', 'section_head',
                'manager', 'managing_director', 'officer', 'bod_chairman', 'sub_section_head',
            ];

        } catch (\Exception $e) {
            $allUsers = [];
            $valid_roles = [
                'super_admin', 'hr_manager', 'dept_head', 'section_head',
                'manager', 'managing_director', 'officer', 'bod_chairman', 'sub_section_head',
            ];
            $_SESSION['flash_error'] = 'Error fetching users: ' . $e->getMessage();
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

        // Get valid roles
        $valid_roles = [
            'super_admin', 'hr_manager', 'dept_head', 'section_head',
            'manager', 'managing_director', 'officer', 'bod_chairman', 'sub_section_head',
        ];

        try {
            if ($action === 'add_user') {
                $this->addUser($valid_roles);
            } elseif ($action === 'edit_user') {
                $this->editUser($valid_roles);
            } elseif ($action === 'delete_user') {
                $this->deleteUser();
            } elseif ($action === 'reset_password') {
                $this->resetPassword();
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('users');
    }

    private function addUser(array $valid_roles): void
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

        // Create user using service
        $userId = $this->userService->createUser([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'surname' => $surname,
            'email' => $email,
            'password' => $password,
            'role' => $role,
            'phone' => $phone,
            'address' => $address,
            'employee_id' => $employee_id,
            'gender' => $gender,
            'designation' => $designation,
        ]);

        $_SESSION['flash_message'] = 'User created successfully!';
        $_SESSION['flash_type'] = 'success';
    }

    private function editUser(array $valid_roles): void
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

        // Get old user data for audit
        $oldUser = $this->userService->getUserById((int)$id);

        // Update user using service
        $updateData = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'surname' => $surname,
            'email' => $email,
            'role' => $role,
            'phone' => $phone,
            'address' => $address,
            'employee_id' => $employee_id,
            'gender' => $gender,
            'designation' => $designation,
            'is_active' => $is_active,
        ];

        // Only include password if provided
        if (!empty($password)) {
            $updateData['password'] = $password;
        }

        $this->userService->updateUser((int)$id, $updateData);

        $_SESSION['flash_message'] = 'User updated successfully!';
        $_SESSION['flash_type'] = 'success';
        
        // Audit log
        if ($oldUser) {
            $this->audit->logUpdate(
                'users',
                (int)$id,
                $oldUser,
                array_merge($oldUser, $updateData),
                "Updated user: {$first_name} {$last_name}"
            );
        }
    }

    private function deleteUser(): void
    {
        $id = trim($_POST['id'] ?? '');
        $current_user_id = $_SESSION['user_id'];

        if ($id === $current_user_id) {
            throw new \InvalidArgumentException('You cannot delete your own account.');
        }

        // Get user data before deletion for audit
        $oldUser = $this->userService->getUserById((int)$id);

        // Delete user using service
        $this->userService->deleteUser((int)$id);

        $_SESSION['flash_message'] = 'User deleted successfully!';
        $_SESSION['flash_type'] = 'success';
        
        // Audit log
        if ($oldUser) {
            $this->audit->logDelete(
                'users',
                (int)$id,
                $oldUser,
                "Deleted user: " . ($oldUser['first_name'] ?? '') . " " . ($oldUser['last_name'] ?? '')
            );
        }
    }

    private function resetPassword(): void
    {
        $id = trim($_POST['id'] ?? '');
        $password = trim($_POST['password'] ?? '');

        // Get user data for audit
        $user = $this->userService->getUserById((int)$id);

        // Reset password using service
        $this->userService->updatePassword((int)$id, $password);

        $_SESSION['flash_message'] = 'Password reset successfully!';
        $_SESSION['flash_type'] = 'success';
        
        // Audit log
        if ($user) {
            $this->audit->log(
                'PASSWORD_RESET',
                "Reset password for user: " . ($user['first_name'] ?? '') . " " . ($user['last_name'] ?? ''),
                [
                    'table_name' => 'users',
                    'record_id' => (int)$id,
                ]
            );
        }
    }
}

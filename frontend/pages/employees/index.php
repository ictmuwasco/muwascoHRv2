<?php

declare(strict_types=1);

/**
 * MUWASCO HR System - Employee Management Page
 * 
 * Modular frontend page migrated from legacy employees.php
 */

require_once __DIR__ . '/../../../../backend/bootstrap.php';

// Apply security headers
\App\Middleware\SecurityMiddleware::applySecurityHeaders();
\App\Middleware\SecurityMiddleware::ensureCsrfToken();

// Require authentication
\App\Middleware\AuthMiddleware::requireAuth();

// Check permission
$rbac = \App\Helpers\RBAC::getInstance();
if (!$rbac->currentUserCan('employees', 'view')) {
    header('Location: /hrdemo/');
    exit();
}

$userId = $_SESSION['user_id'] ?? 0;
$userName = $_SESSION['user_name'] ?? '';
$userRole = $_SESSION['user_role'] ?? '';

// Get notification count
$notificationService = \App\Services\NotificationService::getInstance();
$unreadNotifications = $notificationService->getUnreadCount($userId);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - MUWASCO HR System</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="frontend/assets/css/main.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        },
                        sidebar: {
                            bg: '#1e293b',
                            hover: '#334155',
                            active: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="bg-white text-gray-800 w-64 flex-shrink-0 hidden md:flex flex-col shadow-md">
            <div class="p-4 border-b border-gray-700">
                <div class="flex items-center space-x-3">
                    <img src="muwascologo.png" alt="MUWASCO" class="w-10 h-10 rounded">
                    <div>
                        <h1 class="font-bold text-sm">MUWASCO HR</h1>
                        <p class="text-xs text-gray-400">Management System</p>
                    </div>
                </div>
            </div>
            
            <nav class="flex-1 overflow-y-auto p-2 space-y-1">
                <a href="/hrdemo/" class="nav-item"><i class="fas fa-tachometer-alt w-6"></i><span>Dashboard</span></a>
                <a href="/hrdemo/frontend/pages/employees/" class="nav-item active"><i class="fas fa-users w-6"></i><span>Employees</span></a>
                <a href="/hrdemo/frontend/pages/attendance/" class="nav-item"><i class="fas fa-fingerprint w-6"></i><span>Attendance</span></a>
                <a href="/hrdemo/frontend/pages/leave/" class="nav-item"><i class="fas fa-calendar-alt w-6"></i><span>Leave</span></a>
                <a href="/hrdemo/payroll.php" class="nav-item"><i class="fas fa-money-bill-wave w-6"></i><span>Payroll</span></a>
                <a href="/hrdemo/frontend/pages/complaints/" class="nav-item"><i class="fas fa-exclamation-circle w-6"></i><span>Complaints</span></a>
                <hr class="border-gray-700 my-2">
                <a href="/hrdemo/users.php" class="nav-item"><i class="fas fa-user-cog w-6"></i><span>Users</span></a>
                <a href="/hrdemo/audit_dashboard.php" class="nav-item"><i class="fas fa-history w-6"></i><span>Audit Trail</span></a>
            </nav>
            
            <div class="p-4 border-t border-gray-700">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-primary-600 rounded-full flex items-center justify-center text-sm font-bold">
                        <?= strtoupper(substr($userName, 0, 1)) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate"><?= htmlspecialchars($userName) ?></p>
                        <p class="text-xs text-gray-400 truncate"><?= htmlspecialchars($userRole) ?></p>
                    </div>
                    <a href="/hrdemo/logout.php" class="text-gray-400 hover:text-white"><i class="fas fa-sign-out-alt"></i></a>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b border-gray-200">
                <div class="flex items-center justify-between px-4 py-3">
                    <div class="flex items-center space-x-3">
                        <button onclick="toggleSidebar()" class="md:hidden text-gray-500 hover:text-gray-700">
                            <i class="fas fa-bars text-xl"></i>
                        </button>
                        <h2 class="text-xl font-semibold text-gray-800">Employee Management</h2>
                    </div>
                    
                    <div class="flex items-center space-x-4">
                        <a href="/hrdemo/notifications/notifications.php" class="relative text-gray-500 hover:text-gray-700">
                            <i class="fas fa-bell text-xl"></i>
                            <?php if ($unreadNotifications > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                                <?= min($unreadNotifications, 99) ?>
                            </span>
                            <?php endif; ?>
                        </a>
                        <a href="/hrdemo/profile.php" class="text-gray-700 hover:text-gray-900">
                            <i class="fas fa-user-circle text-xl"></i>
                        </a>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 overflow-y-auto p-4 md:p-6 bg-gradient-to-b from-white to-gray-100">
                <div class="space-y-6">
                    <!-- Actions -->
                    <div class="flex justify-between items-center">
                        <div class="flex gap-2">
                            <button onclick="showAddModal()" class="btn btn-success">
                                <i class="fas fa-plus"></i> Add New Employee
                            </button>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Search & Filters -->
                    <div class="card">
                        <h3 class="card-title mb-4">Search & Filter</h3>
                        <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <input type="text" 
                                       name="search" 
                                       class="form-input" 
                                       placeholder="Search by name, ID, or email..."
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            </div>
                            <div>
                                <select name="department" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php
                                    $db = \db();
                                    $departments = $db->fetchAll("SELECT * FROM departments ORDER BY name");
                                    foreach ($departments as $dept):
                                    ?>
                                    <option value="<?= (int)$dept['id'] ?>" <?= ($_GET['department'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <select name="type" class="form-select">
                                    <option value="">All Types</option>
                                    <option value="officer" <?= ($_GET['type'] ?? '') === 'officer' ? 'selected' : '' ?>>Officer</option>
                                    <option value="section_head" <?= ($_GET['type'] ?? '') === 'section_head' ? 'selected' : '' ?>>Section Head</option>
                                    <option value="manager" <?= ($_GET['type'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                                    <option value="hr_manager" <?= ($_GET['type'] ?? '') === 'hr_manager' ? 'selected' : '' ?>>HR Manager</option>
                                    <option value="dept_head" <?= ($_GET['type'] ?? '') === 'dept_head' ? 'selected' : '' ?>>Department Head</option>
                                    <option value="managing_director" <?= ($_GET['type'] ?? '') === 'managing_director' ? 'selected' : '' ?>>Managing Director</option>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary w-full">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Employees Table -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Employees (<?= $total ?? 0 ?>)</h3>
                        </div>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8 text-gray-500">
                                            No employees found
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($emp['employee_id']) ?></td>
                                        <td>
                                            <a href="/hrdemo/personal_profile.php?token=<?= urlencode($emp['profile_token']) ?>" 
                                               class="text-primary-600 hover:text-primary-800">
                                                <?= htmlspecialchars(trim($emp['first_name'] . ' ' . $emp['last_name'] . ' ' . ($emp['surname'] ?? ''))) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($emp['email'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?= $emp['employee_type'] ? htmlspecialchars(ucwords(str_replace('_', ' ', $emp['employee_type']))) : 'N/A' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= ($emp['employee_status'] ?? '') === 'active' ? 'badge-success' : 'badge-danger' ?>">
                                                <?= htmlspecialchars(ucwords($emp['employee_status'] ?? 'N/A')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex gap-1">
                                                <button onclick="editEmployee(<?= (int)$emp['id'] ?>)" 
                                                        class="btn btn-primary btn-sm" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="/hrdemo/personal_profile.php?token=<?= urlencode($emp['profile_token']) ?>" 
                                                   class="btn btn-info btn-sm" title="View Profile">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button onclick="deleteEmployee(<?= (int)$emp['id'] ?>, '<?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>')" 
                                                        class="btn btn-danger btn-sm" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if (($totalPages ?? 1) > 1): ?>
                        <div class="pagination mt-4">
                            <?php if (($page ?? 1) > 1): ?>
                            <a href="?page=<?= ($page ?? 1) - 1 ?><?= isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '' ?><?= isset($_GET['department']) ? '&department=' . urlencode($_GET['department']) : '' ?><?= isset($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>" 
                               class="page-link">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, ($page ?? 1) - 2); $i <= min($totalPages ?? 1, ($page ?? 1) + 2); $i++): ?>
                            <a href="?page=<?= $i ?><?= isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '' ?><?= isset($_GET['department']) ? '&department=' . urlencode($_GET['department']) : '' ?><?= isset($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>" 
                               class="page-link <?= $i === ($page ?? 1) ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                            <?php endfor; ?>
                            
                            <?php if (($page ?? 1) < ($totalPages ?? 1)): ?>
                            <a href="?page=<?= ($page ?? 1) + 1 ?><?= isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '' ?><?= isset($_GET['department']) ? '&department=' . urlencode($_GET['department']) : '' ?><?= isset($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>" 
                               class="page-link">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="employeeModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="text-lg font-semibold" id="modalTitle">Add New Employee</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="employeeForm" class="modal-body space-y-4">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="employeeId">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Employee ID *</label>
                        <input type="text" name="employee_id" id="employeeIdInput" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" id="firstName" class="form-input" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" id="lastName" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" id="email" class="form-input" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" id="phone" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Designation *</label>
                        <input type="text" name="designation" id="designation" class="form-input" required>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="department" class="form-select">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= (int)$dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employee Type *</label>
                        <select name="employee_type" id="employeeType" class="form-select" required>
                            <option value="">Select Type</option>
                            <option value="officer">Officer</option>
                            <option value="section_head">Section Head</option>
                            <option value="manager">Manager</option>
                            <option value="hr_manager">HR Manager</option>
                            <option value="dept_head">Department Head</option>
                            <option value="managing_director">Managing Director</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Employment Type</label>
                        <select name="employment_type" id="employmentType" class="form-select">
                            <option value="permanent">Permanent</option>
                            <option value="contract">Contract</option>
                            <option value="temporary">Temporary</option>
                            <option value="intern">Intern</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="employee_status" id="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="resigned">Resigned</option>
                            <option value="fired">Fired</option>
                            <option value="retired">Retired</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Hire Date *</label>
                    <input type="date" name="hire_date" id="hireDate" class="form-input" required>
                </div>
            </form>
            <div class="modal-footer">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                <button type="button" onclick="saveEmployee()" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Employee
                </button>
            </div>
        </div>
    </div>

    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('aside');
            sidebar.classList.toggle('hidden');
            sidebar.classList.toggle('fixed');
            sidebar.classList.toggle('inset-0');
            sidebar.classList.toggle('z-50');
        }

        // Show add modal
        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Employee';
            document.getElementById('formAction').value = 'add';
            document.getElementById('employeeForm').reset();
            document.getElementById('employeeModal').style.display = 'flex';
        }

        // Edit employee
        function editEmployee(id) {
            // Fetch employee data
            fetch(`/hrdemo/api/employees/${id}`)
                .then(res => res.json())
                .then(result => {
                    if (result.success) {
                        const emp = result.data;
                        document.getElementById('modalTitle').textContent = 'Edit Employee';
                        document.getElementById('formAction').value = 'edit';
                        document.getElementById('employeeId').value = emp.id;
                        document.getElementById('employeeIdInput').value = emp.employee_id;
                        document.getElementById('firstName').value = emp.first_name;
                        document.getElementById('lastName').value = emp.last_name;
                        document.getElementById('email').value = emp.email;
                        document.getElementById('phone').value = emp.phone;
                        document.getElementById('designation').value = emp.designation;
                        document.getElementById('department').value = emp.department_id || '';
                        document.getElementById('employeeType').value = emp.employee_type;
                        document.getElementById('employmentType').value = emp.employment_type;
                        document.getElementById('status').value = emp.employee_status;
                        document.getElementById('hireDate').value = emp.hire_date;
                        document.getElementById('employeeModal').style.display = 'flex';
                    }
                });
        }

        // Close modal
        function closeModal() {
            document.getElementById('employeeModal').style.display = 'none';
        }

        // Save employee
        async function saveEmployee() {
            const form = document.getElementById('employeeForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            const action = data.action;
            
            const url = action === 'edit' 
                ? `/hrdemo/api/employees/${data.id}` 
                : '/hrdemo/api/employees';
            
            const method = action === 'edit' ? 'PUT' : 'POST';
            
            try {
                const res = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                
                if (result.success) {
                    alert('Employee saved successfully!');
                    closeModal();
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (err) {
                alert('Failed to save employee: ' + err.message);
            }
        }

        // Delete employee
        async function deleteEmployee(id, name) {
            if (!confirm(`Delete employee ${name}? This action cannot be undone.`)) return;
            
            try {
                const res = await fetch(`/hrdemo/api/employees/${id}`, { method: 'DELETE' });
                const result = await res.json();
                
                if (result.success) {
                    alert('Employee deleted successfully');
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (err) {
                alert('Failed to delete employee: ' + err.message);
            }
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('employeeModal');
            if (event.target === modal) {
                closeModal();
            }
        };
    </script>
</body>
</html>
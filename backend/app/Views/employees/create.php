<?php
/**
 * Create Employee View
 *
 * Place: backend/app/Views/employees/create.php
 */
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">
    <?php require __DIR__ . '/../components/navbar.php'; ?>
    <?php require __DIR__ . '/../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16">
        <div class="w-full px-4 sm:px-6 lg:px-8 py-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900">Add New Employee</h1>
                <p class="text-gray-600 mt-1">Create a new employee record</p>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
                <form action="<?= BASE_URL ?>/?route=employees/store" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="form-group">
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" required class="form-input" placeholder="Enter first name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" required class="form-input" placeholder="Enter last name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" required class="form-input" placeholder="Enter email address">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-input" placeholder="Enter phone number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Department *</label>
                            <select name="department_id" required class="form-select">
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Section *</label>
                            <select name="section_id" required class="form-select">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?= $section['id'] ?>"><?= htmlspecialchars($section['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employee Type *</label>
                            <select name="employee_type" required class="form-select">
                                <option value="permanent">Permanent</option>
                                <option value="contract">Contract</option>
                                <option value="intern">Intern</option>
                                <option value="casual">Casual</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Employment Status *</label>
                            <select name="employee_status" required class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="on_leave">On Leave</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center gap-4 mt-8">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Employee
                        </button>
                        <a href="<?= BASE_URL ?>/?route=employees" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
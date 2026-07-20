<?php
/** @var array $employees */
/** @var array $departments */
/** @var array $sections */
/** @var array $roles */
/** @var array $stats */
/** @var string $search */
/** @var string $department */
/** @var string $section */
/** @var string $role */
/** @var string $status */
/** @var int $page */
/** @var int $totalPages */
/** @var int $totalRecords */
/** @var string $csrf_token */
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission Overrides - HR Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900 dark:bg-dark-bg dark:text-white">

    <?php require __DIR__ . '/../../components/navbar.php'; ?>
    <?php require __DIR__ . '/../../components/header_bar.php'; ?>

    <div class="lg:pl-64 pt-16 bg-gray-50 dark:bg-dark-bg min-h-screen">
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full">
            
            <!-- Page Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Permission Overrides</h1>
                <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Manage user-specific permission overrides</p>
            </div>

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700 text-green-800 dark:text-green-300 text-sm">
                    <i class="fas fa-check-circle mr-1"></i><?= htmlspecialchars($_SESSION['flash_message']) ?>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="mb-6 px-4 py-3 rounded-lg bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700 text-red-800 dark:text-red-300 text-sm">
                    <i class="fas fa-exclamation-circle mr-1"></i><?= htmlspecialchars($_SESSION['flash_error']) ?>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <!-- Admin Tabs -->
            <?php $activeTab = 'permission-overrides'; include __DIR__ . '/../../components/admin_tabs.php'; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="stat-card bg-gradient-to-br from-blue-500/10 to-blue-600/10 dark:from-blue-500/20 dark:to-blue-600/20">
                    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-blue-500 dark:bg-blue-600 mb-4 mx-auto">
                        <i class="fas fa-user-shield text-white text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-1"><?= number_format($stats['total_overrides']) ?></h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm font-medium">Total Overrides</p>
                </div>
                
                <div class="stat-card bg-gradient-to-br from-green-500/10 to-green-600/10 dark:from-green-500/20 dark:to-green-600/20">
                    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-green-500 dark:bg-green-600 mb-4 mx-auto">
                        <i class="fas fa-check-circle text-white text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-1"><?= number_format($stats['allow_overrides']) ?></h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm font-medium">Explicit Allows</p>
                </div>
                
                <div class="stat-card bg-gradient-to-br from-red-500/10 to-red-600/10 dark:from-red-500/20 dark:to-red-600/20">
                    <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-red-500 dark:bg-red-600 mb-4 mx-auto">
                        <i class="fas fa-times-circle text-white text-xl"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900 dark:text-white mb-1"><?= number_format($stats['deny_overrides']) ?></h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm font-medium">Explicit Denies</p>
                </div>
            </div>

            <!-- Search and Filters -->
            <div class="glass-card mb-6">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-white/10">
                    <h5 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <i class="fas fa-search mr-2 text-primary-400"></i>Search Employees
                    </h5>
                </div>
                <div class="p-6">
                    <form method="GET" action="<?= BASE_URL ?>/?route=admin/permission-overrides" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                        <div>
                            <label for="search" class="form-label">Search</label>
                            <input type="text" 
                                   class="form-input"
                                   id="search" 
                                   name="search" 
                                   value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Employee ID, Name..."
                                   autocomplete="off">
                        </div>
                        <div>
                            <label for="department" class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= htmlspecialchars($dept['department']) ?>" 
                                            <?= $department === $dept['department'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dept['department']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="section" class="form-label">Section</label>
                            <select class="form-select" id="section" name="section">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $sec): ?>
                                    <option value="<?= htmlspecialchars($sec['section']) ?>" 
                                            <?= $section === $sec['section'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sec['section']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role">
                                <option value="">All Roles</option>
                                <?php foreach ($roles as $roleValue => $roleName): ?>
                                    <option value="<?= htmlspecialchars($roleValue) ?>" 
                                            <?= $role === $roleValue ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($roleName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-lg transition duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-filter"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Employees Table -->
            <div class="glass-card">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-white/10 flex justify-between items-center">
                    <h5 class="text-lg font-semibold text-gray-900 dark:text-white">
                        <i class="fas fa-users mr-2 text-primary-400"></i>Employees (<?= number_format($totalRecords) ?>)
                    </h5>
                </div>
                <div class="p-6">
                    <?php if (empty($employees)): ?>
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-300 px-4 py-3 rounded-lg">
                            <i class="fas fa-info-circle mr-2"></i>No employees found matching your criteria.
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50 dark:bg-white/5">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee ID</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Department</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Section</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Role</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Overrides</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-dark-secondary divide-y divide-gray-200 dark:divide-white/10">
                                    <?php foreach ($employees as $emp): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition duration-150">
                                            <td class="px-4 py-4">
                                                <strong class="text-gray-900 dark:text-white"><?= htmlspecialchars($emp['full_name']) ?></strong><br>
                                                <small class="text-gray-500 dark:text-gray-400"><?= htmlspecialchars($emp['email']) ?></small>
                                            </td>
                                            <td class="px-4 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($emp['employee_id']) ?></td>
                                            <td class="px-4 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                                            <td class="px-4 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($emp['section'] ?? 'N/A') ?></td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $emp['role'] === 'super_admin' ? 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300' : ($emp['role'] === 'hr_manager' ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300' : 'bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300') ?>">
                                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $emp['role']))) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $emp['employee_status'] === 'active' ? 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300' ?>">
                                                    <?= ucfirst($emp['employee_status']) ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-4">
                                                <?php if ($emp['override_count'] > 0): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300">
                                                        <?= $emp['override_count'] ?> override(s)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400 dark:text-gray-500 text-sm">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-4">
                                                <a href="<?= BASE_URL ?>/?route=admin/permission-overrides/manage/<?= $emp['user_id'] ?>" 
                                                   class="inline-flex items-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-200 shadow-md hover:shadow-lg"
                                                   title="Manage Permissions">
                                                    <i class="fas fa-edit mr-1"></i>Manage
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="mt-6">
                                <nav class="flex justify-center">
                                    <ul class="flex space-x-2">
                                        <?php if ($page > 1): ?>
                                            <li>
                                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>&section=<?= urlencode($section) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>" 
                                                   class="px-3 py-2 bg-white dark:bg-dark-secondary border border-gray-300 dark:border-white/10 rounded-lg hover:bg-gray-50 dark:hover:bg-white/5 text-gray-700 dark:text-gray-300 transition duration-200">
                                                    Previous
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                            <li>
                                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>&section=<?= urlencode($section) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>" 
                                                   class="px-3 py-2 rounded-lg transition duration-200 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white dark:bg-dark-secondary border border-gray-300 dark:border-white/10 hover:bg-gray-50 dark:hover:bg-white/5 text-gray-700 dark:text-gray-300' ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($page < $totalPages): ?>
                                            <li>
                                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>&section=<?= urlencode($section) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>" 
                                                   class="px-3 py-2 bg-white dark:bg-dark-secondary border border-gray-300 dark:border-white/10 rounded-lg hover:bg-gray-50 dark:hover:bg-white/5 text-gray-700 dark:text-gray-300 transition duration-200">
                                                    Next
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
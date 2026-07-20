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

<div class="admin-tab-content">
    <?php include __DIR__ . '/../../components/admin_tabs.php'; ?>
    
    <div class="container-fluid px-4 py-6">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-6 flex items-center">
                <div class="bg-blue-500 rounded-lg p-4 mr-4">
                    <i class="fas fa-user-shield text-white text-2xl"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_overrides']) ?></h3>
                    <p class="text-gray-600 text-sm">Total Overrides</p>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6 flex items-center">
                <div class="bg-green-500 rounded-lg p-4 mr-4">
                    <i class="fas fa-check-circle text-white text-2xl"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-bold text-gray-800"><?= number_format($stats['allow_overrides']) ?></h3>
                    <p class="text-gray-600 text-sm">Explicit Allows</p>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-6 flex items-center">
                <div class="bg-red-500 rounded-lg p-4 mr-4">
                    <i class="fas fa-times-circle text-white text-2xl"></i>
                </div>
                <div>
                    <h3 class="text-2xl font-bold text-gray-800"><?= number_format($stats['deny_overrides']) ?></h3>
                    <p class="text-gray-600 text-sm">Explicit Denies</p>
                </div>
            </div>
        </div>

        <!-- Search and Filters -->
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h5 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-search mr-2"></i>Search Employees
                </h5>
            </div>
            <div class="p-6">
                <form method="GET" action="<?= BASE_URL ?>/?route=admin/permission-overrides" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                        <input type="text" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               id="search" 
                               name="search" 
                               value="<?= htmlspecialchars($search) ?>"
                               placeholder="Employee ID, Name..."
                               autocomplete="off">
                    </div>
                    <div>
                        <label for="department" class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" id="department" name="department">
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
                        <label for="section" class="block text-sm font-medium text-gray-700 mb-2">Section</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" id="section" name="section">
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
                        <label for="role" class="block text-sm font-medium text-gray-700 mb-2">Role</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" id="role" name="role">
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
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition duration-200">
                            <i class="fas fa-filter"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Employees Table -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h5 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-users mr-2"></i>Employees (<?= number_format($totalRecords) ?>)
                </h5>
            </div>
            <div class="p-6">
                <?php if (empty($employees)): ?>
                    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg">
                        <i class="fas fa-info-circle mr-2"></i>No employees found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Employee ID</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Overrides</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($employees as $emp): ?>
                                    <tr class="hover:bg-gray-50 transition duration-150">
                                        <td class="px-4 py-4">
                                            <strong class="text-gray-900"><?= htmlspecialchars($emp['full_name']) ?></strong><br>
                                            <small class="text-gray-500"><?= htmlspecialchars($emp['email']) ?></small>
                                        </td>
                                        <td class="px-4 py-4 text-sm text-gray-700"><?= htmlspecialchars($emp['employee_id']) ?></td>
                                        <td class="px-4 py-4 text-sm text-gray-700"><?= htmlspecialchars($emp['department'] ?? 'N/A') ?></td>
                                        <td class="px-4 py-4 text-sm text-gray-700"><?= htmlspecialchars($emp['section'] ?? 'N/A') ?></td>
                                        <td class="px-4 py-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $emp['role'] === 'super_admin' ? 'bg-red-100 text-red-800' : ($emp['role'] === 'hr_manager' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') ?>">
                                                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $emp['role']))) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $emp['employee_status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                                <?= ucfirst($emp['employee_status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <?php if ($emp['override_count'] > 0): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                    <?= $emp['override_count'] ?> override(s)
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-sm">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-4">
                                            <a href="<?= BASE_URL ?>/?route=admin/permission-overrides/manage/<?= $emp['user_id'] ?>" 
                                               class="inline-flex items-center px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition duration-200"
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
                                               class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700 transition duration-200">
                                                Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                        <li>
                                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>&section=<?= urlencode($section) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>" 
                                               class="px-3 py-2 rounded-lg transition duration-200 <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50 text-gray-700' ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <li>
                                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&department=<?= urlencode($department) ?>&section=<?= urlencode($section) ?>&role=<?= urlencode($role) ?>&status=<?= urlencode($status) ?>" 
                                               class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700 transition duration-200">
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
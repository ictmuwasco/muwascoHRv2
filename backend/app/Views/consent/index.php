<?php
/**
 * Consent Management View
 *
 * Clean interface for managing employee consents with filtering and export.
 * Place: backend/app/Views/consent/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/Helpers/Auth.php';
}

// Helper functions
if (!function_exists('maskNationalId')) {
    function maskNationalId(?string $id): string {
        if (empty($id)) return 'N/A';
        $len = strlen($id);
        if ($len <= 4) return str_repeat('*', $len);
        return substr($id, 0, 2) . str_repeat('*', $len - 4) . substr($id, -2);
    }
}

if (!function_exists('formatDate')) {
    function formatDate(?string $d): string {
        return $d ? date('M d, Y', strtotime($d)) : 'N/A';
    }
}

$pageTitle = 'Employee Consent Management - HR Management System';
include __DIR__ . '/../components/header_bar.php';
?>

<!DOCTYPE html>
<html lang="en" data-theme="<?= $_SESSION['theme'] ?? 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="<?= BASE_URL ?>/frontend/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-900">

<div class="flex pt-16">
    <!-- Sidebar -->
    <?php include __DIR__ . '/../components/navbar.php'; ?>
    
    <!-- Main Content -->
    <div class="flex-1 ml-64 p-8">
        <div class="content">

            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'info' ?>">
                    <?= htmlspecialchars($_SESSION['flash_message']) ?>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['flash_error']) ?></div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>

            <?php $activeTab = 'consents'; include __DIR__ . '/../components/admin_tabs.php'; ?>

            <div class="tab-content">
                <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">Employee Consent Management</h3>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <div class="stat-card bg-white dark:bg-dark-secondary/50 border border-gray-200 dark:border-white/20 rounded-xl shadow-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="stat-icon bg-primary-400/20 text-primary-400 w-12 h-12 rounded-lg flex items-center justify-center flex-shrink-0"><i class="fas fa-users text-xl"></i></div>
                            <div class="stat-info"><h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total_employees']) ?></h3><p class="text-xs text-gray-600 dark:text-gray-400">Total Employees</p></div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-dark-secondary/50 border border-gray-200 dark:border-white/20 rounded-xl shadow-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="stat-icon bg-green-500/20 text-green-500 w-12 h-12 rounded-lg flex items-center justify-center flex-shrink-0"><i class="fas fa-check-circle text-xl"></i></div>
                            <div class="stat-info"><h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['consented_employees']) ?></h3><p class="text-xs text-gray-600 dark:text-gray-400">Consented</p></div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-dark-secondary/50 border border-gray-200 dark:border-white/20 rounded-xl shadow-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="stat-icon bg-yellow-500/20 text-yellow-500 w-12 h-12 rounded-lg flex items-center justify-center flex-shrink-0"><i class="fas fa-exclamation-circle text-xl"></i></div>
                            <div class="stat-info"><h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['pending_consents']) ?></h3><p class="text-xs text-gray-600 dark:text-gray-400">Pending</p></div>
                        </div>
                    </div>
                    <div class="stat-card bg-white dark:bg-dark-secondary/50 border border-gray-200 dark:border-white/20 rounded-xl shadow-lg p-4">
                        <div class="flex items-center gap-3">
                            <div class="stat-icon bg-blue-500/20 text-blue-500 w-12 h-12 rounded-lg flex items-center justify-center flex-shrink-0"><i class="fas fa-chart-line text-xl"></i></div>
                            <div class="stat-info"><h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= $stats['completion_rate'] ?>%</h3><p class="text-xs text-gray-600 dark:text-gray-400">Completion Rate</p></div>
                        </div>
                    </div>
                </div>

                <!-- Filters + Export -->
                <div class="bg-white dark:bg-dark-secondary/50 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg p-4 mb-6">
                    <form method="GET" action="." class="filter-form">
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-3">
                            <div class="form-group">
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Search</label>
                                <input type="text" name="search" class="w-full px-3 py-1.5 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm focus:outline-none focus:border-primary-400 focus:ring-1 focus:ring-primary-400" placeholder="Name, email, ID..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Department</label>
                                <select name="department" class="w-full px-3 py-1.5 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm">
                                    <option value="">All</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= (($_GET['department'] ?? '') == $d['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">Status</label>
                                <select name="consent_status" class="w-full px-3 py-1.5 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm">
                                    <option value="">All</option>
                                    <option value="consented" <?= (($_GET['consent_status'] ?? '')==='consented') ? 'selected' : '' ?>>Consented</option>
                                    <option value="not_consented" <?= (($_GET['consent_status'] ?? '')==='not_consented') ? 'selected' : '' ?>>Pending</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">From</label>
                                <input type="date" name="date_from" class="w-full px-3 py-1.5 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">To</label>
                                <input type="date" name="date_to" class="w-full px-3 py-1.5 bg-gray-50 dark:bg-white/5 border border-gray-300 dark:border-white/10 rounded-lg text-gray-900 dark:text-white text-sm" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search mr-1"></i>Apply
                            </button>
                            <a href="." class="btn btn-secondary btn-sm">
                                <i class="fas fa-times mr-1"></i>Clear
                            </a>
                            <div class="ml-auto flex gap-2">
                                <form method="POST" id="exportForm" class="flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="export_type" id="export_type_input">
                                    <input type="hidden" name="filters" id="export_filters" value="">
                                    <button type="button" onclick="doExport('pdf')" class="btn btn-danger btn-sm"><i class="fas fa-file-pdf"></i> PDF</button>
                                    <button type="button" onclick="doExport('excel')" class="btn btn-success btn-sm"><i class="fas fa-file-excel"></i> Excel</button>
                                </form>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-dark-secondary/50 backdrop-blur-xl border border-gray-200 dark:border-white/20 rounded-2xl shadow-lg overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Employee ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Full Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">National ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Department</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Position</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Consent Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Consent Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP Address</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-dark-secondary/30 divide-y divide-gray-200 dark:divide-white/10">
                                <?php if (empty($consents)): ?>
                                    <tr><td colspan="9" class="px-6 py-4 text-center text-sm text-gray-500 dark:text-gray-400">No records found</td></tr>
                                <?php else: foreach ($consents as $c): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition duration-150">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($c['employee_id']) ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <strong class="text-gray-900 dark:text-white"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></strong>
                                            <br><small class="text-gray-500 dark:text-gray-400"><?= htmlspecialchars($c['email']) ?></small>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                            <?php if ($c['consent_national_id']): ?>
                                                <?= htmlspecialchars(maskNationalId($c['consent_national_id'])) ?>
                                                <br><small class="text-gray-500 dark:text-gray-400">(Partially masked)</small>
                                            <?php else: ?>
                                                <span class="text-gray-500 dark:text-gray-400">Not provided</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($c['department_name'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($c['designation'] ?? 'N/A') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php if ($c['consent_given']): ?>
                                                <span class="badge badge-success"><i class="fas fa-check"></i> Consented</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?= $c['consent_date'] ? formatDate($c['consent_date']) : 'N/A' ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white"><?= $c['ip_address'] ? '<code class="bg-gray-100 dark:bg-white/10 px-2 py-1 rounded">'.htmlspecialchars($c['ip_address']).'</code>' : '<span class="text-gray-500 dark:text-gray-400">N/A</span>' ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                           class="page-link <?= $i === $current_page ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<script>
function doExport(type) {
    document.getElementById('export_type_input').value = type;
    document.getElementById('exportForm').submit();
}

function viewConsentDetails(id) {
    // TODO: replace with a detail modal
    alert('Consent ID: ' + id);
}

document.addEventListener('DOMContentLoaded', () => {
    ['search','department','consent_status','date_from','date_to'].forEach(n => {
        const el = document.querySelector('[name="'+n+'"]');
        if (el) el.addEventListener('change', () => el.closest('form').submit());
    });
});
</script>
</body>
</html>


<?php
/**
 * Audit Dashboard View
 *
 * Modern, clean interface for viewing audit logs with advanced filtering.
 * Place: backend/app/Views/audit/index.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Audit Trail Dashboard - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
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
<body class="min-h-screen bg-gray-50 text-gray-900 dark:bg-dark-bg dark:text-white">

    <div class="lg:pl-64 pt-16 min-h-screen relative z-10">
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full">

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

            <?php $activeTab = 'audit'; include __DIR__ . '/../components/admin_tabs.php'; ?>

            <div class="tab-content">
                <h3>Audit Trail Dashboard</h3>

                <!-- Statistics Cards - Horizontal Layout -->
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    <div class="stat-card glass-card">
                        <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
                        <div class="stat-info">
                            <h3><?= number_format($total_logs) ?></h3>
                            <p>Total Events</p>
                        </div>
                    </div>
                    <?php
                    $create_count = $stats['CREATE'] ?? 0;
                    $update_count = $stats['UPDATE'] ?? 0;
                    $delete_count = $stats['DELETE'] ?? 0;
                    $approval_count = $stats['APPROVAL'] ?? 0;
                    $login_count = $stats['LOGIN_SUCCESS'] ?? 0;
                    ?>
                    <div class="stat-card glass-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-plus-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($create_count) ?></h3><p>Created</p></div>
                    </div>
                    <div class="stat-card glass-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-edit"></i></div>
                        <div class="stat-info"><h3><?= number_format($update_count) ?></h3><p>Updated</p></div>
                    </div>
                    <div class="stat-card glass-card">
                        <div class="stat-icon" style="background:var(--danger-color)"><i class="fas fa-trash-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($delete_count) ?></h3><p>Deleted</p></div>
                    </div>
                    <div class="stat-card glass-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($approval_count) ?></h3><p>Approvals</p></div>
                    </div>
                    <div class="stat-card glass-card">
                        <div class="stat-icon" style="background:#6c757d"><i class="fas fa-sign-in-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($login_count) ?></h3><p>Logins</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-6">
                    <div class="flex items-center justify-between mb-5 pb-3 border-b border-gray-200 dark:border-cyan-500/30">
                        <h5 class="mb-0 text-cyan-400 dark:text-cyan-400 font-semibold">
                            <i class="fas fa-shield-alt mr-2"></i>Search & Filter
                        </h5>
                        <button type="button" class="text-sm px-4 py-2 rounded-lg border border-cyan-500/30 bg-cyan-500/10 text-cyan-400 hover:bg-cyan-500/20 transition-all" onclick="toggleAdvancedFilters()">
                            <i class="fas fa-sliders-h mr-1"></i> Advanced
                        </button>
                    </div>
                    
                    <form method="GET" action="/audit-dashboard" class="filter-form">
                        <!-- Primary Search Row -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                            <div class="form-group mb-0">
                                <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">Search</label>
                                <div class="relative">
                                    <input type="text" name="search" class="form-control pl-10" placeholder="Search logs..." 
                                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                           class="form-control pl-10 bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg">
                                    <i class="fas fa-search absolute left-3 top-3 text-cyan-500"></i>
                                </div>
                            </div>
                            <div class="form-group mb-0">
                                <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">User</label>
                                <select name="user_id" class="form-control bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg">
                                    <option value="">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= $user['id'] ?? '' ?>" <?= ($_GET['user_id'] ?? '') == ($user['id'] ?? '') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($user['username'] ?? 'User #' . ($user['id'] ?? '0')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">Action Type</label>
                                <select name="action_type" class="form-control bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg">
                                    <option value="">All Actions</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?= $action['action_type'] ?>" <?= ($_GET['action_type'] ?? '') == $action['action_type'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($action['action_type']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">Module</label>
                                <select name="table_name" class="form-control bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg">
                                    <option value="">All Modules</option>
                                    <?php foreach ($tables as $table): ?>
                                        <option value="<?= $table['table_name'] ?>" <?= ($_GET['table_name'] ?? '') == $table['table_name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($table['table_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Advanced Filters (Collapsible) -->
                        <div id="advancedFilters" class="hidden border-t border-gray-200 dark:border-cyan-500/30 pt-4 mt-4">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                                <div class="form-group mb-0">
                                    <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">Date From</label>
                                    <input type="date" name="date_from" class="form-control bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                                </div>
                                <div class="form-group mb-0">
                                    <label class="block text-xs font-semibold uppercase tracking-wider mb-2 text-gray-600 dark:text-gray-400">Date To</label>
                                    <input type="date" name="date_to" class="form-control bg-white dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-900 dark:text-white rounded-lg" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex flex-wrap gap-3 mt-4">
                            <button type="submit" class="btn btn-primary bg-gradient-to-r from-cyan-400 to-cyan-600 hover:from-cyan-500 hover:to-cyan-700 text-white border-none shadow-lg shadow-cyan-500/30">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <?php if (!empty($_GET['search']) || !empty($_GET['user_id']) || !empty($_GET['action_type']) || !empty($_GET['table_name']) || !empty($_GET['date_from']) || !empty($_GET['date_to'])): ?>
                                <a href="/audit-dashboard" class="btn btn-secondary bg-gray-100 dark:bg-dark-secondary border border-gray-300 dark:border-cyan-500/30 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-white/5">
                                    <i class="fas fa-times"></i> Clear All
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Audit Logs Table -->
                <div class="table-container">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Audit Logs</h5>
                        <div>
                            <?php if (!empty($logs)): ?>
                                <form method="POST" action="/audit-dashboard/export" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="filters" value="<?= htmlspecialchars(json_encode($_GET)) ?>">
                                    <input type="hidden" name="export_type" value="excel">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-download"></i> Export
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($logs)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                        <th>Module</th>
                                        <th>Record</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr class="log-entry">
                                            <td>
                                                <div class="timestamp-cell">
                                                    <strong><?= date('Y-m-d', strtotime($log['timestamp'])) ?></strong>
                                                    <small class="text-muted"><?= date('H:i:s', strtotime($log['timestamp'])) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="user-cell">
                                                    <strong><?= htmlspecialchars($log['display_name'] ?? $log['username'] ?? 'System') ?></strong>
                                                    <br><small class="text-muted"><?= htmlspecialchars($log['display_role'] ?? 'Unknown') ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = 'badge-secondary';
                                                $icon = 'fa-circle';
                                                switch ($log['action_type']) {
                                                    case 'CREATE': $badge_class = 'badge-success'; $icon = 'fa-plus'; break;
                                                    case 'UPDATE': $badge_class = 'badge-warning'; $icon = 'fa-edit'; break;
                                                    case 'DELETE': $badge_class = 'badge-danger'; $icon = 'fa-trash'; break;
                                                    case 'APPROVAL': $badge_class = 'badge-info'; $icon = 'fa-check'; break;
                                                    case 'LOGIN_SUCCESS': $badge_class = 'badge-primary'; $icon = 'fa-sign-in-alt'; break;
                                                    case 'LOGIN_FAILED': $badge_class = 'badge-danger'; $icon = 'fa-exclamation-triangle'; break;
                                                    case 'LOGOUT': $badge_class = 'badge-secondary'; $icon = 'fa-sign-out-alt'; break;
                                                    case 'EXPORT': $badge_class = 'badge-purple'; $icon = 'fa-download'; break;
                                                }
                                                ?>
                                                <span class="badge <?= $badge_class ?>">
                                                    <i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($log['action_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="description-cell">
                                                    <?= htmlspecialchars($log['description']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($log['table_name']): ?>
                                                    <span class="badge badge-light"><?= htmlspecialchars($log['table_name']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['record_id']): ?>
                                                    <code>#<?= $log['record_id'] ?></code>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['old_values'] || $log['new_values']): ?>
                                                    <button class="btn btn-sm btn-outline-primary" data-toggle="modal" data-target="#detailsModal"
                                                            onclick="showDetails(<?= htmlspecialchars(json_encode([
                                                                'old' => $log['old_values'] ? json_decode($log['old_values'], true) : null,
                                                                'new' => $log['new_values'] ? json_decode($log['new_values'], true) : null,
                                                                'action' => $log['action_type'],
                                                                'table' => $log['table_name'],
                                                                'record_id' => $log['record_id'],
                                                                'description' => $log['description']
                                                            ]), JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav class="pagination">
                                <ul class="d-flex justify-content-center" style="list-style:none; gap:4px; padding:0; flex-wrap:wrap;">
                                    <?php if ($current_page > 1): ?>
                                        <li>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" class="page-link">
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php
                                    $start = max(1, $current_page - 2);
                                    $end = min($total_pages, $current_page + 2);
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <li class="<?= $i == $current_page ? 'active' : '' ?>">
                                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i == $current_page ? 'active' : '' ?>">
                                                <?= $i ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>

                                    <?php if ($current_page < $total_pages): ?>
                                        <li>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" class="page-link">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No audit logs found with the selected filters.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if (!empty($logs)): ?>
<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background: linear-gradient(135deg, rgba(26,26,46,0.98) 0%, rgba(22,33,62,0.98) 100%); border: 1px solid rgba(0,212,255,0.3); box-shadow: 0 20px 60px rgba(0,0,0,0.5);">
            <div class="modal-header" style="border-bottom: 1px solid rgba(0,212,255,0.3); background: rgba(0,212,255,0.05);">
                <h5 class="modal-title" style="color: #00d4ff; font-weight: 600;">
                    <i class="fas fa-shield-alt mr-2"></i> Audit Log Details
                    <small class="text-muted" id="modalSubtitle" style="color: #9ca3af;"></small>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: #9ca3af; opacity: 1;">
                    <span aria-hidden="true" style="font-size: 1.5rem;">&times;</span>
                </button>
            </div>
            <div class="modal-body" style="color: #fff;">
                <div class="alert mb-3" style="background: rgba(0,212,255,0.1); border: 1px solid rgba(0,212,255,0.3); border-radius: 10px; color: #fff;">
                    <strong style="color: #00d4ff;">Action:</strong> <span id="modalAction"></span><br>
                    <strong style="color: #00d4ff;">Module:</strong> <span id="modalTable"></span><br>
                    <strong style="color: #00d4ff;">Record ID:</strong> <span id="modalRecordId"></span><br>
                    <strong style="color: #00d4ff;">Description:</strong> <span id="modalDescription"></span>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6 style="color: #00d4ff; font-weight: 600; margin-bottom: 0.75rem;">
                            <i class="fas fa-history mr-2"></i>Previous Values
                        </h6>
                        <pre id="oldValues" class="pre-details" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(0,212,255,0.2); border-radius: 10px; padding: 1rem; color: #9ca3af; font-size: 0.875rem; max-height: 300px; overflow-y: auto;">No previous values recorded</pre>
                    </div>
                    <div class="col-md-6">
                        <h6 style="color: #00d4ff; font-weight: 600; margin-bottom: 0.75rem;">
                            <i class="fas fa-sync-alt mr-2"></i>New Values
                        </h6>
                        <pre id="newValues" class="pre-details" style="background: rgba(0,0,0,0.3); border: 1px solid rgba(0,212,255,0.2); border-radius: 10px; padding: 1rem; color: #9ca3af; font-size: 0.875rem; max-height: 300px; overflow-y: auto;">No new values recorded</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid rgba(0,212,255,0.3);">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" style="background: rgba(0,212,255,0.1); border: 1px solid rgba(0,212,255,0.3); color: #00d4ff; border-radius: 8px;">Close</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showDetails(data) {
    if (data.table) {
        document.getElementById('modalSubtitle').textContent = ' - ' + data.table;
    }
    document.getElementById('modalAction').textContent = data.action;
    document.getElementById('modalTable').textContent = data.table || 'N/A';
    document.getElementById('modalRecordId').textContent = data.record_id || 'N/A';
    document.getElementById('modalDescription').textContent = data.description || 'N/A';

    document.getElementById('oldValues').textContent = data.old ? JSON.stringify(data.old, null, 2) : 'No previous values recorded';
    document.getElementById('newValues').textContent = data.new ? JSON.stringify(data.new, null, 2) : 'No new values recorded';

    $('#detailsModal').modal('show');
}

function toggleAdvancedFilters() {
    const advancedFilters = document.getElementById('advancedFilters');
    const button = event.target.closest('button');
    
    if (advancedFilters.classList.contains('hidden')) {
        advancedFilters.classList.remove('hidden');
        button.innerHTML = '<i class="fas fa-chevron-up mr-1"></i> Hide Advanced';
    } else {
        advancedFilters.classList.add('hidden');
        button.innerHTML = '<i class="fas fa-sliders-h mr-1"></i> Advanced';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss flash messages
    document.querySelectorAll('.alert').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity .5s';
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 500);
        }, 5000);
    });

    // Date range validation
    const startDate = document.querySelector('input[name="date_from"]');
    const endDate = document.querySelector('input[name="date_to"]');

    if (startDate && endDate) {
        startDate.addEventListener('change', function () { endDate.min = this.value; });
        endDate.addEventListener('change', function () { startDate.max = this.value; });
    }
});
</script>
</body>
</html>


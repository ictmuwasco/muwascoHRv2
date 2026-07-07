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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/frontend/assets/css/output.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen"
      style="background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);">

<div class="container">
    <div class="main-content">
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

            <?php $activeTab = 'audit'; include __DIR__ . '/../components/admin_tabs.php'; ?>

            <div class="tab-content">
                <h3>Audit Trail Dashboard</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
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
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-plus-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($create_count) ?></h3><p>Created</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-edit"></i></div>
                        <div class="stat-info"><h3><?= number_format($update_count) ?></h3><p>Updated</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--danger-color)"><i class="fas fa-trash-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($delete_count) ?></h3><p>Deleted</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($approval_count) ?></h3><p>Approvals</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#6c757d"><i class="fas fa-sign-in-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($login_count) ?></h3><p>Logins</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/audit-dashboard" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search description, user, table..."
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>User</label>
                                <select name="user_id" class="form-control">
                                    <option value="">All Users</option>
                                    <?php foreach ($users as $user): ?>
                                        <option value="<?= $user['user_id'] ?>" <?= ($_GET['user_id'] ?? '') == $user['user_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($user['username'] ?? 'Unknown') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Action Type</label>
                                <select name="action_type" class="form-control">
                                    <option value="">All Actions</option>
                                    <?php foreach ($actions as $action): ?>
                                        <option value="<?= $action['action_type'] ?>" <?= ($_GET['action_type'] ?? '') == $action['action_type'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($action['action_type']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Table/Module</label>
                                <select name="table_name" class="form-control">
                                    <option value="">All Tables</option>
                                    <?php foreach ($tables as $table): ?>
                                        <option value="<?= $table['table_name'] ?>" <?= ($_GET['table_name'] ?? '') == $table['table_name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($table['table_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <?php if (!empty($_GET['search']) || !empty($_GET['user_id']) || !empty($_GET['action_type']) || !empty($_GET['table_name']) || !empty($_GET['date_from']) || !empty($_GET['date_to'])): ?>
                                    <a href="/audit-dashboard" class="btn btn-secondary ml-2">
                                        <i class="fas fa-times"></i> Clear
                                    </a>
                                <?php endif; ?>
                            </div>
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

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle"></i> Audit Log Details
                    <small class="text-muted" id="modalSubtitle"></small>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light mb-3">
                    <strong>Action:</strong> <span id="modalAction"></span><br>
                    <strong>Module:</strong> <span id="modalTable"></span><br>
                    <strong>Record ID:</strong> <span id="modalRecordId"></span><br>
                    <strong>Description:</strong> <span id="modalDescription"></span>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-history"></i> Previous Values</h6>
                        <pre id="oldValues" class="pre-details">No previous values recorded</pre>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-sync-alt"></i> New Values</h6>
                        <pre id="newValues" class="pre-details">No new values recorded</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

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

document.addEventListener('DOMContentLoaded', function () {
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

</parameter>
<task_progress>
- [x] Examine current audit_dashboard.php
- [x] Examine current consent_management.php
- [x] Review NotificationService for audit logging
- [x] Search for existing audit service/logging
- [x] Review AuditService implementation
- [x] Review AuditLogger implementation
- [x] Create AuditController
- [x] Create Audit view with modern design
- [ ] Create ConsentController
- [ ] Create Consent view with improved UX
- [ ] Set up routes
- [ ] Verify audit coverage across all controllers
- [ ] Standardize audit logging
- [ ] Delete legacy files
</task_progress>
</write_to_file>
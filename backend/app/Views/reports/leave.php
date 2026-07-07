<?php
/**
 * Leave Reports View
 *
 * Clean, data-driven leave reporting interface.
 * Place: backend/app/Views/reports/leave.php
 */

// Ensure RBAC functions are loaded
if (!function_exists('hasPermission')) {
    require_once dirname(__DIR__, 2) . '/auth.php';
}

$pageTitle = 'Leave Reports - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';
?>

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

            <div class="tab-content">
                <h3>Leave Reports</h3>

                <!-- Statistics Cards -->
                <div class="stats-grid mb-4">
                    <div class="stat-card">
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_requests']) ?></h3><p>Total Requests</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--success-color)"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['approved']) ?></h3><p>Approved</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--warning-color)"><i class="fas fa-clock"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['pending']) ?></h3><p>Pending</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--danger-color)"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['rejected']) ?></h3><p>Rejected</p></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:var(--info-color)"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-info"><h3><?= number_format($stats['total_days_approved']) ?></h3><p>Days Approved</p></div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="glass-card mb-4">
                    <h5 class="mb-3"><i class="fas fa-filter"></i> Filters</h5>
                    <form method="GET" action="/reports/leave" class="filter-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Date From</label>
                                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Date To</label>
                                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Employee Search</label>
                                <input type="text" name="employee" class="form-control" placeholder="Employee ID or Name"
                                       value="<?= htmlspecialchars($filters['employee'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="form-control">
                                    <option value="">All Departments</option>
                                    <?php foreach ($filter_options['departments'] as $dept): ?>
                                        <option value="<?= $dept['id'] ?>" <?= ($filters['department'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Leave Type</label>
                                <select name="leave_type" class="form-control">
                                    <option value="">All Types</option>
                                    <?php foreach ($filter_options['leave_types'] as $type): ?>
                                        <option value="<?= $type['id'] ?>" <?= ($filters['leave_type'] ?? '') == $type['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($type['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="pending" <?= ($filters['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="approved" <?= ($filters['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
                                    <option value="rejected" <?= ($filters['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                    <option value="cancelled" <?= ($filters['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Leave Year</label>
                                <select name="leave_year" class="form-control">
                                    <option value="">All Years</option>
                                    <?php foreach ($filter_options['years'] as $year): ?>
                                        <option value="<?= $year['year'] ?>" <?= ($filters['leave_year'] ?? '') == $year['year'] ? 'selected' : '' ?>>
                                            <?= $year['year'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Apply Filters
                                </button>
                                <a href="/reports/leave" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Export Buttons -->
                <div class="glass-card mb-4">
                    <div class="card-body">
                        <div class="export-actions">
                            <form method="POST" action="/reports/leave/export" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="filters" value="<?= htmlspecialchars(json_encode($filters)) ?>">
                                <input type="hidden" name="export_type" value="excel">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </button>
                            </form>
                            <button onclick="window.print()" class="btn btn-secondary">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Leave Requests Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h5><i class="fas fa-list"></i> Leave Requests</h5>
                        <span class="records-count"><?= number_format(count($leaves)) ?> records found</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Employee ID</th>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th>Leave Type</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Days</th>
                                    <th>Status</th>
                                    <th>Applied Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($leaves)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted">
                                            <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                            No leave requests found matching the selected filters
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($leaves as $leave): ?>
                                        <tr>
                                            <td><strong>#<?= htmlspecialchars($leave['id']) ?></strong></td>
                                            <td><?= htmlspecialchars($leave['emp_number']) ?></td>
                                            <td><?= htmlspecialchars($leave['employee_name']) ?></td>
                                            <td><?= htmlspecialchars($leave['department_name'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($leave['leave_type_name'] ?? 'N/A') ?></td>
                                            <td><?= date('M d, Y', strtotime($leave['start_date'])) ?></td>
                                            <td><?= date('M d, Y', strtotime($leave['end_date'])) ?></td>
                                            <td><?= number_format($leave['days_requested']) ?></td>
                                            <td>
                                                <span class="badge badge-<?= 
                                                    $leave['status'] === 'approved' ? 'success' : 
                                                    ($leave['status'] === 'pending' ? 'warning' : 
                                                    ($leave['status'] === 'rejected' ? 'danger' : 'secondary')) 
                                                ?>">
                                                    <?= ucwords($leave['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($leave['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

